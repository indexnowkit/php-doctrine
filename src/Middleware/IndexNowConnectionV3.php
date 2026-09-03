<?php

declare(strict_types=1);

namespace IndexNowKit\Doctrine\Middleware;

use Doctrine\DBAL\Driver\Connection as DriverConnection;
use Doctrine\DBAL\Driver\Middleware\AbstractConnectionMiddleware;
use IndexNowKit\Doctrine\Transaction\TransactionStaging;
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
        $this->staging->commit($this->native());

        return $result;
    }

    public function rollBack(): bool
    {
        $result = parent::rollBack();
        $this->staging->discard($this->native());

        return $result;
    }

    private function native(): object
    {
        $native = $this->getNativeConnection();

        return \is_object($native) ? $native : $this;
    }
}
