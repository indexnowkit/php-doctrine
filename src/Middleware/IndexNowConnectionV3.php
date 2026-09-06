<?php

declare(strict_types=1);

namespace IndexNowKit\Doctrine\Middleware;

use Doctrine\DBAL\Driver\Connection as DriverConnection;
use Doctrine\DBAL\Driver\Middleware\AbstractConnectionMiddleware;
use IndexNowKit\Transaction\TransactionStaging;
use Throwable;

/**
 * DBAL 3 flavour (bool return types). Only autoloaded on DBAL 3.
 */
final class IndexNowConnectionV3 extends AbstractConnectionMiddleware
{
    public function __construct(DriverConnection $connection, private readonly TransactionStaging $staging)
    {
        parent::__construct($connection);
    }

    public function commit(): bool
    {
        try {
            $result = parent::commit();
        } catch (Throwable $e) {
            $this->staging->discard($this->native());

            throw $e;
        }
        if ($result) {
            $this->staging->commit($this->native());
        } else {
            $this->staging->discard($this->native()); // DBAL 3 drivers may report a failed commit with false instead of an exception
        }

        return $result;
    }

    public function rollBack(): bool
    {
        try {
            return parent::rollBack();
        } finally {
            $this->staging->discard($this->native()); // a driver that throws on rollback must not leave the URLs for the next commit
        }
    }

    public function exec(string $sql): int
    {
        $affected = parent::exec($sql);
        SavepointStatement::apply($this->staging, $this->native(), $sql);

        return $affected;
    }

    private function native(): object
    {
        $native = $this->getNativeConnection();

        return \is_object($native) ? $native : $this;
    }
}
