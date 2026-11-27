<?php

namespace Tests\Integration;

use Config\AppSettings;
use DB\DB;
use DB\System\Manage;
use DB\System\Migrations\M062_AddAccessToImagePresets;
use Image\Presets;
use Monolog\Handler\NullHandler;
use Monolog\Logger;
use PHPUnit\Framework\TestCase;

/**
 * M062: existing presets keep what is published today (public, enabled); the built-in thumb, never
 * stored before, is written out as a public preset. Idempotent, and never overwrites a choice.
 */
class M062MigrationTest extends TestCase
{
    private DB $db;
    private Manage $manage;

    protected function setUp(): void
    {
        $log = new Logger('t');
        $log->pushHandler(new NullHandler());
        $this->db = new DB('m062_' . uniqid(), ['db_engine' => 'sqlite', 'db_path' => ':memory:']);
        $this->db->setLog($log);
        $this->manage = new Manage($this->db);
        $this->manage->createTable('bdus_cfg_app');
        $this->db->query("INSERT INTO bdus_cfg_app (id, status, max_image_size, welcome) VALUES (1, 'on', 0, '')", [], 'boolean');
    }

    private function stored(): array
    {
        return AppSettings::getImagePresets($this->db);
    }

    private function byName(): array
    {
        return array_column($this->stored(), null, 'name');
    }

    /** An application that already has uploaded files (so something may be published through thumb). */
    private function withFiles(): void
    {
        $this->manage->createTable('bdus_files');
        $this->db->query("INSERT INTO bdus_files (creator, ext, filename) VALUES ('x', 'jpg', 'a')", [], 'boolean');
    }

    public function testABrandNewApplicationKeepsTheDefaults(): void
    {
        $this->manage->createTable('bdus_files');   // exists, but empty: nothing is published

        M062_AddAccessToImagePresets::run($this->manage);

        $this->assertSame([], $this->stored(), 'nothing stored: the built-in thumb stays what the code says (it follows the original)');
        $this->assertSame('inherit', Presets::resolve($this->stored())['thumb']['access']);
    }

    public function testNothingStoredButFilesBecomesAnExplicitPublicThumb(): void
    {
        $this->withFiles();
        M062_AddAccessToImagePresets::run($this->manage);

        $this->assertSame(['thumb'], array_keys($this->byName()));
        $thumb = $this->byName()['thumb'];
        $this->assertSame('public', $thumb['access'], 'it was public: published sites keep working');
        $this->assertTrue($thumb['enabled']);
        $this->assertSame(200, $thumb['width']);
    }

    public function testExistingPresetsBecomePublicAndEnabledAndThumbIsAdded(): void
    {
        $this->withFiles();
        AppSettings::saveImagePresets($this->db, [['name' => 'medium', 'width' => 800, 'height' => null, 'fit' => 'inside', 'format' => 'webp', 'quality' => 80]]);

        M062_AddAccessToImagePresets::run($this->manage);

        $presets = $this->byName();
        $this->assertSame(['thumb', 'medium'], array_keys($presets));
        $this->assertSame('public', $presets['medium']['access']);
        $this->assertTrue($presets['medium']['enabled']);
        $this->assertSame(800, $presets['medium']['width'], 'the rest is untouched');
    }

    public function testAnExplicitChoiceIsNeverOverwritten(): void
    {
        AppSettings::saveImagePresets($this->db, [
            ['name' => 'thumb', 'width' => 100, 'height' => 100, 'fit' => 'inside', 'format' => 'webp', 'quality' => 80, 'access' => 'inherit', 'enabled' => true],
            ['name' => 'big', 'width' => 2000, 'access' => 'inherit', 'enabled' => false],
        ]);

        M062_AddAccessToImagePresets::run($this->manage);

        $presets = $this->byName();
        $this->assertSame('inherit', $presets['thumb']['access']);
        $this->assertSame(100, $presets['thumb']['width'], 'an explicit thumb is not replaced');
        $this->assertSame('inherit', $presets['big']['access']);
        $this->assertFalse($presets['big']['enabled']);
    }

    public function testIsIdempotent(): void
    {
        $this->withFiles();
        AppSettings::saveImagePresets($this->db, [['name' => 'medium', 'width' => 800]]);
        M062_AddAccessToImagePresets::run($this->manage);
        $first = $this->stored();

        M062_AddAccessToImagePresets::run($this->manage);

        $this->assertSame($first, $this->stored());
    }

    public function testTheStoredListStillPassesValidation(): void
    {
        $this->withFiles();
        AppSettings::saveImagePresets($this->db, [['name' => 'medium', 'width' => 800, 'height' => null, 'fit' => 'inside', 'format' => 'webp', 'quality' => 80]]);
        M062_AddAccessToImagePresets::run($this->manage);

        $normalised = Presets::normalize($this->stored());

        $this->assertSame(['thumb', 'medium'], array_column($normalised, 'name'));
        $this->assertSame(['public', 'public'], array_column($normalised, 'access'));
    }

    public function testAnAppWithoutThePresetsColumnIsLeftAlone(): void
    {
        $this->db->exec('DROP TABLE bdus_cfg_app');
        $this->manage->createTable('bdus_cfg_app');
        // (the column exists in the current structure: simulate an older app by recreating without it)
        $this->db->exec('DROP TABLE bdus_cfg_app');
        $this->db->exec('CREATE TABLE bdus_cfg_app (id INTEGER PRIMARY KEY, status TEXT)');

        M062_AddAccessToImagePresets::run($this->manage);

        $this->assertFalse($this->manage->columnExists('bdus_cfg_app', 'image_presets'));
    }

    public function testIsRegistered(): void
    {
        $this->assertContains(M062_AddAccessToImagePresets::class, \DB\System\Migrate::ALL_MIGRATIONS);
    }
}
