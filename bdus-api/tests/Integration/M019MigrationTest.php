<?php

namespace Tests\Integration;

use PHPUnit\Framework\TestCase;
use DB\DB;
use DB\System\Manage;
use DB\System\Migrations\M019_AppSettingsToDB;
use Config\AppSettings;
use Monolog\Logger;
use Monolog\Handler\NullHandler;

/**
 * Tests for M019_AppSettingsToDB.
 */
class M019MigrationTest extends TestCase
{
    private static DB     $db;
    private static Manage $manage;

    public static function setUpBeforeClass(): void
    {
        $log = new Logger('test');
        $log->pushHandler(new NullHandler());
        static::$db = new DB('test_m019', ['db_engine' => 'sqlite', 'db_path' => ':memory:']);
        static::$db->setLog($log);
        static::$manage = new Manage(static::$db);
        // Create the table so the migration can use it.
        static::$manage->createTable('bdus_cfg_app');
    }

    private function makeTmpDir(): string
    {
        $dir = sys_get_temp_dir() . '/bdus_m019_' . uniqid();
        mkdir($dir, 0755, true);
        return $dir;
    }

    private function cleanTmpDir(string $dir): void
    {
        foreach (glob($dir . '/{,.}*', GLOB_BRACE) ?: [] as $f) {
            if (!is_dir($f)) @unlink($f);
        }
        if (is_dir($dir . '/templates')) {
            foreach (glob($dir . '/templates/*.twig') ?: [] as $f) @unlink($f);
            @rmdir($dir . '/templates');
        }
        @rmdir($dir);
    }

    // ── Core migration ────────────────────────────────────────────────────────

    public function testCreatesRowInBdusCfgApp(): void
    {
        $dir = $this->makeTmpDir();
        file_put_contents($dir . '/config.json', json_encode([
            'definition'   => 'Test project',
            'status'       => 'on',
            'maxImageSize' => '800',
            'db_engine'    => 'sqlite',
        ]));

        M019_AppSettingsToDB::run(static::$manage, $dir);

        $this->assertTrue(AppSettings::isAvailable(static::$db));
        $settings = AppSettings::get(static::$db);
        $this->assertSame('on',  $settings['status']);
        $this->assertSame(800,   (int) $settings['max_image_size']);

        $this->cleanTmpDir($dir);
    }

    public function testMigratesWelcomeMdToDb(): void
    {
        // Reset DB for this test.
        static::$db->query('DELETE FROM bdus_cfg_app', [], 'boolean');

        $dir = $this->makeTmpDir();
        file_put_contents($dir . '/config.json', json_encode(['db_engine' => 'sqlite']));
        file_put_contents($dir . '/welcome.md', '# Hello');

        M019_AppSettingsToDB::run(static::$manage, $dir);

        $this->assertSame('# Hello', AppSettings::getWelcome(static::$db));
        $this->assertFileDoesNotExist($dir . '/welcome.md');

        $this->cleanTmpDir($dir);
    }

    public function testRewritesConfigJsonWithBootstrapOnly(): void
    {
        static::$db->query('DELETE FROM bdus_cfg_app', [], 'boolean');

        $dir = $this->makeTmpDir();
        file_put_contents($dir . '/config.json', json_encode([
            'definition'   => 'Proj',
            'db_engine'    => 'sqlite',
            'status'       => 'on',
            'maxImageSize' => '1500',
            'lang'         => 'it',
            'name'         => 'testapp',
        ]));

        M019_AppSettingsToDB::run(static::$manage, $dir);

        $cfg = json_decode(file_get_contents($dir . '/config.json'), true);
        $this->assertArrayHasKey('definition',  $cfg);
        $this->assertArrayHasKey('db_engine',   $cfg);
        $this->assertArrayNotHasKey('status',       $cfg, 'status must be removed from config.json');
        $this->assertArrayNotHasKey('maxImageSize', $cfg, 'maxImageSize must be removed from config.json');
        $this->assertArrayNotHasKey('lang',         $cfg, 'lang must be removed from config.json');
        $this->assertArrayNotHasKey('name',         $cfg, 'name must be removed from config.json');

        $this->cleanTmpDir($dir);
    }

    public function testRemovesObsoleteFiles(): void
    {
        static::$db->query('DELETE FROM bdus_cfg_app', [], 'boolean');

        $dir = $this->makeTmpDir();
        file_put_contents($dir . '/config.json', '{"db_engine":"sqlite"}');
        file_put_contents($dir . '/history.log', 'old log');
        mkdir($dir . '/templates', 0755);
        file_put_contents($dir . '/templates/record.twig', '{{ id }}');
        file_put_contents($dir . '/templates/search.twig', '{{ results }}');

        M019_AppSettingsToDB::run(static::$manage, $dir);

        $this->assertFileDoesNotExist($dir . '/history.log');
        $this->assertFileDoesNotExist($dir . '/templates/record.twig');
        $this->assertFileDoesNotExist($dir . '/templates/search.twig');
        $this->assertDirectoryDoesNotExist($dir . '/templates');

        $this->cleanTmpDir($dir);
    }

    public function testIsIdempotent(): void
    {
        // bdus_cfg_app row already exists — second run must be a no-op.
        $dir = $this->makeTmpDir();
        file_put_contents($dir . '/config.json', '{"db_engine":"sqlite"}');

        M019_AppSettingsToDB::run(static::$manage, $dir); // already run above
        M019_AppSettingsToDB::run(static::$manage, $dir); // must not throw

        $this->assertTrue(AppSettings::isAvailable(static::$db));
        $this->cleanTmpDir($dir);
    }
}
