<?php

namespace Tests\Integration;

use PHPUnit\Framework\TestCase;
use DB\DB;
use DB\System\Manage;
use DB\System\Migrations\M013_CreateCfgRelations;
use Monolog\Logger;
use Monolog\Handler\NullHandler;

/**
 * Tests the M013_CreateCfgRelations migration.
 *
 * Scenarios:
 *   A. Fresh DB with no bdus_cfg_tables → migration creates the table and exits.
 *   B. DB with bdus_cfg_tables populated and legacy links JSON blobs →
 *      migration creates bdus_cfg_relations and migrates data.
 *   C. Running again on already-migrated DB → idempotent no-op.
 */
class M013MigrationTest extends TestCase
{
    private static Logger $log;

    public static function setUpBeforeClass(): void
    {
        $log = new Logger('test');
        $log->pushHandler(new NullHandler());
        static::$log = $log;
    }

    // ── Helper ────────────────────────────────────────────────────────────────

    private function freshDb(): array
    {
        $db     = new DB('test', ['db_engine' => 'sqlite', 'db_path' => ':memory:']);
        $db->setLog(static::$log);
        $manage = new Manage($db);
        return [$db, $manage];
    }

    // ── Scenario A: no bdus_cfg_tables ────────────────────────────────────────

    public function testRunOnFreshDbCreatesTableAndSucceeds(): void
    {
        [$db, $manage] = $this->freshDb();
        // No bdus_cfg_tables yet — migration should just create the table.
        M013_CreateCfgRelations::run($manage);

        $rows = $db->query(
            "SELECT name FROM sqlite_master WHERE type='table' AND name='bdus_cfg_relations'",
            [], 'read'
        );
        $this->assertNotEmpty($rows);

        // No relations rows expected.
        $cnt = $db->query('SELECT COUNT(*) AS cnt FROM bdus_cfg_relations', [], 'read');
        $this->assertSame(0, (int)$cnt[0]['cnt']);
    }

    // ── Scenario B: legacy links blobs ───────────────────────────────────────

    public function testRunMigratesLinksBlobsToRelationRows(): void
    {
        [$db, $manage] = $this->freshDb();

        // Seed bdus_cfg_tables with a legacy links blob.
        $manage->createTable('bdus_cfg_tables');
        $db->query(
            "INSERT INTO bdus_cfg_tables
                (name, label, order_field, id_field, preview, is_plugin, plugin_of, sort, links, backlinks)
             VALUES ('items','Items','id','id','[\"id\"]',0,NULL,0,?,NULL)",
            [json_encode([
                ['other_tb' => 'tags',    'fld' => [['my' => 'id', 'other' => 'item_id']]],
                ['other_tb' => 'periods', 'fld' => [['my' => 'period', 'other' => 'name']]],
            ])],
            'boolean'
        );
        // Second table with no links.
        $db->query(
            "INSERT INTO bdus_cfg_tables
                (name, label, order_field, id_field, preview, is_plugin, plugin_of, sort, links, backlinks)
             VALUES ('tags','Tags','id','id','[\"id\"]',1,NULL,1,NULL,NULL)",
            [], 'boolean'
        );

        M013_CreateCfgRelations::run($manage);

        // Should have 2 relation rows for 'items'.
        $rows = $db->query(
            'SELECT * FROM bdus_cfg_relations WHERE from_tb=? ORDER BY sort',
            ['items'],
            'read'
        );
        $this->assertCount(2, $rows);
        $this->assertSame('tags',    $rows[0]['to_tb']);
        $this->assertSame('periods', $rows[1]['to_tb']);

        // Field mapping preserved.
        $fld0 = json_decode($rows[0]['fld'], true);
        $this->assertSame('id',      $fld0[0]['my']);
        $this->assertSame('item_id', $fld0[0]['other']);

        // 'tags' has no links — no rows.
        $tagsRows = $db->query(
            'SELECT * FROM bdus_cfg_relations WHERE from_tb=?',
            ['tags'],
            'read'
        ) ?: [];
        $this->assertCount(0, $tagsRows);
    }

    public function testRunSkipsNullAndEmptyLinkBlobs(): void
    {
        [$db, $manage] = $this->freshDb();

        $manage->createTable('bdus_cfg_tables');
        // links is NULL
        $db->query(
            "INSERT INTO bdus_cfg_tables (name, label, order_field, id_field, preview, is_plugin, sort)
             VALUES ('a','A','id','id','[]',0,0)",
            [], 'boolean'
        );
        // links is empty string
        $db->query(
            "INSERT INTO bdus_cfg_tables (name, label, order_field, id_field, preview, is_plugin, sort, links)
             VALUES ('b','B','id','id','[]',0,1,'')",
            [], 'boolean'
        );

        M013_CreateCfgRelations::run($manage);

        $cnt = $db->query('SELECT COUNT(*) AS cnt FROM bdus_cfg_relations', [], 'read');
        $this->assertSame(0, (int)$cnt[0]['cnt']);
    }

    // ── Scenario C: idempotency ───────────────────────────────────────────────

    public function testRunIsIdempotent(): void
    {
        [$db, $manage] = $this->freshDb();

        $manage->createTable('bdus_cfg_tables');
        $db->query(
            "INSERT INTO bdus_cfg_tables (name, label, order_field, id_field, preview, is_plugin, sort, links)
             VALUES ('items','Items','id','id','[]',0,0,?)",
            [json_encode([['other_tb' => 'tags', 'fld' => []]])],
            'boolean'
        );

        M013_CreateCfgRelations::run($manage);

        $before = (int)$db->query('SELECT COUNT(*) AS cnt FROM bdus_cfg_relations', [], 'read')[0]['cnt'];

        // Run again — should be a no-op.
        M013_CreateCfgRelations::run($manage);

        $after = (int)$db->query('SELECT COUNT(*) AS cnt FROM bdus_cfg_relations', [], 'read')[0]['cnt'];
        $this->assertSame($before, $after);
    }
}
