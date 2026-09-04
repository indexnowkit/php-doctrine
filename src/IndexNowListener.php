<?php

declare(strict_types=1);

namespace IndexNowKit\Doctrine;

use Doctrine\ORM\EntityManagerInterface;
use Doctrine\ORM\Event\OnFlushEventArgs;
use Doctrine\ORM\Event\PostFlushEventArgs;
use Doctrine\ORM\Events;
use Doctrine\ORM\PersistentCollection;
use IndexNowKit\Attribute\RuleEvent;
use IndexNowKit\Event;
use IndexNowKit\IndexNowKit;
use IndexNowKit\Transaction\TransactionStaging;
use IndexNowKit\Url\GuardedUrlResolver;
use IndexNowKit\Url\ObjectChangeHandler;
use IndexNowKit\Url\ResolvedUrl;
use IndexNowKit\Url\UrlResolverInterface;
use Psr\Log\LoggerInterface;
use Psr\Log\NullLogger;

/**
 * Classifies changed entities per URL rule in onFlush, resolves URLs in postFlush (ids assigned) — deletions
 * and pages that stopped applying are resolved in onFlush while the old state is live — and hands the URLs
 * over only once the outermost transaction committed.
 */
final class IndexNowListener
{
    public const EVENTS = [Events::onFlush, Events::postFlush];

    /** @var list<array{0: object, 1: RuleEvent}> resolved in postFlush */
    private array $pending = [];

    /** @var list<ResolvedUrl> already resolved (deletions) */
    private array $resolved = [];

    private readonly ObjectChangeHandler $changes;

    /**
     * @param UrlResolverInterface|null $resolver  defaults to the facade's resolver
     * @param bool                      $autoFlush call IndexNowKit::flush() right after hand-off (standalone usage); adapters flush at request end
     */
    public function __construct(
        private readonly IndexNowKit $indexNow,
        ?UrlResolverInterface $resolver,
        private readonly TransactionStaging $staging,
        private readonly LoggerInterface $logger = new NullLogger(),
        private readonly bool $autoFlush = false,
    ) {
        $this->changes = $resolver === null
            ? $indexNow->changes()
            : new ObjectChangeHandler($indexNow->attributes, $resolver instanceof GuardedUrlResolver ? $resolver : new GuardedUrlResolver($resolver, $indexNow->attributes, $logger), $logger);
    }

    public function onFlush(OnFlushEventArgs $args): void
    {
        $uow = self::entityManager($args)->getUnitOfWork();
        $this->pending = [];
        $this->resolved = [];

        foreach ($uow->getScheduledEntityInsertions() as $entity) {
            $this->defer($entity, $this->changes->createdEvents($entity));
        }

        foreach ($uow->getScheduledEntityUpdates() as $entity) {
            /** @var array<string, array{0: mixed, 1: mixed}> $changeSet */
            $changeSet = $uow->getEntityChangeSet($entity);
            $this->resolved = [...$this->resolved, ...$this->changes->renamed($entity, $changeSet)]; // old URLs of a renamed page, resolved before the write
            foreach ($this->changes->distinct($this->changes->updatedEvents($entity, array_keys($changeSet), $changeSet)) as $ruleEvent) {
                if ($ruleEvent->event === Event::Deleted) {
                    $this->resolveNow($entity, $ruleEvent);
                } else {
                    $this->pending[] = [$entity, $ruleEvent];
                }
            }
        }

        // A changed to-many association (post <-> tags) is not part of the owner's change set.
        foreach ([...$uow->getScheduledCollectionUpdates(), ...$uow->getScheduledCollectionDeletions()] as $collection) {
            if (!$collection instanceof PersistentCollection) {
                continue;
            }
            $owner = $collection->getOwner();
            if ($owner === null) {
                continue;
            }
            $this->defer($owner, $this->changes->updatedEvents($owner, [self::fieldName($collection)]));
        }

        foreach ($uow->getScheduledEntityDeletions() as $entity) {
            foreach ($this->changes->distinct($this->changes->deletedEvents($entity)) as $ruleEvent) {
                $this->resolveNow($entity, $ruleEvent);
            }
        }
    }

    public function postFlush(PostFlushEventArgs $args): void
    {
        $resolved = $this->resolved;
        foreach ($this->pending as [$entity, $ruleEvent]) {
            $resolved = [...$resolved, ...$this->changes->resolve($entity, $ruleEvent)];
        }
        $this->pending = [];
        $this->resolved = [];

        if ($resolved === []) {
            return;
        }
        foreach ($resolved as $item) {
            $this->logger->debug('indexnow: {source} ({event}) -> {url}', ['source' => $item->source(), 'event' => $item->event->value, 'url' => $item->url]);
        }
        $this->handOff(self::entityManager($args), ResolvedUrl::urls($resolved));
    }

    /** ORM 2.19 types the manager of the flush events as ObjectManager; 2.20 and 3 as EntityManagerInterface. */
    private static function entityManager(OnFlushEventArgs|PostFlushEventArgs $args): EntityManagerInterface
    {
        $em = $args->getObjectManager();
        \assert($em instanceof EntityManagerInterface);

        return $em;
    }

    /**
     * @param list<string> $urls
     */
    private function handOff(EntityManagerInterface $em, array $urls): void
    {
        $connection = $em->getConnection();
        if ($connection->getTransactionNestingLevel() > 0) {
            $native = $connection->getNativeConnection();
            if (\is_object($native)) {
                $this->staging->stage($native, $urls);

                return;
            }
            $this->logger->warning('indexnow: driver has no native connection object; submitting inside an open transaction');
        }
        $this->deliver($urls);
    }

    /**
     * @param list<string> $urls
     */
    public function deliver(array $urls): void
    {
        $this->indexNow->collect($urls);
        if ($this->autoFlush) {
            $this->indexNow->flush();
        }
    }

    /**
     * @param list<RuleEvent> $ruleEvents
     */
    private function defer(object $entity, array $ruleEvents): void
    {
        foreach ($this->changes->distinct($ruleEvents) as $ruleEvent) {
            $this->pending[] = [$entity, $ruleEvent];
        }
    }

    private function resolveNow(object $entity, RuleEvent $ruleEvent): void
    {
        $this->resolved = [...$this->resolved, ...$this->changes->resolve($entity, $ruleEvent)];
    }

    /**
     * @param PersistentCollection<int|string, object> $collection
     */
    private static function fieldName(PersistentCollection $collection): string
    {
        // ORM 2: array{fieldName: string, ...}, ORM 3: AssociationMapping object with a public $fieldName; the cast reads both.
        return ((array) $collection->getMapping())['fieldName'];
    }
}
