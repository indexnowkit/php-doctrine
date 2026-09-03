<?php

declare(strict_types=1);

namespace IndexNowKit\Doctrine\Tests;

use IndexNowKit\Doctrine\Tests\Fixtures\BadAttribute;
use IndexNowKit\Doctrine\Tests\Fixtures\Broken;
use IndexNowKit\Doctrine\Tests\Fixtures\CategorizedPost;
use IndexNowKit\Doctrine\Tests\Fixtures\Category;
use IndexNowKit\Doctrine\Tests\Fixtures\MultiPost;
use IndexNowKit\Doctrine\Tests\Fixtures\Post;
use IndexNowKit\Doctrine\Tests\Fixtures\Tag;
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

    #[TestDox('A10b invalid #[IndexNow] attribute -> error logged in onFlush, flush succeeds')]
    public function testA10InvalidAttribute(): void
    {
        $this->em->persist(new BadAttribute('x'));
        $this->em->persist(new Post('with-bad-sibling'));
        $this->em->flush();

        self::assertSame(['https://www.example.com/posts/with-bad-sibling'], $this->sentUrls(), 'unrelated entity in the same flush is still submitted');
        self::assertStringContainsString('invalid #[IndexNow]', implode("\n", $this->logger->messages('error')));
        self::assertSame(1, $this->em->getRepository(BadAttribute::class)->count([]));
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

    #[TestDox('A15 multi-rule entity submits every applicable URL on create')]
    public function testA15MultiRuleEntitySubmitsAllApplicableUrls(): void
    {
        $this->em->persist(new MultiPost('multi', published: true, amp: true));
        $this->em->flush();

        self::assertEqualsCanonicalizing([
            'https://www.example.com/posts/multi',
            'https://www.example.com/amp/multi',
            'https://www.example.com/',
        ], $this->sentUrls());
    }

    #[TestDox('A16 amp true -> false submits the AMP URL as deletion while post_show (and the filter-less homepage rule) still get an update, in the same flush')]
    public function testA16AmpToggleSubmitsAmpDeletionAndPostShowUpdate(): void
    {
        $post = new MultiPost('withamp', published: true, amp: true);
        $this->em->persist($post);
        $this->em->flush();

        $post->amp = false;
        $this->em->flush();

        self::assertCount(2, $this->transport->posts, 'one POST per flush');
        $secondBatch = $this->transport->posts[1]['body']['urlList'];
        self::assertEqualsCanonicalizing([
            'https://www.example.com/amp/withamp',
            'https://www.example.com/posts/withamp',
            'https://www.example.com/',
        ], $secondBatch, 'the AMP page is resolved as a deletion despite `when` now being false; post_show and the homepage (no fields filter) resubmit as updates; nothing else');
    }

    #[TestDox('A17 unpublish through a getter-named `when` (isPublished() over $published) submits the deletion (the historic field-name-vs-accessor mismatch bug is fixed, and Deleted resolves despite `when` now being false)')]
    public function testA17UnpublishThroughAGetterNamedWhenSubmitsTheDeletion(): void
    {
        $post = new MultiPost('viageter', published: true, amp: false);
        $this->em->persist($post);
        $this->em->flush();
        self::assertEqualsCanonicalizing(['https://www.example.com/posts/viageter', 'https://www.example.com/'], $this->sentUrls());

        $post->published = false;
        $this->em->flush();

        self::assertCount(2, $this->transport->posts, 'one POST per flush');
        self::assertEqualsCanonicalizing(
            ['https://www.example.com/posts/viageter', 'https://www.example.com/'],
            $this->transport->posts[1]['body']['urlList'],
            'both rules whose `when` depends on isPublished() are resubmitted as deletions',
        );
    }

    #[TestDox('A18 deleting a draft (when already false) submits nothing, neither on create nor on delete')]
    public function testA18DeletingADraftSubmitsNothing(): void
    {
        $post = new MultiPost('neverpublished', published: false, amp: false);
        $this->em->persist($post);
        $this->em->flush();
        self::assertSame([], $this->sentUrls(), 'draft creation: no rule applies');

        $this->em->remove($post);
        $this->em->flush();
        self::assertSame([], $this->sentUrls(), 'draft deletion: it was never public, nothing to signal');
    }

    #[TestDox('A19 via a ManyToOne relation resubmits the category page, on create and on a later update')]
    public function testA19ViaRelationResubmitsTheCategoryPage(): void
    {
        $category = new Category('news');
        $post = new CategorizedPost('story');
        $post->category = $category;
        $this->em->persist($category);
        $this->em->persist($post);
        $this->em->flush();

        self::assertContains('https://www.example.com/categories/news', $this->sentUrls());
        $firstCount = \count($this->sentUrls());

        $post->views = 1;
        $this->em->flush();

        self::assertGreaterThan($firstCount, \count($this->sentUrls()), 'the category page resubmits again on an unrelated field update');
    }

    #[TestDox('A20 adding a tag (ManyToMany) is not part of the change set but still triggers an update')]
    public function testA20CollectionChangeTriggersAnUpdate(): void
    {
        $post = new CategorizedPost('tagged');
        $this->em->persist($post);
        $this->em->flush();
        self::assertSame(['https://www.example.com/posts/tagged'], $this->sentUrls());

        $tag = new Tag('news');
        $this->em->persist($tag);
        $post->tags->add($tag);
        $this->em->flush();

        self::assertCount(2, $this->transport->posts, 'the collection update triggers a second flush-worth of submission');
        self::assertSame(['https://www.example.com/posts/tagged'], $this->transport->posts[1]['body']['urlList']);
    }
}
