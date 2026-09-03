<?php

declare(strict_types=1);

namespace IndexNowKit\Doctrine\Middleware;

use Doctrine\DBAL\Driver\Connection as DriverConnection;
use Doctrine\DBAL\Driver\Middleware\AbstractConnectionMiddleware;
use IndexNowKit\Doctrine\Transaction\TransactionStaging;

/**
 * DBAL 4 flavour (void return types).
 */
final class IndexNowConnection extends AbstractConnectionMiddleware
{
    public function __construct(DriverConnection $connection, private readonly TransactionStaging $staging)
    {
        parent::__construct($connection);
    }

    public function commit(): void
    {
        parent::commit();
        $this->staging->commit($this->native());
    }

    public function rollBack(): void
    {
        parent::rollBack();
        $this->staging->discard($this->native());
    }

    private function native(): object
    {
        $native = $this->getNativeConnection();

        return \is_object($native) ? $native : $this;
    }
}
