<?php

namespace Tests\Integration;

use PHPUnit\Framework\TestCase;
use DB\DB;
use DB\System\Manage;
use DB\System\Migrations\M043_DropDanglingCfgRelations;
use Monolog\Logger;
use Monolog\Handler\NullHandler;

/**
 * Tests for M043_DropDanglingCfgRelations.
 *
 * A v4→v5 upgrade of an app that once had a per-app `geodata` table leaves a
 * relation row `geodata → places` in bdus_cfg_relations (imported verbatim by
 * M011). `geodata` is not a real table post-M037/M038, so Config\LoadFromDB
 * turns it into tables.places.link[] and Record\Read::getLinks() then queries a
 * non-existent table, aborting every `places` record read. This migration
 * removes any relation row whose from_tb/to_tb is not a real, non-system table.
 */
class M043MigrationTest extends TestCase
{
    private static DB     $db;
    private static Manage $manage;

    public static function setUpBeforeClass(): void
    {
        $log = new Logger('test');
        $log->pushHandler(new NullHandler());

        static::$db = new DB('test_m043', ['db_engine' => 'sqlite', 'db_path' => ':memory:']);
        static::$db->setLog($log);
        static::$manage = new Manage(static::$db);
    }

    protected function setUp(): void
    {
        static::$db->exec('DROP TABLE IF EXISTS bdus_cfg_relations');
        static::$db->exec('DROP TABLE IF EXISTS places');
        static::$db->exec('DROP TABLE IF EXISTS finds');
        static::$manage->createTable('bdus_cfg_relations');
        static::$db->exec('CREATE TABLE places (id INTEGER PRIMARY KEY AUTOINCREMENT)');
        static::$db->exec('CREATE TABLE finds  (id INTEGER PRIMARY KEY AUTOINCREMENT, place_id INTEGER)');
    }

    private function insertRelation(string $from, string $fromCol, string $to, string $toCol): void
    {
        static::$db->query(
            'INSERT INTO bdus_cfg_relations (from_tb, from_col, to_tb, to_col, on_delete, on_update)
             VALUES (?,?,?,?,?,?)',
            [$from, $fromCol, $to, $toCol, 'RESTRICT', 'CASCADE'],
            'boolean'
        );
    }

    private function relationRows(): array
    {
        return static::$db->query('SELECT from_tb, to_tb FROM bdus_cfg_relations ORDER BY id', [], 'read') ?: [];
    }

    private function migrate(): void
    {
        M043_DropDanglingCfgRelations::run(static::$manage);
    }

    public function testRemovesRowWhoseFromTableDoesNotExist(): void
    {
        $this->insertRelation('geodata', 'id_link', 'places', 'id'); // dangling — the #48 row
        $this->insertRelation('finds', 'place_id', 'places', 'id');  // legitimate

        $this->migrate();

        $rows = $this->relationRows();
        $this->assertCount(1, $rows);
        $this->assertSame('finds', $rows[0]['from_tb']);
        $this->assertSame('places', $rows[0]['to_tb']);
    }

    public function testRemovesRowWhoseToTableDoesNotExist(): void
    {
        $this->insertRelation('finds', 'ghost_ref', 'ghost_tbl', 'id');
        $this->insertRelation('finds', 'place_id', 'places', 'id');

        $this->migrate();

        $rows = $this->relationRows();
        $this->assertCount(1, $rows);
        $this->assertSame('places', $rows[0]['to_tb']);
    }

    public function testRemovesRowPointingAtSystemTable(): void
    {
        // bdus_geodata physically exists, but a bdus_* table must never be a
        // configurable relation endpoint.
        static::$manage->createTable('bdus_geodata');
        $this->insertRelation('places', 'geo_ref', 'bdus_geodata', 'id');
        $this->insertRelation('finds', 'place_id', 'places', 'id');

        $this->migrate();

        $rows = $this->relationRows();
        $this->assertCount(1, $rows);
        $this->assertSame('finds', $rows[0]['from_tb']);
    }

    public function testKeepsAllRowsWhenEverythingResolves(): void
    {
        $this->insertRelation('finds', 'place_id', 'places', 'id');
        $this->insertRelation('places', 'self_ref', 'places', 'id');

        $this->migrate();

        $this->assertCount(2, $this->relationRows());
    }

    public function testIsIdempotent(): void
    {
        $this->insertRelation('geodata', 'id_link', 'places', 'id');
        $this->insertRelation('finds', 'place_id', 'places', 'id');

        $this->migrate();
        $this->migrate(); // second run must be a no-op, not an error

        $this->assertCount(1, $this->relationRows());
    }

    public function testNoRelationsTableIsSafe(): void
    {
        static::$db->exec('DROP TABLE IF EXISTS bdus_cfg_relations');
        $this->migrate(); // must not throw
        $this->assertFalse(static::$manage->tableExists('bdus_cfg_relations'));
    }
}
