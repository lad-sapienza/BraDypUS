<?php

namespace Tests\Integration;

use PHPUnit\Framework\TestCase;
use DB\DB;
use DB\System\Manage;
use DB\System\Migrations\M011_ConfigToDb;
use Monolog\Logger;
use Monolog\Handler\NullHandler;

/**
 * Tests the M011_ConfigToDb migration.
 *
 * Strategy: define PROJ_DIR to point at a temporary directory that
 * mirrors the real app layout (cfg/tables.json, cfg/{tb}.json,
 * template/*.json), run M011, then assert that bdus_cfg_tables,
 * bdus_cfg_fields and bdus_cfg_templates contain the expected rows.
 */
class M011MigrationTest extends TestCase
{
    private static DB     $db;
    private static Manage $manage;
    private static string $tmpDir;

    public static function setUpBeforeClass(): void
    {
        $log = new Logger('test');
        $log->pushHandler(new NullHandler());

        static::$db = new DB('test', ['db_engine' => 'sqlite', 'db_path' => ':memory:']);
        static::$db->setLog($log);

        static::$manage = new Manage(static::$db);

        // Build a temporary app directory with fixture data.
        static::$tmpDir = sys_get_temp_dir() . '/bdus_m011_test_' . uniqid();
        mkdir(static::$tmpDir . '/cfg',      0755, true);
        mkdir(static::$tmpDir . '/template', 0755, true);

        // tables.json
        file_put_contents(static::$tmpDir . '/cfg/tables.json', json_encode([
            'tables' => [
                [
                    'name'      => 'items',
                    'label'     => 'Items',
                    'order'     => 'id',
                    'id_field'  => 'id',
                    'preview'   => ['id', 'name'],
                    'link'      => [['other_tb' => 'tags', 'fld' => [['my' => 'id', 'other' => 'item_id']]]],
                ],
                [
                    'name'      => 'tags',
                    'label'     => 'Tags',
                    'order'     => 'id',
                    'id_field'  => 'id',
                    'is_plugin' => '1',
                ],
            ],
        ]));

        // items.json
        file_put_contents(static::$tmpDir . '/cfg/items.json', json_encode([
            ['name' => 'id',   'label' => 'ID',   'type' => 'text', 'db_type' => 'INTEGER', 'readonly' => '1'],
            ['name' => 'name', 'label' => 'Name', 'type' => 'text', 'db_type' => 'TEXT',    'check'    => ['not_empty']],
        ]));

        // tags.json
        file_put_contents(static::$tmpDir . '/cfg/tags.json', json_encode([
            ['name' => 'id',      'label' => 'ID',    'type' => 'text'],
            ['name' => 'item_id', 'label' => 'Item',  'type' => 'text'],
        ]));

        // template
        file_put_contents(static::$tmpDir . '/template/items.default.json', json_encode([
            'sections' => [['content' => [['field' => 'name', 'width' => '1/1']]]],
        ]));

        // Run the migration, passing our tmp dir explicitly so that the
        // test bootstrap's PROJ_DIR constant (fixed to a different path)
        // does not interfere.
        M011_ConfigToDb::run(static::$manage, static::$tmpDir . '/');
    }

    public static function tearDownAfterClass(): void
    {
        // Clean up tmp directory.
        array_map('unlink', glob(static::$tmpDir . '/cfg/*'));
        array_map('unlink', glob(static::$tmpDir . '/template/*'));
        @rmdir(static::$tmpDir . '/cfg');
        @rmdir(static::$tmpDir . '/template');
        @rmdir(static::$tmpDir);
    }

    // ── Tables ────────────────────────────────────────────────────────────────

    public function testTableCountIsCorrect(): void
    {
        $rows = static::$db->query('SELECT COUNT(*) AS cnt FROM bdus_cfg_tables', [], 'read');
        $this->assertSame(2, (int)$rows[0]['cnt']);
    }

    public function testItemsTableImported(): void
    {
        $row = static::$db->query(
            'SELECT * FROM bdus_cfg_tables WHERE name=?',
            ['items'],
            'read'
        );
        $this->assertNotEmpty($row);
        $this->assertSame('Items', $row[0]['label']);
        $this->assertSame('id',    $row[0]['order_field']);
        $this->assertSame('id',    $row[0]['id_field']);
        $this->assertSame(0, (int)$row[0]['is_plugin']);
    }

    public function testTagsTableIsPlugin(): void
    {
        $row = static::$db->query(
            'SELECT * FROM bdus_cfg_tables WHERE name=?',
            ['tags'],
            'read'
        );
        $this->assertNotEmpty($row);
        $this->assertSame(1, (int)$row[0]['is_plugin']);
    }

