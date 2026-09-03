<?php

declare(strict_types=1);

namespace IndexNowKit\Doctrine\Tests;

use IndexNowKit\Doctrine\Middleware\SavepointStatement;
use IndexNowKit\Transaction\TransactionStaging;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use stdClass;

final class SavepointStatementTest extends TestCase
{
    /**
     * @return iterable<string, array{string, string, string}>
     */
    public static function platforms(): iterable
    {
        yield 'mysql/pgsql/sqlite' => ['SAVEPOINT DOCTRINE_2', 'RELEASE SAVEPOINT DOCTRINE_2', 'ROLLBACK TO SAVEPOINT DOCTRINE_2'];
        yield 'sql server' => ['SAVE TRANSACTION DOCTRINE_2', '', 'ROLLBACK TRANSACTION DOCTRINE_2'];
        yield 'lower case, trailing semicolon' => ['savepoint doctrine_2;', 'release savepoint doctrine_2;', 'rollback to savepoint doctrine_2;'];
    }

    #[DataProvider('platforms')]
    public function testSavepointStatementsAreMirroredIntoTheStaging(string $create, string $release, string $rollback): void
    {
        $delivered = [];
        $staging = new TransactionStaging(static function (array $urls) use (&$delivered): void {
            $delivered = $urls;
        });
        $scope = new stdClass();

        $staging->stage($scope, ['/outer']);
        SavepointStatement::apply($staging, $scope, $create);
        $staging->stage($scope, ['/inner']);
        SavepointStatement::apply($staging, $scope, $rollback);
        self::assertSame(1, $staging->pendingCount($scope));

        SavepointStatement::apply($staging, $scope, $create);
        $staging->stage($scope, ['/inner-2']);
        if ($release !== '') {
            SavepointStatement::apply($staging, $scope, $release);
        }
        $staging->commit($scope);

        self::assertSame(['/outer', '/inner-2'], $delivered);
    }

    public function testOrdinaryStatementsAreIgnored(): void
    {
        $staging = new TransactionStaging();
        $scope = new stdClass();
        $staging->stage($scope, ['/a']);

        foreach (['INSERT INTO savepoint_log (name) VALUES (\'SAVEPOINT x\')', 'ROLLBACK', 'COMMIT', 'SELECT 1', 'RELEASE SAVEPOINT'] as $sql) {
            SavepointStatement::apply($staging, $scope, $sql);
        }

        self::assertSame(1, $staging->pendingCount($scope));
    }
}
