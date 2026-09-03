<?php

declare(strict_types=1);

namespace IndexNowKit\Doctrine\Middleware;

use Doctrine\DBAL\Driver\Connection as DriverConnection;
use Doctrine\DBAL\Driver\Middleware\AbstractConnectionMiddleware;
use IndexNowKit\Transaction\TransactionStaging;
use Throwable;

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
        try {
            parent::commit();
        } catch (Throwable $e) {
            $this->staging->discard($this->native());

            throw $e;
        }
        $this->staging->commit($this->native());
    }

    public function rollBack(): void
    {
        parent::rollBack();
        $this->staging->discard($this->native());
    }

    public function exec(string $sql): int|string
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
