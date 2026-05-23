<?php

namespace Tests\Integration;

use PHPUnit\Framework\TestCase;
use DB\DB;
use DB\System\Manage;
use DB\System\Migrations\M017_CleanupCfgDir;
use DB\System\Migrations\M018_MoveConfigToRoot;
use Config\Load;
use Monolog\Logger;
use Monolog\Handler\NullHandler;

class M017M018MigrationTest extends TestCase
{
    private static DB     $db;
    private static Manage $manage;

    public static function setUpBeforeClass(): void
    {
        $log = new Logger('test');
        $log->pushHandler(new NullHandler());
        static::$db = new DB('test_m017m018', ['db_engine' => 'sqlite', 'db_path' => ':memory:']);
        static::$db->setLog($log);
        static::$manage = new Manage(static::$db);
    }

    private function makeTmpDir(): string
    {
        $dir = sys_get_temp_dir() . '/bdus_m01718_' . uniqid();
        mkdir($dir . '/cfg', 0755, true);
        return $dir;
    }

    private function cleanTmpDir(string $dir): void
    {
        foreach (glob($dir . '/{,.}*', GLOB_BRACE) ?: [] as $f) {
            if (!is_dir($f)) @unlink($f);
        }
        foreach (glob($dir . '/cfg/{,.}*', GLOB_BRACE) ?: [] as $f) {
            if (!is_dir($f)) @unlink($f);
        }
        @rmdir($dir . '/cfg');
        @rmdir($dir);
    }

    // ── M017: cleanup stray cfg/*.json ────────────────────────────────────

    public function testM017DeletesStrayJsonFiles(): void
    {
        $dir = $this->makeTmpDir();
        file_put_contents($dir . '/cfg/config.json',  '{"name":"test"}');
        file_put_contents($dir . '/cfg/files.json',   '[]');
        file_put_contents($dir . '/cfg/tables.json',  '{}');
        file_put_contents($dir . '/cfg/.htaccess',    'Deny from all');

        M017_CleanupCfgDir::run(static::$manage, $dir);

        $this->assertFileDoesNotExist($dir . '/cfg/files.json');
        $this->assertFileDoesNotExist($dir . '/cfg/tables.json');
        $this->assertFileExists($dir . '/cfg/config.json', 'config.json must not be deleted');
        $this->assertFileExists($dir . '/cfg/.htaccess',   '.htaccess must not be deleted');

        $this->cleanTmpDir($dir);
    }

    public function testM017IsIdempotent(): void
    {
        $dir = $this->makeTmpDir();
        file_put_contents($dir . '/cfg/config.json', '{"name":"test"}');

        M017_CleanupCfgDir::run(static::$manage, $dir);
        M017_CleanupCfgDir::run(static::$manage, $dir); // second run must not throw

        $this->assertFileExists($dir . '/cfg/config.json');
        $this->cleanTmpDir($dir);
    }

    // ── M018: move config.json to project root ────────────────────────────

    public function testM018MovesConfigJsonToRoot(): void
    {
        $dir = $this->makeTmpDir();
        file_put_contents($dir . '/cfg/config.json',  '{"name":"test","status":"on"}');
        file_put_contents($dir . '/cfg/.jwt_secret',  'secretvalue');
        file_put_contents($dir . '/cfg/.htaccess',    'Deny from all');

        M018_MoveConfigToRoot::run(static::$manage, $dir);

        $this->assertFileExists($dir . '/config.json',   'config.json must be at project root');
        $this->assertFileExists($dir . '/.jwt_secret',   '.jwt_secret must be at project root');
        $this->assertFileDoesNotExist($dir . '/cfg/config.json');
        $this->assertFileDoesNotExist($dir . '/cfg/.jwt_secret');

        $this->cleanTmpDir($dir);
    }

    public function testM018WritesProjectHtaccess(): void
    {
        $dir = $this->makeTmpDir();
        file_put_contents($dir . '/cfg/config.json', '{"name":"test"}');

        M018_MoveConfigToRoot::run(static::$manage, $dir);

        $this->assertFileExists($dir . '/.htaccess');
        $htaccess = file_get_contents($dir . '/.htaccess');
        $this->assertStringContainsString('config.json', $htaccess);
        $this->assertStringContainsString('.jwt_secret',  $htaccess);
        $this->assertStringContainsString('Deny from all', $htaccess);

        $this->cleanTmpDir($dir);
    }

    public function testM018IsIdempotent(): void
    {
        $dir = $this->makeTmpDir();
        file_put_contents($dir . '/cfg/config.json', '{"name":"test"}');
        M018_MoveConfigToRoot::run(static::$manage, $dir);

        // Second run: config.json already at root — must not throw or duplicate.
        M018_MoveConfigToRoot::run(static::$manage, $dir);
        $this->assertFileExists($dir . '/config.json');

        $this->cleanTmpDir($dir);
    }

    public function testM018RemovesCfgDirectoryWhenEmpty(): void
    {
        $dir = $this->makeTmpDir();
        file_put_contents($dir . '/cfg/config.json', '{"name":"test"}');
        // cfg/ only has config.json and .htaccess (which M018 removes).

        M018_MoveConfigToRoot::run(static::$manage, $dir);

        $this->assertDirectoryDoesNotExist($dir . '/cfg');

        $this->cleanTmpDir($dir);
    }

    // ── Load::resolveMainConfig fallback chain ────────────────────────────

    public function testLoadMainReadsFromProjectRoot(): void
    {
        $dir = sys_get_temp_dir() . '/bdus_load_root_' . uniqid();
        mkdir($dir, 0755, true);
        file_put_contents($dir . '/config.json', '{"name":"at-root","status":"on"}');

        $data = Load::main($dir);
        $this->assertSame('at-root', $data['name']);

        @unlink($dir . '/config.json');
        @rmdir($dir);
    }

    public function testLoadMainFallsBackToCfgConfigJson(): void
    {
        $dir = sys_get_temp_dir() . '/bdus_load_cfg_' . uniqid();
        mkdir($dir . '/cfg', 0755, true);
        file_put_contents($dir . '/cfg/config.json', '{"name":"in-cfg","status":"on"}');

        $data = Load::main($dir);
        $this->assertSame('in-cfg', $data['name']);

        @unlink($dir . '/cfg/config.json');
        @rmdir($dir . '/cfg');
        @rmdir($dir);
    }
}
