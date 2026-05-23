<?php

namespace Tests\Integration;

use PHPUnit\Framework\TestCase;
use DB\DB;
use DB\System\Manage;
use DB\System\Migrations\M016_RenameAppDataJson;
use Config\Load;
use Monolog\Logger;
use Monolog\Handler\NullHandler;

/**
 * Tests for M016_RenameAppDataJson.
 */
class M016MigrationTest extends TestCase
{
    private static DB     $db;
    private static Manage $manage;
    private static string $tmpDir;

    public static function setUpBeforeClass(): void
    {
        $log = new Logger('test');
        $log->pushHandler(new NullHandler());

        static::$db = new DB('test_m016', ['db_engine' => 'sqlite', 'db_path' => ':memory:']);
        static::$db->setLog($log);
        static::$manage = new Manage(static::$db);

        static::$tmpDir = sys_get_temp_dir() . '/bdus_m016_test_' . uniqid();
        mkdir(static::$tmpDir . '/cfg', 0755, true);
    }

    public static function tearDownAfterClass(): void
    {
        foreach (['config.json', 'app_data.json'] as $f) {
            @unlink(static::$tmpDir . '/cfg/' . $f);
        }
        @rmdir(static::$tmpDir . '/cfg');
        @rmdir(static::$tmpDir);
    }

    public function testRenamesAppDataToConfigJson(): void
    {
        file_put_contents(static::$tmpDir . '/cfg/app_data.json', '{"name":"test"}');

        M016_RenameAppDataJson::run(static::$manage, static::$tmpDir);

        $this->assertFileDoesNotExist(static::$tmpDir . '/cfg/app_data.json');
        $this->assertFileExists(static::$tmpDir . '/cfg/config.json');
    }

    public function testContentIsPreserved(): void
    {
        $data = json_decode(file_get_contents(static::$tmpDir . '/cfg/config.json'), true);
        $this->assertSame('test', $data['name'] ?? null);
    }

    public function testIsIdempotentWhenConfigJsonExists(): void
    {
        // config.json already exists — running again must not throw.
        M016_RenameAppDataJson::run(static::$manage, static::$tmpDir);
        $this->assertFileExists(static::$tmpDir . '/cfg/config.json');
    }

    public function testDoesNothingWhenAppDataMissing(): void
    {
        $dir2 = sys_get_temp_dir() . '/bdus_m016_nofile_' . uniqid();
        mkdir($dir2 . '/cfg', 0755, true);

        // No app_data.json present — should not throw.
        M016_RenameAppDataJson::run(static::$manage, $dir2);

        $this->assertFileDoesNotExist($dir2 . '/cfg/config.json');

        @rmdir($dir2 . '/cfg');
        @rmdir($dir2);
    }

    // ── Pre-migration fallback (Load::main) ───────────────────────────────────
    // These tests pin the fallback behaviour that allows login to list apps
    // even before M016 has run on a given installation.

    public function testLoadMainReadsConfigJson(): void
    {
        $dir = sys_get_temp_dir() . '/bdus_m016_load_' . uniqid();
        mkdir($dir . '/cfg', 0755, true);
        file_put_contents($dir . '/cfg/config.json', '{"name":"post-m016","status":"on"}');

        $data = Load::main($dir . '/cfg');
        $this->assertSame('post-m016', $data['name']);

        @unlink($dir . '/cfg/config.json');
        @rmdir($dir . '/cfg');
        @rmdir($dir);
    }

    public function testLoadMainFallsBackToAppDataJson(): void
    {
        // Simulates a pre-M016 installation: only app_data.json exists.
        $dir = sys_get_temp_dir() . '/bdus_m016_fallback_' . uniqid();
        mkdir($dir . '/cfg', 0755, true);
        file_put_contents($dir . '/cfg/app_data.json', '{"name":"pre-m016","status":"on"}');

        $data = Load::main($dir . '/cfg');
        $this->assertSame('pre-m016', $data['name'],
            'Load::main must fall back to app_data.json before M016 runs');

        @unlink($dir . '/cfg/app_data.json');
        @rmdir($dir . '/cfg');
        @rmdir($dir);
    }
}
