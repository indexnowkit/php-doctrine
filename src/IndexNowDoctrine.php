<?php

declare(strict_types=1);

namespace IndexNowKit\Doctrine;

use Doctrine\DBAL\Configuration as DbalConfiguration;
use Doctrine\ORM\EntityManagerInterface;
use IndexNowKit\Doctrine\Middleware\IndexNowMiddleware;
use IndexNowKit\Transaction\TransactionStaging;
use IndexNowKit\IndexNowKit;
use IndexNowKit\Url\UrlResolverInterface;
use Psr\Log\LoggerInterface;
use Psr\Log\NullLogger;

/**
 * Standalone wiring helper (no framework). Framework bundles wire the same pieces through DI.
 */
final class IndexNowDoctrine
{
    public readonly TransactionStaging $staging;
    public readonly IndexNowListener $listener;
    public readonly IndexNowMiddleware $middleware;

    public function __construct(IndexNowKit $indexNow, UrlResolverInterface $resolver, LoggerInterface $logger = new NullLogger(), bool $autoFlush = true)
    {
        $this->staging = new TransactionStaging();
        $this->listener = new IndexNowListener($indexNow, $resolver, $this->staging, $logger, $autoFlush);
        $this->staging->setSink($this->listener->deliver(...));
        $this->middleware = new IndexNowMiddleware($this->staging);
    }

    /**
     * Must be called BEFORE the connection is created (middlewares wrap the driver at connect time).
     */
    public function registerMiddleware(DbalConfiguration $configuration): void
    {
        $configuration->setMiddlewares([...$configuration->getMiddlewares(), $this->middleware]);
    }

    public function registerListener(EntityManagerInterface $em): void
    {
        $em->getEventManager()->addEventListener(IndexNowListener::EVENTS, $this->listener);
    }
}
