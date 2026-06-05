<?php

namespace Tests\Integration;

use PHPUnit\Framework\TestCase;
use DB\DB;
use DB\System\Manage;
use DB\System\Migrations\M032_CreatorFkNullable;
use Monolog\Logger;
use Monolog\Handler\NullHandler;

/**
 * Tests for M032_CreatorFkNullable.
 *
 * M032 skips SQLite entirely (cannot add FK constraints to existing
 * tables without full recreation).  These tests verify:
 *  - The migration is a strict no-op on SQLite (does not touch any table)
 *  - Guard conditions return cleanly when bdus_cfg_tables or bdus_users
 *    are absent, or when bdus_cfg_tables has no rows
 *
 * MySQL / PostgreSQL behaviour (nullable column + FK constraint) is
 * exercised by the multi-engine integration suite (test.sh --all-engines).
 */
class M032MigrationTest extends TestCase
{
    private static DB     $db;
    private static Manage $manage;

    public static function setUpBeforeClass(): void
    {
        $log = new Logger('test');
        $log->pushHandler(new NullHandler());

        static::$db = new DB('test_m032', ['db_engine' => 'sqlite', 'db_path' => ':memory:']);
        static::$db->setLog($log);
        static::$manage = new Manage(static::$db);
    }

    protected function setUp(): void
    {
        static::$db->exec('DROP TABLE IF EXISTS "testapp__items"');
        static::$db->exec('DROP TABLE IF EXISTS bdus_cfg_tables');
        static::$db->exec('DROP TABLE IF EXISTS bdus_users');
    }

    // ── SQLite: no-op guard ───────────────────────────────────────────────────

    /**
     * On SQLite the migration returns immediately; the user data table must be
     * untouched — creator stays NOT NULL, no FK is added.
     */
    public function testNoopOnSqliteDoesNotAlterExistingTable(): void
    {
        static::$db->exec(
            'CREATE TABLE bdus_users (id INTEGER PRIMARY KEY, email TEXT NOT NULL, password TEXT NOT NULL)'
        );
        static::$db->exec(
            'CREATE TABLE bdus_cfg_tables (id INTEGER PRIMARY KEY, name TEXT NOT NULL, is_plugin INTEGER)'
        );
        static::$db->exec(
            'CREATE TABLE "testapp__items" (id INTEGER PRIMARY KEY AUTOINCREMENT, creator INTEGER NOT NULL)'
        );
        static::$db->exec(
            "INSERT INTO bdus_cfg_tables (name, is_plugin) VALUES ('testapp__items', 0)"
        );
        static::$db->exec(
            "INSERT INTO bdus_users (id, email, password) VALUES (99, 'u@test.com', 'hash')"
        );
        static::$db->exec("INSERT INTO \"testapp__items\" (creator) VALUES (99)");

        M032_CreatorFkNullable::run(static::$manage);

        // Table must not have been touched on SQLite
        $cols    = static::$db->query("PRAGMA table_info(\"testapp__items\")", [], 'read') ?: [];
        $creator = current(array_filter($cols, fn($c) => $c['name'] === 'creator'));

        $this->assertNotFalse($creator, 'creator column must still exist after no-op');
        $this->assertSame(1, (int)$creator['notnull'], 'NOT NULL must be intact on SQLite (table not touched)');

        $fks = static::$db->query("PRAGMA foreign_key_list(\"testapp__items\")", [], 'read') ?: [];
        $this->assertEmpty($fks, 'No FK must have been added on SQLite');
    }

    // ── Guard conditions ──────────────────────────────────────────────────────

    public function testNoopWhenBothSystemTablesMissing(): void
    {
        M032_CreatorFkNullable::run(static::$manage);
        $this->assertTrue(true, 'Must not throw when neither system table exists');
    }

    public function testNoopWhenCfgTablesMissing(): void
    {
        static::$db->exec(
            'CREATE TABLE bdus_users (id INTEGER PRIMARY KEY, email TEXT NOT NULL, password TEXT NOT NULL)'
        );
        M032_CreatorFkNullable::run(static::$manage);
        $this->assertTrue(true, 'Must not throw when bdus_cfg_tables is absent');
    }

    public function testNoopWhenBdusUsersMissing(): void
    {
        static::$db->exec(
            'CREATE TABLE bdus_cfg_tables (id INTEGER PRIMARY KEY, name TEXT NOT NULL, is_plugin INTEGER)'
        );
        M032_CreatorFkNullable::run(static::$manage);
        $this->assertTrue(true, 'Must not throw when bdus_users is absent');
    }

    public function testNoopWhenCfgTablesHasNoRows(): void
    {
        static::$db->exec(
            'CREATE TABLE bdus_users (id INTEGER PRIMARY KEY, email TEXT NOT NULL, password TEXT NOT NULL)'
        );
        static::$db->exec(
            'CREATE TABLE bdus_cfg_tables (id INTEGER PRIMARY KEY, name TEXT NOT NULL, is_plugin INTEGER)'
        );
        // No rows in bdus_cfg_tables → no tables to migrate
        M032_CreatorFkNullable::run(static::$manage);
        $this->assertTrue(true, 'Must not throw when bdus_cfg_tables is empty');
    }
}
