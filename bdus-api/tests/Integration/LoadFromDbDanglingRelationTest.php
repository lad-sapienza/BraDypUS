<?php

namespace Tests\Integration;

use PHPUnit\Framework\TestCase;
use DB\DB;
use DB\System\Manage;
use Config\Config;
use Config\ToDB;
use Adbar\Dot;
use Monolog\Logger;
use Monolog\Handler\NullHandler;

/**
 * Covers #48 Bug A at the config-load layer: a bdus_cfg_relations row whose
 * from_tb / to_tb is not a real configured table — the classic case being a
 * `geodata → places` row left behind by an incomplete v4→v5 upgrade — must not
 * be turned into tables.{tb}.link[]. If it were, Record\Read::getLinks() would
 * run `SELECT count(id) FROM geodata …` and abort every `places` record read.
 *
 * M043_DropDanglingCfgRelations removes such rows for good; this test targets
 * the in-memory guard in Config\LoadFromDB::tables() that also holds before the
 * migration runs (or if a row is re-inserted by hand).
 */
class LoadFromDbDanglingRelationTest extends TestCase
{
    private static Config $cfg;

    public static function setUpBeforeClass(): void
    {
        $log = new Logger('test');
        $log->pushHandler(new NullHandler());

        $db = new DB('test_dangling_rel', ['db_engine' => 'sqlite', 'db_path' => ':memory:']);
        $db->setLog($log);

        $manage = new Manage($db);
        $manage->createTable('bdus_cfg_tables');
        $manage->createTable('bdus_cfg_fields');
        $manage->createTable('bdus_cfg_relations');

        // Two real, configured tables and one legitimate FK between them.
        $db->exec('CREATE TABLE places (id INTEGER PRIMARY KEY AUTOINCREMENT)');
        $db->exec('CREATE TABLE finds  (id INTEGER PRIMARY KEY AUTOINCREMENT, place_id INTEGER)');
        ToDB::upsertTable($db, ['name' => 'places', 'label' => 'Places']);
        ToDB::upsertTable($db, ['name' => 'finds',  'label' => 'Finds']);
        $db->query(
            'INSERT INTO bdus_cfg_relations (from_tb, from_col, to_tb, to_col, on_delete, on_update)
             VALUES (?,?,?,?,?,?)',
            ['finds', 'place_id', 'places', 'id', 'RESTRICT', 'CASCADE'],
            'boolean'
        );

        // The dangling row: `geodata` is neither a physical table nor a
        // bdus_cfg_tables entry (mirrors the migrated-PAThs scenario).
        $db->query(
            'INSERT INTO bdus_cfg_relations (from_tb, from_col, to_tb, to_col, on_delete, on_update)
             VALUES (?,?,?,?,?,?)',
            ['geodata', 'id_link', 'places', 'id', 'RESTRICT', 'CASCADE'],
            'boolean'
        );

        static::$cfg = new Config(new Dot(), __DIR__ . '/../fixtures/cfg/', $db);
    }

    public function testDanglingRelationIsNotListedAsLinkOnTheTargetTable(): void
    {
        $link = static::$cfg->get('tables.places.link') ?: [];
        $this->assertNotContains(
            'geodata',
            array_column($link, 'other_tb'),
            'a relation whose endpoint is not a configured table must be dropped, not surfaced as a link'
        );
    }

    public function testLegitimateReverseLinkIsStillDerived(): void
    {
        // The healthy `finds.place_id → places.id` row must still produce the
        // reverse link on `places` — the guard drops only the bad row.
        $link = static::$cfg->get('tables.places.link') ?: [];
        $this->assertContains('finds', array_column($link, 'other_tb'));
    }

    public function testForwardLinkOnTheFkHolderIsUnaffected(): void
    {
        $link = static::$cfg->get('tables.finds.link') ?: [];
        $this->assertContains('places', array_column($link, 'other_tb'));
    }
}
