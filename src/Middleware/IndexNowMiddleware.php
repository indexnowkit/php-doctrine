<?php

declare(strict_types=1);

namespace IndexNowKit\Doctrine\Middleware;

use Doctrine\DBAL\Driver;
use Doctrine\DBAL\Driver\Middleware;
use IndexNowKit\Transaction\TransactionStaging;

/**
 * DBAL driver middleware: observes real COMMIT/ROLLBACK (nesting level 0) on every connection.
 * Register with DoctrineBundle (tag doctrine.middleware) or Configuration::setMiddlewares().
 */
final class IndexNowMiddleware implements Middleware
{
    public function __construct(private readonly TransactionStaging $staging) {}

    public function wrap(Driver $driver): Driver
    {
        return new IndexNowDriver($driver, $this->staging);
    }
}
