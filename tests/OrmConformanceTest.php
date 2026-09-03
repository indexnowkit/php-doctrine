<?php

declare(strict_types=1);

namespace IndexNowKit\Doctrine\Tests;

use Doctrine\DBAL\DriverManager;
use Doctrine\ORM\EntityManager;
use Doctrine\ORM\ORMSetup;
use Doctrine\ORM\Tools\SchemaTool;
use IndexNowKit\Config;
use IndexNowKit\Doctrine\IndexNowDoctrine;
use IndexNowKit\Doctrine\Tests\Fixtures\BadAttribute;
use IndexNowKit\Doctrine\Tests\Fixtures\Broken;
use IndexNowKit\Doctrine\Tests\Fixtures\CategorizedPost;
use IndexNowKit\Doctrine\Tests\Fixtures\Category;
use IndexNowKit\Doctrine\Tests\Fixtures\FakeRouter;
use IndexNowKit\Doctrine\Tests\Fixtures\MultiPost;
use IndexNowKit\Doctrine\Tests\Fixtures\Post;
use IndexNowKit\Doctrine\Tests\Fixtures\Tag;
use IndexNowKit\Doctrine\Tests\Fixtures\Untracked;
use IndexNowKit\IndexNowKit;
use IndexNowKit\Testing\ArrayLogger;
use IndexNowKit\Testing\Conformance\OrmConformanceTestCase;
use IndexNowKit\Testing\FakeTransport;
use IndexNowKit\Url\ArrayResolverLocator;
use IndexNowKit\Url\AttributeUrlResolver;

/**
 * The core ORM conformance kit (A01-A21) driven through Doctrine ORM + sqlite: onFlush/postFlush listener and the
 * DBAL middleware for commit safety. Doctrine-specific behaviour (ids cleared after remove, wrapInTransaction,
 * autoFlush) is in {@see ListenerTest}.
 */
final class OrmConformanceTest extends OrmConformanceTestCase
{
    private EntityManager $em;
    private FakeTransport $transport;
    private ArrayLogger $logger;
    private IndexNowKit $indexNow;

    protected function setUp(): void
    {
        $this->transport = new FakeTransport();
        $this->logger = new ArrayLogger();
        $this->indexNow = IndexNowKit::create(Config::fromArray(['key' => DoctrineTestCase::KEY, 'base_url' => 'https://www.example.com', 'debounce' => ['per_url' => 0]]), $this->transport, $this->logger);
        $resolver = new AttributeUrlResolver($this->indexNow->attributes, new FakeRouter(), new ArrayResolverLocator());
        $wiring = new IndexNowDoctrine($this->indexNow, $resolver, $this->logger, autoFlush: false);

        $config = ORMSetup::createAttributeMetadataConfiguration([__DIR__ . '/Fixtures'], true);
        $wiring->registerMiddleware($config);
        $connection = DriverManager::getConnection(['driver' => 'pdo_sqlite', 'memory' => true], $config);
        $connection->setNestTransactionsWithSavepoints(true);
        $this->em = new EntityManager($connection, $config);
        $wiring->registerListener($this->em);
        (new SchemaTool($this->em))->createSchema($this->em->getMetadataFactory()->getAllMetadata());
    }

    protected function transport(): FakeTransport
    {
        return $this->transport;
    }

    protected function logger(): ArrayLogger
    {
        return $this->logger;
    }

    protected function flush(): void
    {
        $this->indexNow->flush();
    }

    protected function collectedCount(): int
    {
        return $this->indexNow->collector->count();
    }

    protected function begin(): void
    {
        $this->em->beginTransaction();
    }

    protected function commit(): void
    {
        $this->em->commit();
    }

    protected function rollback(): void
    {
        $this->em->rollback();
        $this->em->clear(); // Doctrine requires clearing the identity map after a rollback
    }

    protected function createPost(string $slug, bool $published = true): object
    {
        return $this->persist(new Post($slug, published: $published));
    }

    protected function createMultiPost(string $slug, bool $published, bool $amp): object
    {
        return $this->persist(new MultiPost($slug, $published, $amp));
    }

    protected function createCategory(string $slug): object
    {
        return $this->persist(new Category($slug));
    }

    protected function createCategorizedPost(string $slug, ?object $category = null): object
    {
        $post = new CategorizedPost($slug);
        if ($category instanceof Category) {
            $post->category = $category;
        }

        return $this->persist($post);
    }

    protected function createTag(string $name): object
    {
        return $this->persist(new Tag($name));
    }

    protected function createUntracked(): object
    {
        return $this->persist(new Untracked('x'));
    }

    protected function createBroken(): object
    {
        return $this->persist(new Broken('x'));
    }

    protected function createBadAttribute(): object
    {
        return $this->persist(new BadAttribute('x'));
    }

    protected function update(object $model, array $fields): void
    {
        foreach ($fields as $field => $value) {
            $model->$field = $value; // @phpstan-ignore property.dynamicName
        }
        $this->em->flush();
    }

    protected function delete(object $model): void
    {
        $this->em->remove($model);
        $this->em->flush();
    }

    protected function attachTag(object $post, object $tag): void
    {
        \assert($post instanceof CategorizedPost && $tag instanceof Tag);
        $post->tags->add($tag);
        $this->em->flush();
    }

    protected function bulkUpdateTitle(string $title): void
    {
        $this->em->createQuery('UPDATE ' . Post::class . ' p SET p.title = :t')->setParameter('t', $title)->execute();
    }

    private function persist(object $entity): object
    {
        $this->em->persist($entity);
        $this->em->flush();

        return $entity;
    }
}
