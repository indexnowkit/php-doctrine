<?php

declare(strict_types=1);

namespace IndexNowKit\Doctrine\Tests;

use IndexNowKit\Doctrine\Tests\Fixtures\Broken;
use IndexNowKit\Doctrine\Tests\Fixtures\Post;
use IndexNowKit\Doctrine\Tests\Fixtures\Untracked;
use IndexNowKit\Http\Response;
use PHPUnit\Framework\Attributes\TestDox;
use RuntimeException;

/**
 * Scenarios A01-A14 from docs/spec/03-conformance.md against Doctrine ORM + sqlite.
 */
final class OrmConformanceTest extends DoctrineTestCase
{
    #[TestDox('A01 persist + flush outside a transaction -> one POST after flush')]
    public function testA01Create(): void
    {
        $this->em->persist(new Post('hello'));
        $this->em->flush();

        self::assertSame(['https://www.example.com/posts/hello'], $this->sentUrls());
    }

    #[TestDox('A02 flush inside a transaction that rolls back -> no POST')]
    public function testA02Rollback(): void
    {
        $this->em->beginTransaction();
        $this->em->persist(new Post('rolled'));
        $this->em->flush();
        self::assertSame([], $this->sentUrls(), 'nothing sent before COMMIT');
        $this->em->rollback();
        $this->em->clear(); // Doctrine requires clearing the identity map after a rollback

        self::assertSame([], $this->sentUrls());
        $this->em->persist(new Post('after'));
        $this->em->flush();
        self::assertSame(['https://www.example.com/posts/after'], $this->sentUrls(), 'staging was discarded, later flush works');
    }

    #[TestDox('A03 update tracked field -> POST with same URL')]
    public function testA03Update(): void
    {
        $post = new Post('upd');
        $this->em->persist($post);
        $this->em->flush();
        $post->title = 'changed';
        $this->em->flush();

        self::assertSame(['https://www.example.com/posts/upd', 'https://www.example.com/posts/upd'], $this->sentUrls());
    }

    #[TestDox('A04 remove -> POST with URL resolved before deletion')]
    public function testA04Delete(): void
    {
        $post = new Post('gone');
        $this->em->persist($post);
        $this->em->flush();
        $this->em->remove($post);
        $this->em->flush();

        self::assertSame(['https://www.example.com/posts/gone', 'https://www.example.com/posts/gone'], $this->sentUrls());
        self::assertNull($post->id, 'id is cleared by Doctrine after deletion, so URL had to be resolved earlier');
    }

