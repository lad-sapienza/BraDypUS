<?php

namespace Tests\Integration;

use PHPUnit\Framework\TestCase;
use DB\DB;
use DB\System\Manage;
use Config\Config;
use Config\LoadFromDB;
use Config\ToDB;
use Adbar\Dot;
use Monolog\Logger;
use Monolog\Handler\NullHandler;

/**
 * Verifies that Config produces the same get() results when backed by
 * bdus_cfg_tables / bdus_cfg_fields as when loaded from fixture JSON files.
 *
 * No filesystem involvement beyond the fixture JSON (which is used only to
 * seed the in-memory DB and as the reference for assertions).
 */
class ConfigFromDbTest extends TestCase
{
    private static DB     $db;
    private static Config $cfgJson; // loaded from JSON fixture
    private static Config $cfgDb;   // loaded from DB

    public static function setUpBeforeClass(): void
    {
        $log = new Logger('test');
        $log->pushHandler(new NullHandler());

        // ── In-memory DB ──────────────────────────────────────────────────
        static::$db = new DB('test', ['db_engine' => 'sqlite', 'db_path' => ':memory:']);
        static::$db->setLog($log);

        $manage = new Manage(static::$db);
        $manage->createTable('bdus_cfg_tables');
        $manage->createTable('bdus_cfg_fields');
        $manage->createTable('bdus_cfg_relations');
        $manage->createTable('bdus_cfg_templates');

        // ── Seed from fixture JSON ────────────────────────────────────────
        $fixtureDir = __DIR__ . '/../fixtures/cfg/';
        $tablesJson = json_decode(file_get_contents($fixtureDir . 'tables.json'), true);

        foreach ($tablesJson['tables'] as $sort => $tbRow) {
            $name = $tbRow['name'];
            ToDB::upsertTable(static::$db, array_merge($tbRow, ['sort' => $sort]));

            $fieldFile = $fixtureDir . $name . '.json';
            if (!file_exists($fieldFile)) continue;
            $fields = json_decode(file_get_contents($fieldFile), true) ?: [];
            foreach ($fields as $s => $fld) {
                ToDB::upsertField(static::$db, $name, array_merge($fld, ['sort' => $s]));
            }
        }

        // ── Two Config instances for comparison ───────────────────────────
        $dot = new Dot();
        static::$cfgJson = new Config($dot, $fixtureDir);

        $dot2 = new Dot();
        static::$cfgDb = new Config($dot2, $fixtureDir, static::$db);
    }

    // ── Table list ────────────────────────────────────────────────────────────

    public function testTableNamesMatch(): void
    {
        $jsonTables = array_keys(static::$cfgJson->get('tables') ?? []);
        $dbTables   = array_keys(static::$cfgDb->get('tables') ?? []);
        sort($jsonTables);
        sort($dbTables);
        $this->assertSame($jsonTables, $dbTables);
    }

    public function testTableLabelMatches(): void
    {
        $this->assertSame(
            static::$cfgJson->get('tables.items.label'),
            static::$cfgDb->get('tables.items.label')
        );
    }

    public function testTableOrderMatches(): void
    {
        $this->assertSame(
            static::$cfgJson->get('tables.items.order'),
            static::$cfgDb->get('tables.items.order')
        );
    }

    public function testPreviewMatches(): void
    {
        $this->assertSame(
            static::$cfgJson->get('tables.items.preview'),
            static::$cfgDb->get('tables.items.preview')
        );
    }

    public function testPluginTableFlagMatches(): void
    {
        // 'tags' is is_plugin=1 in the fixture
        $jsonVal = (bool) static::$cfgJson->get('tables.tags.is_plugin');
        $dbVal   = (bool) static::$cfgDb->get('tables.tags.is_plugin');
        $this->assertSame($jsonVal, $dbVal);
    }

    // ── Field list ────────────────────────────────────────────────────────────

    public function testFieldNamesMatch(): void
    {
        $jsonFields = array_keys(static::$cfgJson->get('tables.items.fields') ?? []);
        $dbFields   = array_keys(static::$cfgDb->get('tables.items.fields') ?? []);
        sort($jsonFields);
        sort($dbFields);
        $this->assertSame($jsonFields, $dbFields);
    }

    public function testFieldLabelMatches(): void
    {
        $this->assertSame(
            static::$cfgJson->get('tables.items.fields.name.label'),
            static::$cfgDb->get('tables.items.fields.name.label')
        );
    }

