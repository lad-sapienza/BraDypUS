<?php

namespace Tests\Integration;

use PHPUnit\Framework\TestCase;
use DB\DB;
use DB\System\Manage;
use DB\System\Migrations\M038_DropTableLinkFromPlugins;
use Monolog\Logger;
use Monolog\Handler\NullHandler;

/**
 * Tests for M038_DropTableLinkFromPlugins.
 *
 * Since M037_SplitMultiTenantPluginData every plugin table has exactly one
 * physical parent, so table_link is a constant, redundant value on every
 * row — the real attachment lives in bdus_cfg_relations. All CRUD/search
 * code paths were updated to rely on id_link alone; this migration drops
 * the now-dead physical column (and its bdus_cfg_fields row) from every
 * is_plugin table, except bdus_geodata, which stays genuinely multi-tenant
 * and out of scope.
 */
class M038MigrationTest extends TestCase
{
    private static DB     $db;
    private static Manage $manage;

    public static function setUpBeforeClass(): void
    {
        $log = new Logger('test');
        $log->pushHandler(new NullHandler());

        static::$db = new DB('test_m038', ['db_engine' => 'sqlite', 'db_path' => ':memory:']);
        static::$db->setLog($log);
        static::$manage = new Manage(static::$db);
        static::$manage->createTable('bdus_cfg_tables');
        static::$manage->createTable('bdus_cfg_fields');
    }

    protected function setUp(): void
    {
        static::$db->query('DELETE FROM bdus_cfg_tables', [], 'boolean');
        static::$db->query('DELETE FROM bdus_cfg_fields', [], 'boolean');
        foreach (['tags', 'bdus_geodata'] as $tb) {
            static::$db->exec("DROP TABLE IF EXISTS \"{$tb}\"");
        }
    }

    // ── helpers ───────────────────────────────────────────────────────────────

    private function insertTable(string $name, int $isPlugin): void
    {
        static::$db->query(
            'INSERT INTO bdus_cfg_tables (name, is_plugin, sort) VALUES (?, ?, 0)',
            [$name, $isPlugin],
            'boolean'
        );
    }

    private function insertField(string $tb, string $name): void
    {
        static::$db->query(
            'INSERT INTO bdus_cfg_fields (table_name, name, label, sort) VALUES (?, ?, ?, 0)',
            [$tb, $name, ucfirst($name)],
            'boolean'
        );
    }

    private function migrate(): void
    {
        M038_DropTableLinkFromPlugins::run(static::$manage);
    }

    // ── tests ─────────────────────────────────────────────────────────────────

    public function testDropsColumnAndFieldRowFromPluginTable(): void
    {
        static::$db->execInTransaction(
            'CREATE TABLE tags (
                id         INTEGER PRIMARY KEY AUTOINCREMENT,
                table_link TEXT NOT NULL,
                id_link    INTEGER NOT NULL,
                label      TEXT
            )'
        );
        static::$db->execInTransaction("INSERT INTO tags (table_link, id_link, label) VALUES ('items', 1, 'tag-a')");
        $this->insertTable('tags', 1);
        $this->insertField('tags', 'table_link');
        $this->insertField('tags', 'id_link');
        $this->insertField('tags', 'label');

        $this->migrate();

        $this->assertFalse(static::$manage->columnExists('tags', 'table_link'));
        $this->assertTrue(static::$manage->columnExists('tags', 'id_link'));

        $fieldNames = array_column(
            static::$db->query('SELECT name FROM bdus_cfg_fields WHERE table_name = ?', ['tags'], 'read'),
            'name'
        );
        $this->assertEqualsCanonicalizing(['id_link', 'label'], $fieldNames);

        // Data preserved.
        $rows = static::$db->query('SELECT id_link, label FROM tags', [], 'read');
        $this->assertCount(1, $rows);
        $this->assertSame('tag-a', $rows[0]['label']);
    }

    public function testLeavesBdusGeodataUntouched(): void
    {
        static::$db->execInTransaction(
            'CREATE TABLE bdus_geodata (
                id         INTEGER PRIMARY KEY AUTOINCREMENT,
                table_link TEXT NOT NULL,
                id_link    INTEGER NOT NULL,
                geometry   TEXT
            )'
        );
        $this->insertTable('bdus_geodata', 1);
        $this->insertField('bdus_geodata', 'table_link');
        $this->insertField('bdus_geodata', 'id_link');

        $this->migrate();

        $this->assertTrue(static::$manage->columnExists('bdus_geodata', 'table_link'));
        $fieldNames = array_column(
            static::$db->query('SELECT name FROM bdus_cfg_fields WHERE table_name = ?', ['bdus_geodata'], 'read'),
            'name'
        );
        $this->assertEqualsCanonicalizing(['table_link', 'id_link'], $fieldNames);
    }

    public function testNoOpWhenColumnAlreadyAbsent(): void
    {
        static::$db->execInTransaction(
            'CREATE TABLE tags (
                id      INTEGER PRIMARY KEY AUTOINCREMENT,
                id_link INTEGER NOT NULL,
                label   TEXT
            )'
        );
        $this->insertTable('tags', 1);
        $this->insertField('tags', 'id_link');
        $this->insertField('tags', 'label');

        $this->migrate(); // must not throw

        $fieldNames = array_column(
            static::$db->query('SELECT name FROM bdus_cfg_fields WHERE table_name = ?', ['tags'], 'read'),
            'name'
        );
        $this->assertEqualsCanonicalizing(['id_link', 'label'], $fieldNames);
    }

    public function testIsIdempotent(): void
    {
        static::$db->execInTransaction(
            'CREATE TABLE tags (
                id         INTEGER PRIMARY KEY AUTOINCREMENT,
                table_link TEXT NOT NULL,
                id_link    INTEGER NOT NULL
            )'
        );
        $this->insertTable('tags', 1);
        $this->insertField('tags', 'table_link');
        $this->insertField('tags', 'id_link');

        $this->migrate();
        $this->migrate(); // second run must not error

        $this->assertFalse(static::$manage->columnExists('tags', 'table_link'));
    }

    public function testNoOpWhenCfgTablesMissing(): void
    {
        static::$db->exec('DROP TABLE bdus_cfg_tables');

        $this->migrate(); // must not throw

        $this->assertFalse(static::$manage->tableExists('bdus_cfg_tables'));

        static::$manage->createTable('bdus_cfg_tables'); // restore for subsequent tests
    }
}
