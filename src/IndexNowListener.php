<?php

declare(strict_types=1);

namespace IndexNowKit\Doctrine;

use Doctrine\ORM\EntityManagerInterface;
use Doctrine\ORM\Event\OnFlushEventArgs;
use Doctrine\ORM\Event\PostFlushEventArgs;
use Doctrine\ORM\Events;
use IndexNowKit\Attribute\AttributeReader;
use IndexNowKit\Attribute\IndexNow as IndexNowAttribute;
use IndexNowKit\Doctrine\Transaction\TransactionStaging;
use IndexNowKit\IndexNow;
use IndexNowKit\Url\Event;
use IndexNowKit\Url\PublishGuard;
use IndexNowKit\Url\UrlResolverInterface;
use Psr\Log\LoggerInterface;
use Psr\Log\NullLogger;
use Throwable;

/**
 * Collects changed entities in onFlush, resolves URLs in postFlush (ids assigned), hands them over
 * only once the outermost transaction committed.
 */
final class IndexNowListener
{
    public const EVENTS = [Events::onFlush, Events::postFlush];

    /** @var list<array{0: object, 1: Event}> */
    private array $pendingEntities = [];

    /** @var list<string> URLs already resolved (deletions) */
    private array $pendingUrls = [];

    private readonly AttributeReader $reader;

    /**
     * @param bool $autoFlush call IndexNow::flush() right after hand-off (standalone usage); adapters flush at request end
     */
    public function __construct(
        private readonly IndexNow $indexNow,
        private readonly UrlResolverInterface $resolver,
        private readonly TransactionStaging $staging,
        private readonly LoggerInterface $logger = new NullLogger(),
        private readonly bool $autoFlush = false,
    ) {
        $this->reader = $indexNow->attributes;
    }

    public function onFlush(OnFlushEventArgs $args): void
    {
        $em = $args->getObjectManager();
        $uow = $em->getUnitOfWork();
        $this->pendingEntities = [];
        $this->pendingUrls = [];

        foreach ($uow->getScheduledEntityInsertions() as $entity) {
            $attribute = $this->reader->read($entity);
            if ($attribute !== null && $attribute->listensTo(Event::Created)) {
                $this->pendingEntities[] = [$entity, Event::Created];
            }
        }

        foreach ($uow->getScheduledEntityUpdates() as $entity) {
            $attribute = $this->reader->read($entity);
            if ($attribute === null) {
                continue;
            }
            /** @var array<string, array{0: mixed, 1: mixed}> $changeSet */
            $changeSet = $uow->getEntityChangeSet($entity);
            $event = self::classifyUpdate($entity, $attribute, $changeSet);
            if ($event === Event::Deleted) {
                $this->resolveNow($entity, Event::Deleted);
            } elseif ($event !== null) {
                $this->pendingEntities[] = [$entity, $event];
            }
        }

        foreach ($uow->getScheduledEntityDeletions() as $entity) {
            $attribute = $this->reader->read($entity);
            if ($attribute !== null && $attribute->listensTo(Event::Deleted)) {
                $this->resolveNow($entity, Event::Deleted);
            }
        }
    }

    public function postFlush(PostFlushEventArgs $args): void
    {
        $urls = $this->pendingUrls;
        foreach ($this->pendingEntities as [$entity, $event]) {
            $attribute = $this->reader->read($entity);
            if ($attribute === null || !PublishGuard::isPublished($entity, $attribute)) {
                continue;
            }
            $urls = [...$urls, ...$this->safeResolve($entity, $event)];
        }
        $this->pendingEntities = [];
        $this->pendingUrls = [];

        if ($urls === []) {
            return;
        }
        $this->handOff($args->getObjectManager(), array_values(array_unique($urls)));
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

    private function resolveNow(object $entity, Event $event): void
    {
        $this->pendingUrls = [...$this->pendingUrls, ...$this->safeResolve($entity, $event)];
    }

    /**
     * @return list<string>
     */
    private function safeResolve(object $entity, Event $event): array
    {
        try {
            $urls = [];
            foreach ($this->resolver->resolve($entity, $event) as $url) {
                $urls[] = $url;
            }

            return $urls;
        } catch (Throwable $e) {
            $this->logger->error('indexnow: cannot resolve URL for {class} ({event}): {error}', ['class' => $entity::class, 'event' => $event->value, 'error' => $e->getMessage(), 'exception' => $e]);

            return [];
        }
    }

    /**
     * @param array<string, array{0: mixed, 1: mixed}> $changeSet
     */
    private static function classifyUpdate(object $entity, IndexNowAttribute $attribute, array $changeSet): ?Event
    {
        if ($attribute->when !== null && isset($changeSet[$attribute->when])) {
            [$old, $new] = $changeSet[$attribute->when];
            if ((bool) $old && !(bool) $new) {
                return $attribute->listensTo(Event::Deleted) ? Event::Deleted : null;
            }
            if (!(bool) $old && (bool) $new) {
                return $attribute->listensTo(Event::Created) ? Event::Created : null;
            }
        }
        if (!$attribute->listensTo(Event::Updated) || !$attribute->caresAbout(array_keys($changeSet))) {
            return null;
        }

        return Event::Updated;
    }
}
