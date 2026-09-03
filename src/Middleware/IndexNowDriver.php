<?php

declare(strict_types=1);

namespace IndexNowKit\Doctrine\Middleware;

use Doctrine\DBAL\Driver;
use Doctrine\DBAL\Driver\Connection as DriverConnection;
use Doctrine\DBAL\Driver\Middleware\AbstractConnectionMiddleware;
use Doctrine\DBAL\Driver\Middleware\AbstractDriverMiddleware;
use IndexNowKit\Doctrine\Transaction\TransactionStaging;
use ReflectionMethod;

final class IndexNowDriver extends AbstractDriverMiddleware
{
    private static ?bool $dbal4 = null;

    public function __construct(Driver $driver, private readonly TransactionStaging $staging)
    {
        parent::__construct($driver);
    }

    /**
     * @param array<string, mixed> $params
     */
    public function connect(array $params): DriverConnection
    {
        /** @phpstan-ignore argument.type (DBAL's Params shape is a phpdoc-only type; forwarded untouched) */
        $connection = parent::connect($params);

        return self::isDbal4()
            ? new IndexNowConnection($connection, $this->staging)
            : new IndexNowConnectionV3($connection, $this->staging);
    }

    /** DBAL 4 changed commit()/rollBack() to return void; DBAL 3 returns bool. */
    private static function isDbal4(): bool
    {
        return self::$dbal4 ??= (string) (new ReflectionMethod(AbstractConnectionMiddleware::class, 'commit'))->getReturnType() === 'void';
    }
}