    public function testFieldTypeMatches(): void
    {
        $this->assertSame(
            static::$cfgJson->get('tables.items.fields.description.type'),
            static::$cfgDb->get('tables.items.fields.description.type')
        );
    }

    /**
     * Extra attributes (stored in the `extra` JSON column) must survive
     * the round-trip: readonly, hide, check, pattern, etc.
     */
    public function testExtraAttributesPreserved(): void
    {
        // 'id' has readonly="1"
        $this->assertSame(
            static::$cfgJson->get('tables.items.fields.id.readonly'),
            static::$cfgDb->get('tables.items.fields.id.readonly')
        );

        // 'category' has vocabulary_set
        $this->assertSame(
            static::$cfgJson->get('tables.items.fields.category.vocabulary_set'),
            static::$cfgDb->get('tables.items.fields.category.vocabulary_set')
        );

        // 'ref_code' has pattern
        $this->assertSame(
            static::$cfgJson->get('tables.items.fields.ref_code.pattern'),
            static::$cfgDb->get('tables.items.fields.ref_code.pattern')
        );
    }

    // ── Wildcard queries ──────────────────────────────────────────────────────

    public function testWildcardTablesLabels(): void
    {
        $jsonLabels = static::$cfgJson->get('tables.*.label');
        $dbLabels   = static::$cfgDb->get('tables.*.label');
        $this->assertIsArray($dbLabels);
        foreach ($jsonLabels as $tb => $label) {
            $this->assertArrayHasKey($tb, $dbLabels);
            $this->assertSame($label, $dbLabels[$tb]);
        }
    }

    public function testWildcardFieldTypes(): void
    {
        $jsonTypes = static::$cfgJson->get('tables.items.fields.*.type');
        $dbTypes   = static::$cfgDb->get('tables.items.fields.*.type');
        $this->assertSame($jsonTypes, $dbTypes);
    }

    // ── Write operations ──────────────────────────────────────────────────────

    public function testSetFldUpdatesDb(): void
    {
        static::$cfgDb->setFld('items', 'name', [
            'name'  => 'name',
            'label' => 'Updated Name',
            'type'  => 'text',
        ]);

        $row = static::$db->query(
            'SELECT label FROM bdus_cfg_fields WHERE table_name=? AND name=?',
            ['items', 'name'],
            'read'
        );
        $this->assertSame('Updated Name', $row[0]['label'] ?? null);
    }

    public function testDeleteFldRemovesFromDb(): void
    {
        // Add a throwaway field then delete it.
        static::$cfgDb->setFld('items', 'tmp_field', ['name' => 'tmp_field', 'type' => 'text']);
        static::$cfgDb->deleteFld('items', 'tmp_field');

        $row = static::$db->query(
            'SELECT id FROM bdus_cfg_fields WHERE table_name=? AND name=?',
            ['items', 'tmp_field'],
            'read'
        );
        $this->assertEmpty($row);
    }

    public function testRenameFldUpdatesDb(): void
    {
        static::$cfgDb->setFld('items', 'rename_me', ['name' => 'rename_me', 'type' => 'text']);
        static::$cfgDb->renameFld('items', 'rename_me', 'renamed_field');

        $old = static::$db->query(
            'SELECT id FROM bdus_cfg_fields WHERE table_name=? AND name=?',
            ['items', 'rename_me'],
            'read'
        );
        $new = static::$db->query(
            'SELECT id FROM bdus_cfg_fields WHERE table_name=? AND name=?',
            ['items', 'renamed_field'],
            'read'
        );
        $this->assertEmpty($old);
        $this->assertNotEmpty($new);
    }

    // ── Relations (link config) ───────────────────────────────────────────────

    public function testSetTableWithLinkStoresInCfgRelations(): void
    {
        static::$cfgDb->setTable([
            'name'     => 'items',
            'label'    => 'Items',
            'order'    => 'id',
            'id_field' => 'id',
            'preview'  => ['id'],
            'link'     => [
                ['other_tb' => 'tags', 'fld' => [['my' => 'id', 'other' => 'item_id']]],
            ],
        ]);

        $rows = static::$db->query(
            'SELECT * FROM bdus_cfg_relations WHERE from_tb=? ORDER BY sort',
            ['items'],
            'read'
        );
        $this->assertCount(1, $rows);
        $this->assertSame('tags', $rows[0]['to_tb']);
        $fld = json_decode($rows[0]['fld'], true);
        $this->assertSame('id',      $fld[0]['my']);
        $this->assertSame('item_id', $fld[0]['other']);

        // links column in bdus_cfg_tables must be NULL.
        $tb = static::$db->query(
            'SELECT links FROM bdus_cfg_tables WHERE name=?',
            ['items'],
            'read'
        );
        $this->assertNull($tb[0]['links']);
    }