    #[TestDox('A05 nested transaction, outer rollback -> no POST; wrapInTransaction with exception -> no POST')]
    public function testA05NestedRollback(): void
    {
        $this->em->getConnection()->setNestTransactionsWithSavepoints(true);
        $this->em->beginTransaction();
        $this->em->beginTransaction();
        $this->em->persist(new Post('inner'));
        $this->em->flush();
        $this->em->commit();
        self::assertSame([], $this->sentUrls(), 'inner commit is a savepoint release, not a COMMIT');
        $this->em->rollback();
        $this->em->clear();
        self::assertSame([], $this->sentUrls());

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

    #[TestDox('A05b nested transaction, outer commit -> one POST at the real COMMIT')]
    public function testA05NestedCommit(): void
    {
        $this->em->beginTransaction();
        $this->em->persist(new Post('n1'));
        $this->em->flush();
        $this->em->beginTransaction();
        $this->em->persist(new Post('n2'));
        $this->em->flush();
        $this->em->commit();
        self::assertSame([], $this->sentUrls());
        $this->em->commit();

        self::assertCount(1, $this->transport->posts);
        self::assertEqualsCanonicalizing(['https://www.example.com/posts/n1', 'https://www.example.com/posts/n2'], $this->sentUrls());
    }

    #[TestDox('A06 three entities in one flush -> one POST with three URLs')]
    public function testA06Batch(): void
    {
        $this->em->persist(new Post('a'));
        $this->em->persist(new Post('b'));
        $this->em->persist(new Post('c'));
        $this->em->flush();

        self::assertCount(1, $this->transport->posts);
        self::assertCount(3, $this->sentUrls());
    }

    #[TestDox('A07 entity without attribute -> nothing')]
    public function testA07Untracked(): void
    {
        $this->em->persist(new Untracked('x'));
        $this->em->flush();

        self::assertSame([], $this->sentUrls());
    }

    #[TestDox('A08 when=false (draft) -> nothing on create/update')]
    public function testA08Draft(): void
    {
        $post = new Post('draft', published: false);
        $this->em->persist($post);
        $this->em->flush();
        $post->title = 'still draft';
        $this->em->flush();

        self::assertSame([], $this->sentUrls());
    }

    #[TestDox('A09 published -> draft is sent as deletion; draft -> published as creation')]
    public function testA09PublishTransitions(): void
    {
        $post = new Post('toggle', published: false);
        $this->em->persist($post);
        $this->em->flush();
        self::assertSame([], $this->sentUrls());

        $post->published = true;
        $this->em->flush();
        self::assertSame(['https://www.example.com/posts/toggle'], $this->sentUrls(), 'draft -> published = created');

        $post->published = false;
        $this->em->flush();
        self::assertCount(2, $this->sentUrls(), 'published -> draft = deleted (URL now 404, engines must recrawl)');
    }

    #[TestDox('A10 resolver throws -> error logged, flush succeeds')]
    public function testA10ResolverError(): void
    {
        $this->em->persist(new Broken('x'));
        $this->em->flush();

        self::assertSame([], $this->sentUrls());
        self::assertStringContainsString('cannot resolve URL', implode("\n", $this->logger->messages('error')));
        self::assertSame(1, $this->em->getRepository(Broken::class)->count([]));
    }

    #[TestDox('A11 HTTP 500 -> flush succeeds, warning logged')]
    public function testA11HttpError(): void
    {
        $this->transport->willRespond(new Response(500));
        $this->em->persist(new Post('e'));
        $this->em->flush();

        self::assertCount(1, $this->transport->posts);
        self::assertStringContainsString('server error 500', implode("\n", $this->logger->messages('warning')));
    }

    #[TestDox('A12 only untracked field changed -> no POST')]
    public function testA12FieldsFilter(): void
    {
        $post = new Post('views');
        $this->em->persist($post);
        $this->em->flush();
        $post->views = 42;
        $this->em->flush();

        self::assertCount(1, $this->transport->posts);
    }

    #[TestDox('A13 DQL bulk update bypasses the unit of work -> no POST (documented)')]
    public function testA13BulkBypass(): void
    {
        $this->em->persist(new Post('bulk'));
        $this->em->flush();
        $this->em->createQuery('UPDATE ' . Post::class . ' p SET p.title = :t')->setParameter('t', 'x')->execute();

        self::assertCount(1, $this->transport->posts);
    }

    #[TestDox('A14 autoFlush=false -> URLs wait in the collector until flush()')]
    public function testA14CollectorWithoutAutoFlush(): void
    {
        $wiring = new \IndexNowKit\Doctrine\IndexNowDoctrine($this->indexNow, new \IndexNowKit\Url\AttributeUrlResolver($this->indexNow->attributes, new \IndexNowKit\Doctrine\Tests\Fixtures\FakeRouter()), $this->logger, autoFlush: false);
        $this->em->getEventManager()->removeEventListener(\IndexNowKit\Doctrine\IndexNowListener::EVENTS, $this->wiring->listener);
        $wiring->registerListener($this->em);

        $this->em->persist(new Post('later'));
        $this->em->flush();
        self::assertSame([], $this->sentUrls());
        self::assertSame(1, $this->indexNow->collector->count());

        $this->indexNow->flush();
        self::assertSame(['https://www.example.com/posts/later'], $this->sentUrls());
    }
}
