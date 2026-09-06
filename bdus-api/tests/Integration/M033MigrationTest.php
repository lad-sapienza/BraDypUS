<?php

namespace Tests\Integration;

use PHPUnit\Framework\TestCase;
use DB\DB;
use DB\System\Manage;
use DB\System\Migrations\M033_MigrateLegacyFileLinks;
use Monolog\Logger;
use Monolog\Handler\NullHandler;

/**
 * Tests for M033_MigrateLegacyFileLinks.
 *
 * Verifies that file links stored in bdus_userlinks under the legacy bare
 * table name 'files' (pre-rename) are correctly moved to bdus_file_links
 * and removed from bdus_userlinks.
 *
 * bdus_file_links.file_id carries a real FK to bdus_files.id here (matching
 * the production schema), so the orphan-link cases actually exercise the
 * constraint the migration must not trip over.
 */
class M033MigrationTest extends TestCase
{
    private static DB     $db;
    private static Manage $manage;

    public static function setUpBeforeClass(): void
    {
        $log = new Logger('test');
        $log->pushHandler(new NullHandler());

        static::$db     = new DB('test_m033', ['db_engine' => 'sqlite', 'db_path' => ':memory:']);
        static::$db->setLog($log);
        static::$manage = new Manage(static::$db);
    }

    protected function setUp(): void
    {
        // Drop child before parent so the FK below does not block the DROP.
        static::$db->exec('DROP TABLE IF EXISTS bdus_file_links');
        static::$db->exec('DROP TABLE IF EXISTS bdus_userlinks');
        static::$db->exec('DROP TABLE IF EXISTS bdus_files');

        static::$db->exec(
            'CREATE TABLE bdus_files (
                id  INTEGER PRIMARY KEY AUTOINCREMENT
            )'
        );

