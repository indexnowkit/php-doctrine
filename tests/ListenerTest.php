<?php

declare(strict_types=1);

namespace IndexNowKit\Doctrine\Tests;

use IndexNowKit\Doctrine\IndexNowDoctrine;
use IndexNowKit\Doctrine\IndexNowListener;
use IndexNowKit\Doctrine\Tests\Fixtures\FakeRouter;
use IndexNowKit\Doctrine\Tests\Fixtures\Post;
use IndexNowKit\Url\AttributeUrlResolver;
use PHPUnit\Framework\Attributes\TestDox;
use RuntimeException;

/**
 * Doctrine-specific behaviour on top of the shared ORM conformance kit ({@see OrmConformanceTest}): standalone
 * wiring with autoFlush, wrapInTransaction, one flush = one POST, ids cleared after remove.
 */
final class ListenerTest extends DoctrineTestCase
{
    #[TestDox('autoFlush: persist + flush outside a transaction -> one POST right after flush')]
    public function testAutoFlushSubmitsAfterFlush(): void
    {
        $this->em->persist(new Post('hello'));
        $this->em->flush();

        self::assertSame(['https://www.example.com/posts/hello'], $this->sentUrls());
    }

    #[TestDox('remove clears the id -> the URL had to be resolved in onFlush, before the delete')]
    public function testDeleteResolvesBeforeRemoval(): void
    {
        $post = new Post('gone');
        $this->em->persist($post);
        $this->em->flush();
        $this->em->remove($post);
        $this->em->flush();

        self::assertSame(['https://www.example.com/posts/gone', 'https://www.example.com/posts/gone'], $this->sentUrls());
        self::assertNull($post->id, 'id is cleared by Doctrine after deletion, so the URL had to be resolved earlier');
    }

    #[TestDox('wrapInTransaction that throws after flush -> no POST')]
    public function testWrapInTransactionExceptionSubmitsNothing(): void
    {
        try {
            $this->em->wrapInTransaction(function (): void {
                $this->em->persist(new Post('wrapped'));
                $this->em->flush();
                throw new RuntimeException('boom');
            });
        } catch (RuntimeException) {
            $this->em->clear();
        }

        self::assertSame([], $this->sentUrls());
    }

    #[TestDox('three persists, one flush -> one POST with three URLs')]
    public function testOneFlushOnePost(): void
    {
        $this->em->persist(new Post('a'));
        $this->em->persist(new Post('b'));
        $this->em->persist(new Post('c'));
        $this->em->flush();

        self::assertCount(1, $this->transport->posts);
        self::assertCount(3, $this->sentUrls());
    }

    #[TestDox('autoFlush=false -> URLs wait in the collector until IndexNowKit::flush()')]
    public function testCollectorWithoutAutoFlush(): void
    {
        $wiring = new IndexNowDoctrine($this->indexNow, new AttributeUrlResolver($this->indexNow->attributes, new FakeRouter()), $this->logger, autoFlush: false);
        $this->em->getEventManager()->removeEventListener(IndexNowListener::EVENTS, $this->wiring->listener);
        $wiring->registerListener($this->em);

        $this->em->persist(new Post('later'));
        $this->em->flush();
        self::assertSame([], $this->sentUrls());
        self::assertSame(1, $this->indexNow->collector->count());

        $this->indexNow->flush();
        self::assertSame(['https://www.example.com/posts/later'], $this->sentUrls());
    }
}
