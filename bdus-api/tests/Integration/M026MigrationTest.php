<?php

namespace Tests\Integration;

use PHPUnit\Framework\TestCase;
use DB\DB;
use DB\System\Manage;
use DB\System\Migrations\M026_RefactorCfgRelations;
use Monolog\Logger;
use Monolog\Handler\NullHandler;

/**
 * Tests for M026_RefactorCfgRelations.
 *
 * Verifies that the migration:
 *  - creates bdus_cfg_relations with the new schema when it doesn't exist
 *  - expands legacy fld JSON into individual (from_col, to_col) rows
 *  - sets on_delete=RESTRICT, on_update=CASCADE defaults
 *  - deduplicates by UNIQUE(from_tb, from_col)
 *  - is fully idempotent (safe to run twice)
 *  - is a no-op on a fresh DB with no legacy data
 */
class M026MigrationTest extends TestCase
{
    private static DB     $db;
    private static Manage $manage;

    public static function setUpBeforeClass(): void
    {
        $log = new Logger('test');
        $log->pushHandler(new NullHandler());

        static::$db = new DB('test_m026', ['db_engine' => 'sqlite', 'db_path' => ':memory:']);
        static::$db->setLog($log);
        static::$manage = new Manage(static::$db);
    }

    protected function setUp(): void
    {
        static::$db->query('DROP TABLE IF EXISTS bdus_cfg_relations', [], 'boolean');
    }

    // ── Helpers ───────────────────────────────────────────────────────────────

    private function columnNames(string $table): array
    {
        $cols = static::$db->query("PRAGMA table_info({$table})", [], 'read') ?: [];
        return array_column($cols, 'name');
    }

    private function tableExists(string $name): bool
    {
        $rows = static::$db->query(
            "SELECT name FROM sqlite_master WHERE type='table' AND name=?",
            [$name], 'read'
        ) ?: [];
        return !empty($rows);
    }

    private function indexExists(string $name): bool
    {
        $rows = static::$db->query(
            "SELECT name FROM sqlite_master WHERE type='index' AND name=?",
            [$name], 'read'
        ) ?: [];
        return !empty($rows);
    }

    private function seedLegacyTable(array $rows): void
    {
        static::$db->query(
            'CREATE TABLE bdus_cfg_relations (id INTEGER PRIMARY KEY AUTOINCREMENT, from_tb TEXT, to_tb TEXT, fld TEXT, sort INTEGER)',
            [], 'boolean'
        );
        foreach ($rows as $r) {
            static::$db->query(
                'INSERT INTO bdus_cfg_relations (from_tb, to_tb, fld, sort) VALUES (?,?,?,?)',
                [$r['from_tb'], $r['to_tb'], $r['fld'], $r['sort'] ?? 0],
                'boolean'
            );
        }
    }

    // ── Fresh DB (no legacy table) ────────────────────────────────────────────

    public function testCreatesFreshTableWhenMissing(): void
    {
        M026_RefactorCfgRelations::run(static::$manage);
        $this->assertTrue($this->tableExists('bdus_cfg_relations'));
    }

    public function testNewSchemaHasRequiredColumns(): void
    {
        M026_RefactorCfgRelations::run(static::$manage);
        $cols = $this->columnNames('bdus_cfg_relations');

        foreach (['id', 'from_tb', 'from_col', 'to_tb', 'to_col', 'on_delete', 'on_update'] as $col) {
            $this->assertContains($col, $cols, "Column missing: $col");
        }
        $this->assertNotContains('fld',  $cols, 'Legacy column fld must not be present');
        $this->assertNotContains('sort', $cols, 'Legacy column sort must not be present');
    }

    public function testNewSchemaHasUniqueIndex(): void
    {
        M026_RefactorCfgRelations::run(static::$manage);
        $this->assertTrue($this->indexExists('cfg_rel_from_col_unique'));
    }

    // ── Legacy data migration ─────────────────────────────────────────────────

