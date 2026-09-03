<?php

declare(strict_types=1);

namespace IndexNowKit\Doctrine\Tests;

use Doctrine\DBAL\DriverManager;
use Doctrine\ORM\EntityManager;
use Doctrine\ORM\ORMSetup;
use Doctrine\ORM\Tools\SchemaTool;
use IndexNowKit\Doctrine\IndexNowDoctrine;
use IndexNowKit\Doctrine\Tests\Fixtures\FakeRouter;
use IndexNowKit\IndexNow;
use IndexNowKit\Tests\Support\ArrayLogger;
use IndexNowKit\Tests\Support\Factory;
use IndexNowKit\Tests\Support\FakeTransport;
use IndexNowKit\Url\ArrayResolverLocator;
use IndexNowKit\Url\AttributeUrlResolver;
use PHPUnit\Framework\TestCase;

abstract class DoctrineTestCase extends TestCase
{
    protected EntityManager $em;
    protected FakeTransport $transport;
    protected ArrayLogger $logger;
    protected IndexNow $indexNow;
    protected IndexNowDoctrine $wiring;

    protected function setUp(): void
    {
        $this->transport = new FakeTransport();
        $this->logger = new ArrayLogger();
        $this->indexNow = IndexNow::create(Factory::config(), $this->transport, $this->logger);
        $resolver = new AttributeUrlResolver($this->indexNow->attributes, new FakeRouter(), new ArrayResolverLocator());
        $this->wiring = new IndexNowDoctrine($this->indexNow, $resolver, $this->logger, autoFlush: true);

        $config = ORMSetup::createAttributeMetadataConfiguration([__DIR__ . '/Fixtures'], true);
        $this->wiring->registerMiddleware($config);
        $connection = DriverManager::getConnection(['driver' => 'pdo_sqlite', 'memory' => true], $config);
        $this->em = new EntityManager($connection, $config);
        $this->wiring->registerListener($this->em);

        (new SchemaTool($this->em))->createSchema($this->em->getMetadataFactory()->getAllMetadata());
    }

    /**
     * @return list<string>
     */
    protected function sentUrls(): array
    {
        $urls = [];
        foreach ($this->transport->posts as $post) {
            /** @var list<string> $list */
            $list = $post['body']['urlList'];
            $urls = [...$urls, ...$list];
        }

        return $urls;
    }
}
