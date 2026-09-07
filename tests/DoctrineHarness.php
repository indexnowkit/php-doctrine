<?php

declare(strict_types=1);

namespace IndexNowKit\Doctrine\Tests;

use Doctrine\DBAL\DriverManager;
use Doctrine\ORM\EntityManager;
use Doctrine\ORM\ORMSetup;
use Doctrine\ORM\Tools\SchemaTool;
use IndexNowKit\Attribute\ParamExtractor;
use IndexNowKit\Config;
use IndexNowKit\Doctrine\IndexNowDoctrine;
use IndexNowKit\Doctrine\Tests\Fixtures\FakeRouter;
use IndexNowKit\IndexNowKit;
use IndexNowKit\Testing\ArrayLogger;
use IndexNowKit\Testing\FakeTransport;
use IndexNowKit\Url\ArrayResolverLocator;
use IndexNowKit\Url\AttributeUrlResolver;

/** The graph of {@see DoctrineTestCase} as an object: the conformance kits need it without inheriting the test case. */
final class DoctrineHarness
{
    /** A second host with a key of its own, so C04 (two hosts -> one POST per host under its own key) really runs. */
    public const SECOND_HOST = 'example.de';
    public const SECOND_KEY = 'fedcba0987654321fedcba0987654321';

    public readonly FakeTransport $transport;
    public readonly ArrayLogger $logger;
    public readonly IndexNowKit $indexNow;
    public readonly IndexNowDoctrine $wiring;
    public readonly EntityManager $em;

    /**
     * @param array<string, mixed> $overrides
     */
    public function __construct(array $overrides = [])
    {
        $this->transport = new FakeTransport();
        $this->logger = new ArrayLogger();
        $this->indexNow = IndexNowKit::create(Config::fromArray($overrides + ['key' => DoctrineTestCase::KEY, 'base_url' => 'https://www.example.com', 'hosts' => [self::SECOND_HOST => self::SECOND_KEY], 'debounce' => ['per_url' => 0]]), $this->transport, $this->logger);
        $resolver = new AttributeUrlResolver($this->indexNow->attributes, ParamExtractor::plain(), new FakeRouter(), new ArrayResolverLocator());
        $this->wiring = new IndexNowDoctrine($this->indexNow, $resolver, $this->logger, autoFlush: true);

        $config = ORMSetup::createAttributeMetadataConfiguration([__DIR__ . '/Fixtures'], true);
        $this->wiring->registerMiddleware($config);
        $connection = DriverManager::getConnection(['driver' => 'pdo_sqlite', 'memory' => true], $config);
        $this->em = new EntityManager($connection, $config);
        $this->wiring->registerListener($this->em);

        (new SchemaTool($this->em))->createSchema($this->em->getMetadataFactory()->getAllMetadata());
    }
}
