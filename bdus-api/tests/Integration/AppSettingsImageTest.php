<?php

declare(strict_types=1);

namespace Tests\Integration;

use Config\AppSettings;
use DB\System\Manage;
use Tests\Support\BdusTestCase;

/**
 * Integration tests for the image_convert/image_format/image_quality/image_dpi
 * columns of bdus_cfg_app (M045, issue tracker #55) — the round trip:
 * AppSettings::save() persists them, AppSettings::get() reads them back with
 * sane defaults/clamping. Mirrors AppSettingsLangTest.php (M042).
 */
class AppSettingsImageTest extends BdusTestCase
{
    public static function setUpBeforeClass(): void
    {
        parent::setUpBeforeClass();

        $manage = new Manage(static::$db);
        $manage->createTable('bdus_cfg_app');
        static::$db->query(
            'INSERT INTO bdus_cfg_app (id, status, max_image_size, welcome, color, lang) VALUES (?, ?, ?, ?, ?, ?)',
            [1, 'on', 1500, '', 'indigo', 'en'],
            'boolean'
        );
    }

    protected function setUp(): void
    {
        static::$db->query(
            'UPDATE bdus_cfg_app SET image_convert = 0, image_format = ?, image_quality = 85, image_dpi = 72 WHERE id = 1',
            ['webp'],
            'boolean'
        );
    }

    public function testDefaultsToConvertDisabledWebp85Dpi72(): void
    {
        $settings = AppSettings::get(static::$db);

        $this->assertSame(0, (int) $settings['image_convert']);
        $this->assertSame('webp', $settings['image_format']);
        $this->assertSame(85, (int) $settings['image_quality']);
        $this->assertSame(72, (int) $settings['image_dpi']);
    }

    public function testSavePersistsImageConversionSettings(): void
    {
        AppSettings::save(static::$db, [
            'image_convert' => true,
            'image_format'  => 'jpg',
            'image_quality' => 60,
            'image_dpi'     => 150,
        ]);

        $settings = AppSettings::get(static::$db);
        $this->assertSame(1, (int) $settings['image_convert']);
        $this->assertSame('jpg', $settings['image_format']);
        $this->assertSame(60, (int) $settings['image_quality']);
        $this->assertSame(150, (int) $settings['image_dpi']);
    }

    public function testSaveClampsQualityToValidRange(): void
    {
        AppSettings::save(static::$db, ['image_quality' => 500]);
        $this->assertSame(100, (int) AppSettings::get(static::$db)['image_quality']);

        AppSettings::save(static::$db, ['image_quality' => -5]);
        $this->assertSame(1, (int) AppSettings::get(static::$db)['image_quality']);
    }

    public function testSaveRejectsUnknownFormat(): void
    {
        // Only webp/jpg are supported server-side (the frontend <ASelect>
        // only offers those two) — anything else falls back to webp.
        AppSettings::save(static::$db, ['image_format' => 'gif']);
        $this->assertSame('webp', AppSettings::get(static::$db)['image_format']);
    }
}
