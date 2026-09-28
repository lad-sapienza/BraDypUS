<?php

declare(strict_types=1);

namespace Tests\Unit;

use Config\ToDB;
use DB\DBInterface;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

/**
 * ToDB::upsertRelations() drops the legacy UNIQUE(from_tb, to_tb) index left by M020.
 *
 * `DROP INDEX IF EXISTS name` is SQLite/PostgreSQL syntax; sent to MySQL/MariaDB
 * it is a syntax error that made every relation save fail there (found while
 * running the API suite against a real MariaDB, issue #74). Only the DDL sent to
 * each engine is checked here — the DB is a recording stub.
 */
class ToDBUpsertRelationsTest extends TestCase
{
    /**
     * @param bool $indexExists  what information_schema reports (only asked on MySQL)
     * @return list<string> every statement passed to DBInterface::exec()
     */
    private function execCalls(string $engine, bool $indexExists): array
    {
        $exec = [];

        $db = $this->createStub(DBInterface::class);
        $db->method('getEngine')->willReturn($engine);
        $db->method('query')->willReturnCallback(
            static fn (string $sql) => str_contains($sql, 'information_schema')
                ? [['cnt' => $indexExists ? 1 : 0]]
                : []
        );
        $db->method('exec')->willReturnCallback(
            static function (string $sql) use (&$exec): bool {
                $exec[] = $sql;
                return true;
            }
        );

        ToDB::upsertRelations($db, 'complessi', []);

        return $exec;
    }

    #[DataProvider('plainDropEngines')]
    public function testSqliteAndPostgresKeepTheIfExistsForm(string $engine): void
    {
        $this->assertSame(
            ['DROP INDEX IF EXISTS cfg_rel_unique_pair'],
            $this->execCalls($engine, false)
        );
    }

    public static function plainDropEngines(): array
    {
        return ['sqlite' => ['sqlite'], 'pgsql' => ['pgsql']];
    }

    public function testMysqlDropsTheIndexWithOnTableWhenItExists(): void
    {
        $this->assertSame(
            ['DROP INDEX cfg_rel_unique_pair ON bdus_cfg_relations'],
            $this->execCalls('mysql', true)
        );
    }

    public function testMysqlDoesNothingWhenTheIndexIsAbsent(): void
    {
        // and in particular never sends the `IF EXISTS` form MySQL/MariaDB rejects
        $this->assertSame([], $this->execCalls('mysql', false));
    }
}
