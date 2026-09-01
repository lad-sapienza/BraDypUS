<?php

namespace Tests\Integration;

use PHPUnit\Framework\TestCase;
use DB\DB;
use DB\System\Manage;
use DB\System\Migrations\M035_PluginRelationsFromPluginOf;
use Monolog\Logger;
use Monolog\Handler\NullHandler;

/**
 * Tests for M035_PluginRelationsFromPluginOf.
 *
 * Backfills bdus_cfg_relations from the (at the time this migration runs,
 * still physically present) plugin_of column, so Config\LoadFromDB can derive
 * tables.{parent}.plugin[] from relations instead of scanning plugin_of.
 */
class M035MigrationTest extends TestCase
{
    private static DB     $db;
    private static Manage $manage;

    public static function setUpBeforeClass(): void
    {
        $log = new Logger('test');
        $log->pushHandler(new NullHandler());

        static::$db = new DB('test_m035', ['db_engine' => 'sqlite', 'db_path' => ':memory:']);
        static::$db->setLog($log);
        static::$manage = new Manage(static::$db);
        static::$manage->createTable('bdus_cfg_relations');
    }

    protected function setUp(): void
    {
        static::$db->query('DELETE FROM bdus_cfg_relations', [], 'boolean');
        static::$db->exec('DROP TABLE IF EXISTS bdus_cfg_tables');
        static::$db->exec('DROP TABLE IF EXISTS us');
        static::$db->exec('DROP TABLE IF EXISTS attivita');
    }

    private function insertTable(string $name, int $isPlugin, ?string $pluginOf): void
    {
        static::$db->query(
            'INSERT INTO bdus_cfg_tables (name, is_plugin, plugin_of, sort) VALUES (?, ?, ?, 0)',
            [$name, $isPlugin, $pluginOf],
            'boolean'
        );
    }

    private function migrate(): void
    {
        M035_PluginRelationsFromPluginOf::run(static::$manage);
    }

    public function testBackfillsRelationForPluginTable(): void
    {
        static::$manage->createTable('bdus_cfg_tables');
        static::$db->exec('ALTER TABLE bdus_cfg_tables ADD COLUMN plugin_of TEXT');
        static::$db->exec('CREATE TABLE us (id INTEGER PRIMARY KEY AUTOINCREMENT)');
        static::$db->exec('CREATE TABLE attivita (id INTEGER PRIMARY KEY AUTOINCREMENT)');

        $this->insertTable('us', 0, null);
        $this->insertTable('attivita', 1, 'us');

        $this->migrate();

        $rows = static::$db->query(
            "SELECT * FROM bdus_cfg_relations WHERE from_tb = 'attivita' AND from_col = 'id_link'",
            [], 'read'
        );
        $this->assertCount(1, $rows);
        $this->assertSame('us', $rows[0]['to_tb']);
        $this->assertSame('id', $rows[0]['to_col']);
    }

    public function testSkipsWhenParentTableMissing(): void
    {
        static::$manage->createTable('bdus_cfg_tables');
        static::$db->exec('ALTER TABLE bdus_cfg_tables ADD COLUMN plugin_of TEXT');
        static::$db->exec('CREATE TABLE attivita (id INTEGER PRIMARY KEY AUTOINCREMENT)');
        // 'us' physical table intentionally not created — stale plugin_of.
        $this->insertTable('attivita', 1, 'us');

        $this->migrate(); // must not throw

        $rows = static::$db->query("SELECT * FROM bdus_cfg_relations WHERE from_tb = 'attivita'", [], 'read') ?: [];
        $this->assertCount(0, $rows);
    }

    public function testIdempotent(): void
    {
        static::$manage->createTable('bdus_cfg_tables');
        static::$db->exec('ALTER TABLE bdus_cfg_tables ADD COLUMN plugin_of TEXT');
        static::$db->exec('CREATE TABLE us (id INTEGER PRIMARY KEY AUTOINCREMENT)');
        static::$db->exec('CREATE TABLE attivita (id INTEGER PRIMARY KEY AUTOINCREMENT)');
        $this->insertTable('us', 0, null);
        $this->insertTable('attivita', 1, 'us');

        $this->migrate();
        $this->migrate(); // second run must not duplicate the relation row

        $rows = static::$db->query("SELECT * FROM bdus_cfg_relations WHERE from_tb = 'attivita'", [], 'read');
        $this->assertCount(1, $rows);
    }

    public function testNoOpWhenPluginOfColumnAbsent(): void
    {
        // Current Structure JSON no longer declares plugin_of — this is the
        // fresh-install shape (see M036_DropPluginOfColumn). The migration must
        // not error trying to SELECT a nonexistent column.
        static::$manage->createTable('bdus_cfg_tables');
        $this->assertFalse(static::$manage->columnExists('bdus_cfg_tables', 'plugin_of'));

        $this->migrate(); // must not throw

        $rows = static::$db->query('SELECT * FROM bdus_cfg_relations', [], 'read') ?: [];
        $this->assertCount(0, $rows);
    }

    public function testNoOpWhenCfgTablesMissing(): void
    {
        // bdus_cfg_tables not created in this test at all.
        $this->migrate(); // must not throw
        $this->assertFalse(static::$manage->tableExists('bdus_cfg_tables'));
    }
}