        static::$db->exec(
            'CREATE TABLE bdus_userlinks (
                id      INTEGER PRIMARY KEY AUTOINCREMENT,
                tb_one  TEXT NOT NULL,
                id_one  INTEGER NOT NULL,
                tb_two  TEXT NOT NULL,
                id_two  INTEGER NOT NULL,
                sort    INTEGER,
                label   TEXT
            )'
        );

        static::$db->exec(
            'CREATE TABLE bdus_file_links (
                id          INTEGER PRIMARY KEY AUTOINCREMENT,
                file_id     INTEGER NOT NULL,
                table_name  TEXT NOT NULL,
                record_id   INTEGER NOT NULL,
                sort        INTEGER,
                FOREIGN KEY (file_id) REFERENCES bdus_files (id)
            )'
        );

        // Files referenced by the happy-path fixtures below.
        $this->seedFiles(10, 42);
    }

    private function seedFiles(int ...$ids): void
    {
        foreach ($ids as $id) {
            static::$db->query('INSERT INTO bdus_files (id) VALUES (?)', [$id], 'boolean');
        }
    }

    public function testMigratesFileInTbOne(): void
    {
        static::$db->exec("INSERT INTO bdus_userlinks (tb_one, id_one, tb_two, id_two, sort) VALUES ('files', 10, 'places', 96, 1)");

        M033_MigrateLegacyFileLinks::run(static::$manage);

        $links = static::$db->query("SELECT * FROM bdus_file_links", [], 'read');
        $this->assertCount(1, $links);
        $this->assertSame(10,       (int)$links[0]['file_id']);
        $this->assertSame('places', $links[0]['table_name']);
        $this->assertSame(96,       (int)$links[0]['record_id']);
        $this->assertSame(1,        (int)$links[0]['sort']);

        $remaining = static::$db->query("SELECT * FROM bdus_userlinks", [], 'read');
        $this->assertEmpty($remaining, 'Migrated row must be removed from bdus_userlinks');
    }

    public function testMigratesFileInTbTwo(): void
    {
        static::$db->exec("INSERT INTO bdus_userlinks (tb_one, id_one, tb_two, id_two, sort) VALUES ('manuscripts', 5, 'files', 42, 2)");

        M033_MigrateLegacyFileLinks::run(static::$manage);

        $links = static::$db->query("SELECT * FROM bdus_file_links", [], 'read');
        $this->assertCount(1, $links);
        $this->assertSame(42,            (int)$links[0]['file_id']);
        $this->assertSame('manuscripts', $links[0]['table_name']);
        $this->assertSame(5,             (int)$links[0]['record_id']);

        $remaining = static::$db->query("SELECT * FROM bdus_userlinks", [], 'read');
        $this->assertEmpty($remaining);
    }

    public function testPreservesNonFileLinks(): void
    {
        static::$db->exec("INSERT INTO bdus_userlinks (tb_one, id_one, tb_two, id_two) VALUES ('files', 10, 'places', 96)");
        static::$db->exec("INSERT INTO bdus_userlinks (tb_one, id_one, tb_two, id_two) VALUES ('manuscripts', 1, 'places', 2)");

        M033_MigrateLegacyFileLinks::run(static::$manage);

        $remaining = static::$db->query("SELECT * FROM bdus_userlinks", [], 'read');
        $this->assertCount(1, $remaining, 'Non-file link must remain in bdus_userlinks');
        $this->assertSame('manuscripts', $remaining[0]['tb_one']);

        $links = static::$db->query("SELECT * FROM bdus_file_links", [], 'read');
        $this->assertCount(1, $links);
    }

    public function testIdempotentWhenNoLegacyRows(): void
    {
        static::$db->exec("INSERT INTO bdus_userlinks (tb_one, id_one, tb_two, id_two) VALUES ('manuscripts', 1, 'places', 2)");

        M033_MigrateLegacyFileLinks::run(static::$manage);
        M033_MigrateLegacyFileLinks::run(static::$manage); // run twice

        $links     = static::$db->query("SELECT * FROM bdus_file_links", [], 'read');
        $remaining = static::$db->query("SELECT * FROM bdus_userlinks",  [], 'read');

        $this->assertEmpty($links,      'No file links should have been created');
        $this->assertCount(1, $remaining, 'Non-file link must be untouched');
    }

    // ── Orphaned links (file no longer exists in bdus_files) ──────────────────

    public function testSkipsOrphanFileLinkInTbOne(): void
    {
        // id_one = 999 has no matching bdus_files row (v4 deleted the file,
        // left the userlink behind). Copying it would trip fl_file_fk.
        static::$db->exec("INSERT INTO bdus_userlinks (tb_one, id_one, tb_two, id_two, sort) VALUES ('files', 999, 'places', 96, 1)");
        static::$db->exec("INSERT INTO bdus_userlinks (tb_one, id_one, tb_two, id_two, sort) VALUES ('files', 10,  'places', 97, 2)");

        M033_MigrateLegacyFileLinks::run(static::$manage);

        $links = static::$db->query("SELECT * FROM bdus_file_links", [], 'read');
        $this->assertCount(1, $links, 'Only the link to an existing file is migrated');
        $this->assertSame(10, (int) $links[0]['file_id']);

        $remaining = static::$db->query("SELECT * FROM bdus_userlinks", [], 'read');
        $this->assertEmpty($remaining, 'Both rows (migrated + orphan) leave bdus_userlinks');
    }

    public function testSkipsOrphanFileLinkInTbTwo(): void
    {
        static::$db->exec("INSERT INTO bdus_userlinks (tb_one, id_one, tb_two, id_two) VALUES ('manuscripts', 3, 'files', 999)");
        static::$db->exec("INSERT INTO bdus_userlinks (tb_one, id_one, tb_two, id_two) VALUES ('manuscripts', 4, 'files', 42)");

        M033_MigrateLegacyFileLinks::run(static::$manage);

        $links = static::$db->query("SELECT * FROM bdus_file_links", [], 'read');
        $this->assertCount(1, $links);
        $this->assertSame(42, (int) $links[0]['file_id']);

        $remaining = static::$db->query("SELECT * FROM bdus_userlinks", [], 'read');
        $this->assertEmpty($remaining);
    }

    public function testAllOrphanLinksDoNotAbortMigration(): void
    {
        static::$db->exec("INSERT INTO bdus_userlinks (tb_one, id_one, tb_two, id_two) VALUES ('files', 998, 'places', 1)");
        static::$db->exec("INSERT INTO bdus_userlinks (tb_one, id_one, tb_two, id_two) VALUES ('files', 999, 'places', 2)");

        M033_MigrateLegacyFileLinks::run(static::$manage); // must not throw

        $links     = static::$db->query("SELECT * FROM bdus_file_links", [], 'read');
        $remaining = static::$db->query("SELECT * FROM bdus_userlinks",  [], 'read');

        $this->assertEmpty($links,     'Nothing to migrate — every file is gone');
        $this->assertEmpty($remaining, 'Dead file rows are still purged from bdus_userlinks');
    }
}