    public function testExpandsSingleFieldPair(): void
    {
        $this->seedLegacyTable([[
            'from_tb' => 'items',
            'to_tb'   => 'tags',
            'fld'     => json_encode([['my' => 'tag_id', 'other' => 'id']]),
        ]]);

        M026_RefactorCfgRelations::run(static::$manage);

        $rows = static::$db->query(
            'SELECT from_tb, from_col, to_tb, to_col, on_delete, on_update FROM bdus_cfg_relations',
            [], 'read'
        ) ?: [];

        $this->assertCount(1, $rows);
        $this->assertSame('items',   $rows[0]['from_tb']);
        $this->assertSame('tag_id',  $rows[0]['from_col']);
        $this->assertSame('tags',    $rows[0]['to_tb']);
        $this->assertSame('id',      $rows[0]['to_col']);
        $this->assertSame('RESTRICT', $rows[0]['on_delete']);
        $this->assertSame('CASCADE',  $rows[0]['on_update']);
    }

    public function testExpandsMultipleFieldPairs(): void
    {
        $this->seedLegacyTable([[
            'from_tb' => 'a',
            'to_tb'   => 'b',
            'fld'     => json_encode([
                ['my' => 'col1', 'other' => 'id'],
                ['my' => 'col2', 'other' => 'ref'],
            ]),
        ]]);

        M026_RefactorCfgRelations::run(static::$manage);

        $rows = static::$db->query('SELECT from_col FROM bdus_cfg_relations ORDER BY from_col', [], 'read') ?: [];
        $this->assertCount(2, $rows);
        $this->assertSame('col1', $rows[0]['from_col']);
        $this->assertSame('col2', $rows[1]['from_col']);
    }

    public function testDeduplicatesDuplicateFromCol(): void
    {
        // Two legacy rows pointing to the same from_tb.from_col (possible after old dedup issues)
        $this->seedLegacyTable([
            ['from_tb' => 'a', 'to_tb' => 'b', 'fld' => json_encode([['my' => 'col1', 'other' => 'id']])],
            ['from_tb' => 'a', 'to_tb' => 'c', 'fld' => json_encode([['my' => 'col1', 'other' => 'ref']])],
        ]);

        M026_RefactorCfgRelations::run(static::$manage);

        $rows = static::$db->query('SELECT * FROM bdus_cfg_relations', [], 'read') ?: [];
        $this->assertCount(1, $rows, 'Duplicate (from_tb, from_col) must be deduplicated');
    }

    public function testSkipsRowsWithEmptyFld(): void
    {
        $this->seedLegacyTable([[
            'from_tb' => 'items',
            'to_tb'   => 'tags',
            'fld'     => null,
        ]]);

        M026_RefactorCfgRelations::run(static::$manage);

        $rows = static::$db->query('SELECT * FROM bdus_cfg_relations', [], 'read') ?: [];
        $this->assertCount(0, $rows);
    }

    // ── Idempotency ───────────────────────────────────────────────────────────

    public function testIdempotentOnNewSchema(): void
    {
        M026_RefactorCfgRelations::run(static::$manage);
        M026_RefactorCfgRelations::run(static::$manage); // second run must not throw
        $this->assertTrue($this->tableExists('bdus_cfg_relations'));
    }

    public function testIdempotentPreservesData(): void
    {
        M026_RefactorCfgRelations::run(static::$manage);

        static::$db->query(
            'INSERT INTO bdus_cfg_relations (from_tb, from_col, to_tb, to_col) VALUES (?,?,?,?)',
            ['a', 'col1', 'b', 'id'], 'boolean'
        );

        M026_RefactorCfgRelations::run(static::$manage); // must be no-op

        $rows = static::$db->query('SELECT * FROM bdus_cfg_relations', [], 'read') ?: [];
        $this->assertCount(1, $rows, 'Row must survive second migration run');
        $this->assertSame('col1', $rows[0]['from_col']);
    }
}
