<?php

declare(strict_types=1);

namespace IndexNowKit\Doctrine\Middleware;

use IndexNowKit\Transaction\TransactionStaging;

/**
 * Recognizes the savepoint statements DBAL issues for nested transactions and mirrors them into the staging,
 * so URLs staged inside a rolled-back inner transaction never reach the outer COMMIT.
 *
 * Covers every DBAL platform: `SAVEPOINT x` / `RELEASE SAVEPOINT x` / `ROLLBACK TO SAVEPOINT x` (MySQL, PostgreSQL,
 * SQLite, Oracle, DB2) and `SAVE TRANSACTION x` / `ROLLBACK TRANSACTION x` (SQL Server).
 *
 * @internal
 */
final class SavepointStatement
{
    private const CREATE = '/^\s*(?:SAVEPOINT|SAVE\s+TRANSACTION)\s+([^\s;]+)\s*;?\s*$/i';
    private const RELEASE = '/^\s*RELEASE\s+SAVEPOINT\s+([^\s;]+)\s*;?\s*$/i';
    private const ROLLBACK = '/^\s*ROLLBACK\s+(?:TO\s+SAVEPOINT|TO|TRANSACTION)\s+([^\s;]+)\s*;?\s*$/i';

    public static function apply(TransactionStaging $staging, object $scope, string $sql): void
    {
        if (preg_match(self::CREATE, $sql, $m) === 1) {
            $staging->savepoint($scope, self::name($m[1]));
        } elseif (preg_match(self::RELEASE, $sql, $m) === 1) {
            $staging->release($scope, self::name($m[1]));
        } elseif (preg_match(self::ROLLBACK, $sql, $m) === 1) {
            $staging->rollbackTo($scope, self::name($m[1]));
        }
    }

    private static function name(string $raw): string
    {
        return trim($raw, '`"[]');
    }
}