    public function testLinkSurvivesRoundTripThroughConfig(): void
    {
        // After setTable(), re-instantiate Config and verify link is loaded.
        $dot = new \Adbar\Dot();
        $cfg = new Config($dot, __DIR__ . '/../fixtures/cfg/', static::$db);

        $links = $cfg->get('tables.items.link');
        $this->assertIsArray($links);
        $this->assertNotEmpty($links);
        $this->assertSame('tags', $links[0]['other_tb']);
        $fld = $links[0]['fld'];
        $this->assertSame('id',      $fld[0]['my']);
        $this->assertSame('item_id', $fld[0]['other']);
    }

    public function testSetTableReplacesExistingRelations(): void
    {
        // Replace the link with a different one.
        static::$cfgDb->setTable([
            'name'     => 'items',
            'label'    => 'Items',
            'order'    => 'id',
            'id_field' => 'id',
            'preview'  => ['id'],
            'link'     => [
                ['other_tb' => 'periods', 'fld' => [['my' => 'period_id', 'other' => 'id']]],
            ],
        ]);

        $rows = static::$db->query(
            'SELECT * FROM bdus_cfg_relations WHERE from_tb=? ORDER BY sort',
            ['items'],
            'read'
        );
        // Old 'tags' link must be gone; new 'periods' link must be there.
        $this->assertCount(1, $rows);
        $this->assertSame('periods', $rows[0]['to_tb']);
    }

    public function testSetTableWithEmptyLinksClearsRelations(): void
    {
        static::$cfgDb->setTable([
            'name'     => 'items',
            'label'    => 'Items',
            'order'    => 'id',
            'id_field' => 'id',
            'preview'  => ['id'],
            'link'     => [],
        ]);

        $rows = static::$db->query(
            'SELECT * FROM bdus_cfg_relations WHERE from_tb=?',
            ['items'],
            'read'
        ) ?: [];
        $this->assertCount(0, $rows);
    }

    public function testDeleteTableRemovesRelations(): void
    {
        // Add a temporary table with a link, then delete it.
        static::$cfgDb->setTable([
            'name'     => 'tmp_table',
            'label'    => 'Tmp',
            'order'    => 'id',
            'id_field' => 'id',
            'preview'  => ['id'],
            'link'     => [['other_tb' => 'items', 'fld' => []]],
        ]);

        $before = static::$db->query(
            'SELECT COUNT(*) AS cnt FROM bdus_cfg_relations WHERE from_tb=?',
            ['tmp_table'],
            'read'
        );
        $this->assertSame(1, (int)$before[0]['cnt']);

        static::$cfgDb->deleteTb('tmp_table');

        $after = static::$db->query(
            'SELECT COUNT(*) AS cnt FROM bdus_cfg_relations WHERE from_tb=?',
            ['tmp_table'],
            'read'
        ) ?: [['cnt' => 0]];
        $this->assertSame(0, (int)$after[0]['cnt']);
    }

    public function testRenameTableUpdatesRelations(): void
    {
        // Add a table that has a link; rename it; verify relation is updated.
        static::$cfgDb->setTable([
            'name'     => 'rename_src',
            'label'    => 'Src',
            'order'    => 'id',
            'id_field' => 'id',
            'preview'  => ['id'],
            'link'     => [['other_tb' => 'items', 'fld' => []]],
        ]);

        static::$cfgDb->renameTb('rename_src', 'rename_dst');

        $old = static::$db->query(
            'SELECT COUNT(*) AS cnt FROM bdus_cfg_relations WHERE from_tb=?',
            ['rename_src'],
            'read'
        );
        $new = static::$db->query(
            'SELECT COUNT(*) AS cnt FROM bdus_cfg_relations WHERE from_tb=?',
            ['rename_dst'],
            'read'
        );
        $this->assertSame(0, (int)$old[0]['cnt']);
        $this->assertSame(1, (int)$new[0]['cnt']);

        // Clean up.
        static::$cfgDb->deleteTb('rename_dst');
    }
}