    public function testPreviewStoredAsJson(): void
    {
        $row = static::$db->query(
            'SELECT preview FROM bdus_cfg_tables WHERE name=?',
            ['items'],
            'read'
        );
        $preview = json_decode($row[0]['preview'], true);
        $this->assertSame(['id', 'name'], $preview);
    }

    public function testLinksColumnIsNullAfterMigration(): void
    {
        // Since M011 now writes links to bdus_cfg_relations, the `links` blob
        // in bdus_cfg_tables must be NULL.
        $row = static::$db->query(
            'SELECT links FROM bdus_cfg_tables WHERE name=?',
            ['items'],
            'read'
        );
        $this->assertNull($row[0]['links']);
    }

    public function testLinksStoredInCfgRelations(): void
    {
        // New schema (M026): one row per FK column pair.
        $rows = static::$db->query(
            'SELECT from_col, to_tb, to_col FROM bdus_cfg_relations WHERE from_tb=? ORDER BY from_col',
            ['items'],
            'read'
        );
        $this->assertCount(1, $rows);
        $this->assertSame('tags',    $rows[0]['to_tb']);
        $this->assertSame('id',      $rows[0]['from_col']);
        $this->assertSame('item_id', $rows[0]['to_col']);
    }

    // ── Fields ────────────────────────────────────────────────────────────────

    public function testItemsFieldCount(): void
    {
        $rows = static::$db->query(
            'SELECT COUNT(*) AS cnt FROM bdus_cfg_fields WHERE table_name=?',
            ['items'],
            'read'
        );
        $this->assertSame(2, (int)$rows[0]['cnt']);
    }

    public function testFieldExplicitColumnsImported(): void
    {
        $row = static::$db->query(
            'SELECT * FROM bdus_cfg_fields WHERE table_name=? AND name=?',
            ['items', 'name'],
            'read'
        );
        $this->assertSame('Name', $row[0]['label']);
        $this->assertSame('text', $row[0]['type']);
        $this->assertSame('TEXT', $row[0]['db_type']);
    }

    public function testFieldExtraAttributesImported(): void
    {
        // 'id' has readonly="1" → must be in extra JSON.
        $row = static::$db->query(
            'SELECT extra FROM bdus_cfg_fields WHERE table_name=? AND name=?',
            ['items', 'id'],
            'read'
        );
        $extra = json_decode($row[0]['extra'], true);
        $this->assertSame('1', $extra['readonly']);
    }

    public function testFieldCheckImported(): void
    {
        // 'name' has check=['not_empty'] → must be in extra JSON.
        $row = static::$db->query(
            'SELECT extra FROM bdus_cfg_fields WHERE table_name=? AND name=?',
            ['items', 'name'],
            'read'
        );
        $extra = json_decode($row[0]['extra'], true);
        $this->assertContains('not_empty', $extra['check']);
    }

    public function testFieldSortPreserved(): void
    {
        $rows = static::$db->query(
            'SELECT name, sort FROM bdus_cfg_fields WHERE table_name=? ORDER BY sort',
            ['items'],
            'read'
        );
        $this->assertSame('id',   $rows[0]['name']);
        $this->assertSame('name', $rows[1]['name']);
    }

    // ── Templates ────────────────────────────────────────────────────────────

    public function testTemplateImported(): void
    {
        $row = static::$db->query(
            'SELECT * FROM bdus_cfg_templates WHERE table_name=? AND name=?',
            ['items', 'default'],
            'read'
        );
        $this->assertNotEmpty($row);
        $content = json_decode($row[0]['content'], true);
        $this->assertArrayHasKey('sections', $content);
    }

    // ── Idempotency ───────────────────────────────────────────────────────────

    public function testRunningMigrationAgainIsNoop(): void
    {
        $before = static::$db->query('SELECT COUNT(*) AS cnt FROM bdus_cfg_tables', [], 'read')[0]['cnt'];
        M011_ConfigToDb::run(static::$manage, static::$tmpDir . '/');
        $after  = static::$db->query('SELECT COUNT(*) AS cnt FROM bdus_cfg_tables', [], 'read')[0]['cnt'];
        $this->assertSame((int)$before, (int)$after);
    }

    // ── System table filter ───────────────────────────────────────────────────

    public function testSystemTablesNotImported(): void
    {
        // None of the bdus_* system table names should appear in bdus_cfg_tables.
        foreach (['bdus_files', 'bdus_geodata', 'bdus_users', 'bdus_versions'] as $sysName) {
            $row = static::$db->query(
                'SELECT id FROM bdus_cfg_tables WHERE name=?',
                [$sysName],
                'read'
            );
            $this->assertEmpty($row, "$sysName must not appear in bdus_cfg_tables");
        }
    }
}
