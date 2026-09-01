<?php

namespace Tests\Integration;

use PHPUnit\Framework\TestCase;
use DB\DB;
use DB\System\Manage;
use DB\System\Migrations\M015_DeleteCfgJsonFiles;
use Monolog\Logger;
use Monolog\Handler\NullHandler;

/**
 * Tests for M015_DeleteCfgJsonFiles.
 *
 * Uses explicit $projDir to avoid PROJ_DIR constant collisions with other
 * migration tests that define the constant at module load time.
 */
class M015MigrationTest extends TestCase
{
    private static DB     $db;
    private static Manage $manage;
    private static string $tmpDir;

    /** Table names seeded into bdus_cfg_tables (simulates post-M011 state). */
    private static array $tableNames = ['finds', 'contexts'];

    public static function setUpBeforeClass(): void
    {
        $log = new Logger('test');
        $log->pushHandler(new NullHandler());

        static::$db = new DB('test_m015', ['db_engine' => 'sqlite', 'db_path' => ':memory:']);
        static::$db->setLog($log);
        static::$manage = new Manage(static::$db);

        // Create minimal bdus_cfg_tables so M015 can enumerate table names.
        static::$db->execInTransaction('
            CREATE TABLE bdus_cfg_tables (
                id         INTEGER PRIMARY KEY AUTOINCREMENT,
                name       TEXT NOT NULL
            )
        ');
        foreach (static::$tableNames as $name) {
            static::$db->query(
                'INSERT INTO bdus_cfg_tables (name) VALUES (?)',
                [$name],
                'boolean'
            );
        }

        // Create also bdus_migrations (needed by Manage internals).
        static::$db->execInTransaction('
            CREATE TABLE bdus_migrations (
                id         INTEGER PRIMARY KEY AUTOINCREMENT,
                name       TEXT    NOT NULL UNIQUE,
                applied_at INTEGER NOT NULL
            )
        ');

        // Build a temp project tree mirroring a post-M011 installation.
        static::$tmpDir = sys_get_temp_dir() . '/bdus_m015_test_' . uniqid();
        mkdir(static::$tmpDir . '/cfg',      0755, true);
        mkdir(static::$tmpDir . '/template', 0755, true);
        mkdir(static::$tmpDir . '/geodata',  0755, true);

        // Files that MUST be deleted.
        file_put_contents(static::$tmpDir . '/cfg/tables.json',            '{"tables":[]}');
        file_put_contents(static::$tmpDir . '/cfg/finds.json',             '[]');
        file_put_contents(static::$tmpDir . '/cfg/contexts.json',          '[]');
        file_put_contents(static::$tmpDir . '/cfg/files.json',             '[]'); // system-table legacy
        file_put_contents(static::$tmpDir . '/template/finds.main.json',   '{}');
        file_put_contents(static::$tmpDir . '/template/contexts.list.json','{}');

        // Files that MUST NOT be deleted.
        file_put_contents(static::$tmpDir . '/cfg/app_data.json',          '{"db":"test"}');
        file_put_contents(static::$tmpDir . '/geodata/index.json',         '[]');
    }

    public static function tearDownAfterClass(): void
    {
        // Recursively clean up the temp tree (most files already gone after the test).
        foreach (['cfg', 'template', 'geodata'] as $sub) {
            $dir = static::$tmpDir . '/' . $sub;
            if (is_dir($dir)) {
                foreach (scandir($dir) as $f) {
                    if ($f !== '.' && $f !== '..') {
                        @unlink($dir . '/' . $f);
                    }
                }
                @rmdir($dir);
            }
        }
        @rmdir(static::$tmpDir);
    }

    // ── Tests ─────────────────────────────────────────────────────────────────

    public function testTablesJsonIsDeleted(): void
    {
        M015_DeleteCfgJsonFiles::run(static::$manage, static::$tmpDir);
        $this->assertFileDoesNotExist(static::$tmpDir . '/cfg/tables.json');
    }

    public function testPerTableJsonFilesAreDeleted(): void
    {
        foreach (static::$tableNames as $name) {
            $this->assertFileDoesNotExist(
                static::$tmpDir . '/cfg/' . $name . '.json',
                "cfg/{$name}.json should have been deleted"
            );
        }
    }

    public function testSystemTableJsonIsDeleted(): void
    {
        // files.json is a system-table config never imported by M011,
        // but must still be cleaned up.
        $this->assertFileDoesNotExist(static::$tmpDir . '/cfg/files.json');
    }

    public function testTemplateJsonFilesAreDeleted(): void
    {
        $this->assertFileDoesNotExist(static::$tmpDir . '/template/finds.main.json');
        $this->assertFileDoesNotExist(static::$tmpDir . '/template/contexts.list.json');
    }

    public function testAppDataJsonIsPreserved(): void
    {
        $this->assertFileExists(static::$tmpDir . '/cfg/app_data.json');
    }

    public function testGeodataIndexIsPreserved(): void
    {
        $this->assertFileExists(static::$tmpDir . '/geodata/index.json');
    }

    public function testMigrationIsIdempotent(): void
    {
        // All target files are already gone; running again must not throw.
        M015_DeleteCfgJsonFiles::run(static::$manage, static::$tmpDir);
        $this->assertTrue(true); // No exception = pass.
    }

    public function testMigrationAbortsWhenCfgTablesIsEmpty(): void
    {
        // Fresh DB with an empty bdus_cfg_tables — migration should do nothing.
        $db2     = new DB('test_m015_empty', ['db_engine' => 'sqlite', 'db_path' => ':memory:']);
        $manage2 = new Manage($db2);
        $db2->execInTransaction('
            CREATE TABLE bdus_cfg_tables (id INTEGER PRIMARY KEY, name TEXT NOT NULL)
        ');

        $dir2 = sys_get_temp_dir() . '/bdus_m015_empty_' . uniqid();
        mkdir($dir2 . '/cfg', 0755, true);
        file_put_contents($dir2 . '/cfg/tables.json', '{"tables":[]}');

        M015_DeleteCfgJsonFiles::run($manage2, $dir2);

        // tables.json must still be there because bdus_cfg_tables had 0 rows.
        $this->assertFileExists($dir2 . '/cfg/tables.json');

        @unlink($dir2 . '/cfg/tables.json');
        @rmdir($dir2 . '/cfg');
        @rmdir($dir2);
    }
}
