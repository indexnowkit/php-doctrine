<?php

declare(strict_types=1);

namespace IndexNowKit\Doctrine\Transaction;

use WeakMap;

/**
 * URLs collected inside an open DBAL transaction, keyed by the native (PDO/mysqli/...) connection object,
 * which is the one identity shared between the ORM connection and the driver middleware.
 */
final class TransactionStaging
{
    /** @var WeakMap<object, array<string, true>> */
    private WeakMap $pending;

    /** @var (callable(list<string>): void)|null */
    private $sink;

    /**
     * @param (callable(list<string>): void)|null $sink receives URLs once the real COMMIT happened
     */
    public function __construct(?callable $sink = null)
    {
        $this->sink = $sink;
        $this->pending = new WeakMap();
    }

    /**
     * @param callable(list<string>): void $sink
     */
    public function setSink(callable $sink): void
    {
        $this->sink = $sink;
    }

    /**
     * @param list<string> $urls
     */
    public function stage(object $nativeConnection, array $urls): void
    {
        $current = $this->pending[$nativeConnection] ?? [];
        foreach ($urls as $url) {
            $current[$url] = true;
        }
        $this->pending[$nativeConnection] = $current;
    }

    public function commit(object $nativeConnection): void
    {
        $urls = $this->take($nativeConnection);
        if ($urls !== [] && $this->sink !== null) {
            ($this->sink)($urls);
        }
    }

    public function discard(object $nativeConnection): void
    {
        $this->take($nativeConnection);
    }

    public function hasPending(object $nativeConnection): bool
    {
        return isset($this->pending[$nativeConnection]) && $this->pending[$nativeConnection] !== [];
    }

    /**
     * @return list<string>
     */
    private function take(object $nativeConnection): array
    {
        $urls = array_keys($this->pending[$nativeConnection] ?? []);
        unset($this->pending[$nativeConnection]);

        return $urls;
    }
}
