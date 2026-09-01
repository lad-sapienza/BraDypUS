<?php

namespace Tests\Integration;

use PHPUnit\Framework\TestCase;
use DB\DB;
use DB\System\Manage;
use DB\System\Migrations\M023_ZoteroTables;
use Monolog\Logger;
use Monolog\Handler\NullHandler;

/**
 * Tests for M023_ZoteroTables.
 *
 * Verifies that the migration:
 *   - creates bdus_zotero_libs with the expected columns and unique index
 *   - creates bdus_zotero_links with the expected columns and indexes
 *   - is fully idempotent (safe to run twice)
 */
class M023MigrationTest extends TestCase
{
    private static DB     $db;
    private static Manage $manage;

    public static function setUpBeforeClass(): void
    {
        $log = new Logger('test');
        $log->pushHandler(new NullHandler());

        static::$db = new DB('test_m023', ['db_engine' => 'sqlite', 'db_path' => ':memory:']);
        static::$db->setLog($log);
        static::$manage = new Manage(static::$db);
    }

    protected function setUp(): void
    {
        // Drop tables (links first, because it would FK-reference libs)
        static::$db->query('DROP TABLE IF EXISTS bdus_zotero_links', [], 'boolean');
        static::$db->query('DROP TABLE IF EXISTS bdus_zotero_libs',  [], 'boolean');
    }

    // ── Helpers ───────────────────────────────────────────────────────────────

    private function columnNames(string $table): array
    {
        $cols = static::$db->query("PRAGMA table_info($table)", [], 'read') ?: [];
        return array_column($cols, 'name');
    }

    private function indexExists(string $name): bool
    {
        $rows = static::$db->query(
            "SELECT name FROM sqlite_master WHERE type='index' AND name=?",
            [$name],
            'read'
        ) ?: [];
        return !empty($rows);
    }

    private function tableExists(string $name): bool
    {
        $rows = static::$db->query(
            "SELECT name FROM sqlite_master WHERE type='table' AND name=?",
            [$name],
            'read'
        ) ?: [];
        return !empty($rows);
    }

    // ── bdus_zotero_libs ──────────────────────────────────────────────────────

    public function testCreatesLibsTable(): void
    {
        M023_ZoteroTables::run(static::$manage);
        $this->assertTrue($this->tableExists('bdus_zotero_libs'));
    }

    public function testLibsTableHasRequiredColumns(): void
    {
        M023_ZoteroTables::run(static::$manage);
        $cols = $this->columnNames('bdus_zotero_libs');

        foreach (['id', 'type', 'zotero_id', 'name', 'api_key', 'citation_style', 'created_at'] as $col) {
            $this->assertContains($col, $cols, "bdus_zotero_libs is missing column: $col");
        }
    }

    public function testLibsUniqueIndexCreated(): void
    {
        M023_ZoteroTables::run(static::$manage);
        $this->assertTrue($this->indexExists('zl_type_id_idx'));
    }

    public function testLibsUniqueIndexEnforced(): void
    {
        M023_ZoteroTables::run(static::$manage);

        static::$db->query(
            "INSERT INTO bdus_zotero_libs (type, zotero_id, name) VALUES ('group', '123456', 'Lib A')",
            [], 'boolean'
        );

        $this->expectException(\Exception::class);
        static::$db->query(
            "INSERT INTO bdus_zotero_libs (type, zotero_id, name) VALUES ('group', '123456', 'Lib B')",
            [], 'boolean'
        );
    }

    // ── bdus_zotero_links ─────────────────────────────────────────────────────

    public function testCreatesLinksTable(): void
    {
        M023_ZoteroTables::run(static::$manage);
        $this->assertTrue($this->tableExists('bdus_zotero_links'));
    }

    public function testLinksTableHasRequiredColumns(): void
    {
        M023_ZoteroTables::run(static::$manage);
        $cols = $this->columnNames('bdus_zotero_links');

        foreach ([
            'id', 'tb', 'record_id', 'lib_id', 'zotero_key',
            'pages', 'notes', 'sort',
            'author_year', 'full_citation', 'zotero_version',
            'synced_at', 'detached', 'created_at',
        ] as $col) {
            $this->assertContains($col, $cols, "bdus_zotero_links is missing column: $col");
        }
    }

    public function testLinksRecordIndexCreated(): void
    {
        M023_ZoteroTables::run(static::$manage);
        $this->assertTrue($this->indexExists('zln_record_idx'));
    }

    public function testLinksItemIndexCreated(): void
    {
        M023_ZoteroTables::run(static::$manage);
        $this->assertTrue($this->indexExists('zln_item_idx'));
    }

    public function testDetachedDefaultIsZero(): void
    {
        M023_ZoteroTables::run(static::$manage);

        // Insert a lib first (lib_id = 1)
        static::$db->query(
            "INSERT INTO bdus_zotero_libs (id, type, zotero_id, name) VALUES (1, 'group', '999', 'Test')",
            [], 'boolean'
        );
        // Insert a link without specifying detached
        static::$db->query(
            "INSERT INTO bdus_zotero_links (tb, record_id, lib_id, zotero_key)
             VALUES ('items', 1, 1, 'ABCD1234')",
            [], 'boolean'
        );

        $rows = static::$db->query(
            "SELECT detached FROM bdus_zotero_links WHERE zotero_key = 'ABCD1234'",
            [], 'read'
        );
        $this->assertSame('0', (string) $rows[0]['detached']);
    }

    // ── Idempotency ───────────────────────────────────────────────────────────

    public function testIdempotentRunDoesNotThrow(): void
    {
        M023_ZoteroTables::run(static::$manage);
        M023_ZoteroTables::run(static::$manage); // second run must not throw
        $this->assertTrue($this->tableExists('bdus_zotero_libs'));
        $this->assertTrue($this->tableExists('bdus_zotero_links'));
    }

    public function testIdempotentRunPreservesData(): void
    {
        M023_ZoteroTables::run(static::$manage);

        static::$db->query(
            "INSERT INTO bdus_zotero_libs (type, zotero_id, name) VALUES ('group', '777', 'My Library')",
            [], 'boolean'
        );

        M023_ZoteroTables::run(static::$manage); // should be a no-op

        $rows = static::$db->query(
            "SELECT * FROM bdus_zotero_libs WHERE zotero_id = '777'",
            [], 'read'
        );
        $this->assertCount(1, $rows, 'Row must survive second migration run');
        $this->assertSame('My Library', $rows[0]['name']);
    }
}
