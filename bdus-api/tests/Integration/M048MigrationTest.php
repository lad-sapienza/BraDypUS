<?php

namespace Tests\Integration;

use Config\AppSettings;
use DB\System\Manage;
use DB\System\Migrations\M048_AddImagePresetsToCfgApp;
use Image\Presets;
use Tests\Support\BdusTestCase;

/**
 * M048 (bdus_cfg_app.image_presets) and the AppSettings presets round trip,
 * including the guarantee that a pending M048 does not degrade the other settings.
 */
class M048MigrationTest extends BdusTestCase
{
    public static function setUpBeforeClass(): void
    {
        parent::setUpBeforeClass();
    }

    protected function setUp(): void
    {
        static::$db->exec('DROP TABLE IF EXISTS bdus_cfg_app');
        // The pre-M048 shape: no image_presets column.
        static::$db->exec(
            "CREATE TABLE bdus_cfg_app (id INTEGER PRIMARY KEY, status TEXT NOT NULL DEFAULT 'on', "
            . "max_image_size INTEGER NOT NULL DEFAULT 0, welcome TEXT, color TEXT, bdus_version TEXT, "
            . "allow_self_registration INTEGER NOT NULL DEFAULT 0, lang TEXT NOT NULL DEFAULT 'en', "
            . "image_convert INTEGER NOT NULL DEFAULT 0, image_format TEXT NOT NULL DEFAULT 'webp', "
            . "image_quality INTEGER NOT NULL DEFAULT 85, image_dpi INTEGER NOT NULL DEFAULT 72)"
        );
        static::$db->query(
            "INSERT INTO bdus_cfg_app (id, status, max_image_size, welcome, color, lang) VALUES (1, 'off', 1500, '', 'indigo', 'it')",
            [],
            'boolean'
        );
    }

    public function testBeforeMigrationPresetsAreEmptyAndOtherSettingsUnaffected(): void
    {
        $this->assertSame([], AppSettings::getImagePresets(static::$db));
        AppSettings::saveImagePresets(static::$db, [['name' => 'x']]); // must not throw

        $s = AppSettings::get(static::$db);
        $this->assertSame('off', $s['status']);
        $this->assertSame(1500, (int) $s['max_image_size']);
    }

    public function testMigrationAddsColumnAndIsIdempotent(): void
    {
        $manage = new Manage(static::$db);
        $this->assertFalse($manage->columnExists('bdus_cfg_app', 'image_presets'));

        M048_AddImagePresetsToCfgApp::run($manage);
        M048_AddImagePresetsToCfgApp::run($manage);

        $this->assertTrue($manage->columnExists('bdus_cfg_app', 'image_presets'));
        $this->assertSame([], AppSettings::getImagePresets(static::$db), 'NULL = no custom presets');
    }

    public function testPresetsRoundTripAfterMigration(): void
    {
        M048_AddImagePresetsToCfgApp::run(new Manage(static::$db));
        $list = Presets::normalize([
            ['name' => 'medium', 'width' => 800],
            ['name' => '200x200', 'width' => 200, 'height' => 200, 'format' => 'jpg', 'quality' => 70],
        ]);

        AppSettings::saveImagePresets(static::$db, $list);

        $this->assertSame($list, AppSettings::getImagePresets(static::$db));
        $this->assertSame('off', AppSettings::get(static::$db)['status'], 'other settings untouched');
    }
}
