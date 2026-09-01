<?php

namespace Tests\Integration;

use PHPUnit\Framework\TestCase;
use DB\DB;
use DB\System\Manage;
use DB\System\Migrations\M036_DropPluginOfColumn;
use Monolog\Logger;
use Monolog\Handler\NullHandler;

/**
 * Tests for M036_DropPluginOfColumn.
 *
 * plugin_of stopped being read by any code in authority once
 * M035_PluginRelationsFromPluginOf made bdus_cfg_relations the source of
 * truth (see the plugin-architecture rethink). This migration removes the
 * now-dead physical column.
 */
class M036MigrationTest extends TestCase
{
    private static DB     $db;
    private static Manage $manage;

    public static function setUpBeforeClass(): void
    {
        $log = new Logger('test');
        $log->pushHandler(new NullHandler());

        static::$db = new DB('test_m036', ['db_engine' => 'sqlite', 'db_path' => ':memory:']);
        static::$db->setLog($log);
        static::$manage = new Manage(static::$db);
    }

    protected function setUp(): void
    {
        static::$db->exec('DROP TABLE IF EXISTS bdus_cfg_tables');
    }

    private function migrate(): void
    {
        M036_DropPluginOfColumn::run(static::$manage);
    }

    public function testDropsColumnWhenPresent(): void
    {
        static::$manage->createTable('bdus_cfg_tables');
        static::$db->exec('ALTER TABLE bdus_cfg_tables ADD COLUMN plugin_of TEXT');
        $this->assertTrue(static::$manage->columnExists('bdus_cfg_tables', 'plugin_of'));

        $this->migrate();

        $this->assertFalse(static::$manage->columnExists('bdus_cfg_tables', 'plugin_of'));
    }

    public function testPreservesOtherData(): void
    {
        static::$manage->createTable('bdus_cfg_tables');
        static::$db->exec('ALTER TABLE bdus_cfg_tables ADD COLUMN plugin_of TEXT');
        static::$db->query(
            'INSERT INTO bdus_cfg_tables (name, is_plugin, plugin_of, sort) VALUES (?, ?, ?, ?)',
            ['tags', 1, 'items', 0],
            'boolean'
        );

        $this->migrate();

        $row = static::$db->query('SELECT name, is_plugin, sort FROM bdus_cfg_tables WHERE name = ?', ['tags'], 'read');
        $this->assertSame('tags', $row[0]['name']);
        $this->assertSame(1, (int) $row[0]['is_plugin']);
    }

    public function testNoOpWhenColumnAlreadyAbsent(): void
    {
        // Current Structure JSON no longer declares plugin_of — this is the
        // fresh-install shape.
        static::$manage->createTable('bdus_cfg_tables');
        $this->assertFalse(static::$manage->columnExists('bdus_cfg_tables', 'plugin_of'));

        $this->migrate(); // must not throw

        $this->assertFalse(static::$manage->columnExists('bdus_cfg_tables', 'plugin_of'));
    }

    public function testNoOpWhenTableMissing(): void
    {
        // bdus_cfg_tables dropped in setUp() and never recreated in this test.
        $this->migrate(); // must not throw
        $this->assertFalse(static::$manage->tableExists('bdus_cfg_tables'));
    }

    public function testIdempotent(): void
    {
        static::$manage->createTable('bdus_cfg_tables');
        static::$db->exec('ALTER TABLE bdus_cfg_tables ADD COLUMN plugin_of TEXT');

        $this->migrate();
        $this->migrate(); // second run must not error

        $this->assertFalse(static::$manage->columnExists('bdus_cfg_tables', 'plugin_of'));
    }
}
