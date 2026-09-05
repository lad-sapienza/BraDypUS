<?php

namespace Tests\Integration;

use PHPUnit\Framework\TestCase;
use DB\DB;
use DB\Inspect\Postgres as PostgresInspect;
use Monolog\Logger;
use Monolog\Handler\NullHandler;

/**
 * Regression test: DB\Inspect\Postgres::tableExists()/tableColumns() must be
 * scoped to the connection's own schema. Needs a real PostgreSQL server —
 * SQLite has no schema concept to reproduce the bug with — so it only runs
 * under `test.sh --unit --db=pgsql` (docker-compose.test.pg.yml exposes a
 * "postgres" host with these exact credentials) and skips itself otherwise.
 */
class PostgresInspectSchemaTest extends TestCase
{
    private static ?DB $db = null;
    private static ?PostgresInspect $inspect = null;

    public static function setUpBeforeClass(): void
    {
        $log = new Logger('test');
        $log->pushHandler(new NullHandler());

        try {
            static::$db = new DB('pg_inspect_schema_test', [
                'db_engine'   => 'pgsql',
                'db_host'     => 'postgres',
                'db_port'     => 5432,
                'db_name'     => 'bdus_test',
                'db_username' => 'bdus',
                'db_password' => 'bdus_test_pw',
            ]);
            static::$db->setLog($log);
            static::$inspect = new PostgresInspect(static::$db);
        } catch (\Throwable $e) {
            static::$db = null;
        }
    }

    protected function setUp(): void
    {
        if (static::$db === null) {
            $this->markTestSkipped('No live PostgreSQL server reachable — run via test.sh --unit --db=pgsql');
        }
        static::$db->exec('DROP TABLE IF EXISTS "shadow_probe"');
        static::$db->exec('DROP SCHEMA IF EXISTS shadow_schema CASCADE');
        static::$db->exec('CREATE SCHEMA shadow_schema');
    }

    protected function tearDown(): void
    {
        if (static::$db === null) {
            return;
        }
        static::$db->exec('DROP TABLE IF EXISTS "shadow_probe"');
        static::$db->exec('DROP SCHEMA IF EXISTS shadow_schema CASCADE');
    }

    public function testTableExistsIgnoresSameNameTableInAnotherSchema(): void
    {
        static::$db->exec('CREATE TABLE shadow_schema.shadow_probe (id SERIAL PRIMARY KEY)');

        $this->assertFalse(
            static::$inspect->tableExists('shadow_probe'),
            'tableExists() must not see a table with the same name living in a different schema'
        );
    }

    public function testTableExistsFindsOwnSchemaTableAlongsideAnother(): void
    {
        static::$db->exec('CREATE TABLE shadow_schema.shadow_probe (id SERIAL PRIMARY KEY)');
        static::$db->exec('CREATE TABLE "shadow_probe" (id SERIAL PRIMARY KEY)');

        $this->assertTrue(static::$inspect->tableExists('shadow_probe'));
    }

    public function testTableColumnsIgnoresSameNameTableInAnotherSchema(): void
    {
        static::$db->exec('CREATE TABLE shadow_schema.shadow_probe (id SERIAL PRIMARY KEY, only_in_other_schema TEXT)');

        $this->expectException(\Exception::class);
        static::$inspect->tableColumns('shadow_probe');
    }
}
