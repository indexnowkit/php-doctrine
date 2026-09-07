<?php

declare(strict_types=1);

namespace IndexNowKit\Doctrine;

use Closure;
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
use LogicException;
use Psr\Log\LoggerInterface;
use Psr\Log\NullLogger;

/**
 * Classifies changed entities per URL rule in onFlush, resolves URLs in postFlush (ids assigned) — deletions
 * and pages that stopped applying are resolved in onFlush while the old state is live — and hands the URLs
 * over only once the outermost transaction committed.
 *
 * The change handler is built on the first flush that has something to classify, not in the constructor: an adapter
 * with a lazy graph passes a closure and a sink instead of the facade, and a request that writes nothing then builds
 * no submitter, client or transport (see the constructor).
 */
final class IndexNowListener
{
    public const EVENTS = [Events::onFlush, Events::postFlush];

    /** @var list<array{0: object, 1: RuleEvent}> resolved in postFlush */
    private array $pending = [];

    /** @var list<ResolvedUrl> already resolved (deletions) */
    private array $resolved = [];

    /** @var Closure(): ObjectChangeHandler */
    private readonly Closure $changeHandler;
    private ?ObjectChangeHandler $changes = null;
    /** @var Closure(list<string>): void */
    private readonly Closure $sink;
    private bool $inFlush = false;

    /**
     * Over the facade, or over a closure that builds the change handler on the first flush that has something to
     * classify. An adapter whose graph is lazy (`Adapter\Services`) passes `fn() => $services->changes()` with a
     * $sink of `fn(array $urls) => $services->kit()->collect($urls)`, so registering the listener — and a request
     * that writes nothing — never builds the submitter, the client or the transport.
     *
     * @param IndexNowKit|Closure(): ObjectChangeHandler $source    the facade (its change handler and `collect()`), or the
     *                                                              deferred change handler; with a closure $sink is required
     * @param UrlResolverInterface|null                  $resolver  defaults to the resolver of $source; ignored with a closure $source
     * @param bool                                       $autoFlush call IndexNowKit::flush() right after hand-off (standalone
     *                                                              usage); adapters flush at request end. Only for a facade $source:
     *                                                              with a closure the $sink decides what hand-off means
     * @param (Closure(list<string>): void)|null         $sink      where the resolved URLs go; null = `collect()` of the facade
     */
    public function __construct(
        IndexNowKit|Closure $source,
        ?UrlResolverInterface $resolver,
        private readonly TransactionStaging $staging,
        private readonly LoggerInterface $logger = new NullLogger(),
        bool $autoFlush = false,
        ?Closure $sink = null,
    ) {
        if (!$source instanceof IndexNowKit) {
            $this->changeHandler = $source;
            $this->sink = $sink ?? throw new LogicException('IndexNowListener over a change-handler closure needs the $sink the URLs go to.');

            return;
        }
        $this->changeHandler = $resolver === null
            ? static fn(): ObjectChangeHandler => $source->changes()
            : static fn(): ObjectChangeHandler => new ObjectChangeHandler($source->attributes, $resolver instanceof GuardedUrlResolver ? $resolver : new GuardedUrlResolver($resolver, $source->attributes, $logger), $source->extractor, $logger);
        $this->sink = $sink ?? static function (array $urls) use ($source, $autoFlush): void {
            $source->collect($urls);
            if ($autoFlush) {
                $source->flush();
            }
        };
    }

    /** The change handler, built on the first flush that has something to classify and kept from then on. */
    private function changes(): ObjectChangeHandler
    {
        return $this->changes ??= ($this->changeHandler)();
    }

    public function onFlush(OnFlushEventArgs $args): void
    {
        $uow = self::entityManager($args)->getUnitOfWork();
        if (!$this->inFlush) {
            // A fresh flush starts clean; a flush() another listener triggers inside postFlush() accumulates into the outer one instead.
            $this->pending = [];
            $this->resolved = [];
        }
        $this->inFlush = true;

        foreach ($uow->getScheduledEntityInsertions() as $entity) {
            $this->defer($entity, $this->changes()->createdEvents($entity));
        }

        foreach ($uow->getScheduledEntityUpdates() as $entity) {
            /** @var array<string, array{0: mixed, 1: mixed}> $changeSet */
            $changeSet = $uow->getEntityChangeSet($entity);
            $this->resolved = [...$this->resolved, ...$this->changes()->renamed($entity, $changeSet)]; // old URLs of a renamed page, resolved before the write
            foreach ($this->changes()->distinct($this->changes()->updatedEvents($entity, array_keys($changeSet), $changeSet)) as $ruleEvent) {
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
            $this->defer($owner, $this->changes()->updatedEvents($owner, [self::fieldName($collection)]));
        }

        foreach ($uow->getScheduledEntityDeletions() as $entity) {
            foreach ($this->changes()->distinct($this->changes()->deletedEvents($entity)) as $ruleEvent) {
                $this->resolveNow($entity, $ruleEvent);
            }
        }
    }

    public function postFlush(PostFlushEventArgs $args): void
    {
        $resolved = $this->resolved;
        $pending = $this->pending;
        $this->pending = [];
        $this->resolved = [];
        $this->inFlush = false;
        foreach ($pending as [$entity, $ruleEvent]) {
            $resolved = [...$resolved, ...$this->changes()->resolve($entity, $ruleEvent)];
        }

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
        ($this->sink)($urls);
    }

    /**
     * @param list<RuleEvent> $ruleEvents
     */
    private function defer(object $entity, array $ruleEvents): void
    {
        foreach ($this->changes()->distinct($ruleEvents) as $ruleEvent) {
            $this->pending[] = [$entity, $ruleEvent];
        }
    }

    private function resolveNow(object $entity, RuleEvent $ruleEvent): void
    {
        $this->resolved = [...$this->resolved, ...$this->changes()->resolve($entity, $ruleEvent)];
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
