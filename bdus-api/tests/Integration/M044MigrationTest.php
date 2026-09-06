<?php

namespace Tests\Integration;

use PHPUnit\Framework\TestCase;
use DB\DB;
use DB\System\Manage;
use DB\System\Migrations\M044_DeriveGeodataFlag;
use Monolog\Logger;
use Monolog\Handler\NullHandler;

/**
 * Tests for M044_DeriveGeodataFlag.
 *
 * A v4→v5 upgrade fills bdus_geodata but never sets bdus_cfg_tables.extra.geodata,
 * so Record\Read (which gates geometry on tables.{tb}.geodata) always returns
 * `geodata: []` for a migrated app even though the map view works. This migration
 * backfills the flag for every table that has geometry rows, merging into `extra`
 * without disturbing other keys.
 */
class M044MigrationTest extends TestCase
{
    private static DB     $db;
    private static Manage $manage;

    public static function setUpBeforeClass(): void
    {
        $log = new Logger('test');
        $log->pushHandler(new NullHandler());

        static::$db = new DB('test_m044', ['db_engine' => 'sqlite', 'db_path' => ':memory:']);
        static::$db->setLog($log);
        static::$manage = new Manage(static::$db);
    }

    protected function setUp(): void
    {
        static::$db->exec('DROP TABLE IF EXISTS bdus_geodata');
        static::$db->exec('DROP TABLE IF EXISTS bdus_cfg_tables');
        static::$manage->createTable('bdus_geodata');
        static::$manage->createTable('bdus_cfg_tables');
    }

    private function cfgTable(string $name, ?string $extra): void
    {
        static::$db->query(
            'INSERT INTO bdus_cfg_tables (name, is_plugin, sort, extra) VALUES (?, 0, 0, ?)',
            [$name, $extra],
            'boolean'
        );
    }

    private function geometry(string $tableLink, int $idLink): void
    {
        static::$db->query(
            'INSERT INTO bdus_geodata (table_link, id_link, geometry) VALUES (?, ?, ?)',
            [$tableLink, $idLink, 'POINT(0 0)'],
            'boolean'
        );
    }

    private function extraOf(string $name): array
    {
        $rows = static::$db->query('SELECT extra FROM bdus_cfg_tables WHERE name = ?', [$name], 'read') ?: [];
        return json_decode((string) ($rows[0]['extra'] ?? ''), true) ?: [];
    }

    private function migrate(): void
    {
        M044_DeriveGeodataFlag::run(static::$manage);
    }

    public function testAddsFlagAndPreservesOtherExtraKeys(): void
    {
        $this->cfgTable('places', json_encode(['backlinks' => ['x'], 'zotero' => '1']));
        $this->geometry('places', 1);

        $this->migrate();

        $extra = $this->extraOf('places');
        $this->assertSame('1', $extra['geodata']);
        $this->assertSame('1', $extra['zotero']);
        $this->assertSame(['x'], $extra['backlinks']);
    }

    public function testAddsFlagWhenExtraIsNull(): void
    {
        $this->cfgTable('places', null);
        $this->geometry('places', 1);

        $this->migrate();

        $this->assertSame('1', $this->extraOf('places')['geodata']);
    }

    public function testFlagsEveryDistinctTableLink(): void
    {
        $this->cfgTable('places', null);
        $this->cfgTable('graves', null);
        $this->geometry('places', 1);
        $this->geometry('places', 2);
        $this->geometry('graves', 1);

        $this->migrate();

        $this->assertSame('1', $this->extraOf('places')['geodata']);
        $this->assertSame('1', $this->extraOf('graves')['geodata']);
    }

    public function testAlreadyFlaggedRowIsLeftUntouched(): void
    {
        $this->cfgTable('places', json_encode(['geodata' => '1', 'note' => 'keep']));
        $this->geometry('places', 1);

        $this->migrate();

        $extra = $this->extraOf('places');
        $this->assertSame('1', $extra['geodata']);
        $this->assertSame('keep', $extra['note']);
    }

    public function testTableLinkWithoutConfigRowIsSkipped(): void
    {
        // geometry rows for a table that has no bdus_cfg_tables entry — nothing
        // to flag, and no error.
        $this->geometry('ghost', 1);

        $this->migrate();

        $this->assertSame([], static::$db->query('SELECT * FROM bdus_cfg_tables', [], 'read') ?: []);
    }

    public function testIsIdempotent(): void
    {
        $this->cfgTable('places', json_encode(['zotero' => '1']));
        $this->geometry('places', 1);

        $this->migrate();
        $this->migrate();

        $extra = $this->extraOf('places');
        $this->assertSame('1', $extra['geodata']);
        $this->assertSame('1', $extra['zotero']);
    }

    public function testNoGeodataTableIsSafe(): void
    {
        static::$db->exec('DROP TABLE IF EXISTS bdus_geodata');
        $this->migrate(); // must not throw
        $this->assertFalse(static::$manage->tableExists('bdus_geodata'));
    }
}
