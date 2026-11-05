<?php

namespace Tests\Unit;

use DB\DBException;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

/**
 * DBException::getRuleMessage() — only messages raised on purpose by a trigger
 * (user-defined SQLSTATE) are exposed; any other database error stays generic (#43).
 */
class DBExceptionRuleMessageTest extends TestCase
{
    private static function pdo(string $state, string $driverMsg): \PDOException
    {
        $e = new \PDOException('SQLSTATE[' . $state . ']: whatever');
        $e->errorInfo = [$state, 1644, $driverMsg];
        return $e;
    }

    #[DataProvider('cases')]
    public function testRuleMessage(string $state, string $driverMsg, ?string $expected): void
    {
        $ex = DBException::fromPdo(self::pdo($state, $driverMsg));

        $this->assertSame('Database error', $ex->getMessage());
        $this->assertSame($expected, $ex->getRuleMessage());
    }

    public static function cases(): array
    {
        return [
            'mysql signal'      => ['45000', 'Date must be in the past', 'Date must be in the past'],
            'pgsql raise'       => ['P0001', "ERROR:  err_future_date\nCONTEXT:  PL/pgSQL function chk() line 3", 'err_future_date'],
            'unique violation'  => ['23000', "Duplicate entry 'x' for key 'items.name'", null],
            'pgsql unique'      => ['23505', 'ERROR: duplicate key value violates unique constraint "items_pkey"', null],
            'empty rule text'   => ['45000', '', null],
        ];
    }

    public function testPlainExceptionHasNoRuleMessage(): void
    {
        $this->assertNull((new DBException('Missing database name'))->getRuleMessage());
    }
}
