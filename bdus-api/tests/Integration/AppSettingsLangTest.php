<?php

declare(strict_types=1);

namespace Tests\Integration;

use Config\AppSettings;
use DB\System\Manage;
use Tests\Support\BdusTestCase;

/**
 * Integration tests for the `lang` column of bdus_cfg_app (M042).
 *
 * Previously ConfigAppForm.vue's "Language" field bound to a value that was
 * never read from or written to any backend store — AppSettings::get()/save()
 * didn't know about it, and neither did the migration. These tests cover the
 * round trip: AppSettings::save() persists it, AppSettings::get() reads it
 * back, and Controllers\Info::getAppInfo() (the endpoint AppLayout.vue calls
 * on every page load, any privilege level) exposes it.
 */
class AppSettingsLangTest extends BdusTestCase
{
    public static function setUpBeforeClass(): void
    {
        parent::setUpBeforeClass();

        // bdus_cfg_app is already created (empty) by BdusTestCase::createSchema()
        // via Manage::$available_tables — seed the single settings row that
        // CreateApp would normally create, same as FrontpageEditorCtrlTest.
        $manage = new Manage(static::$db);
        $manage->createTable('bdus_cfg_app');
        static::$db->query(
            'INSERT INTO bdus_cfg_app (id, status, max_image_size, welcome, color, lang) VALUES (?, ?, ?, ?, ?, ?)',
            [1, 'on', 1500, '', 'indigo', 'en'],
            'boolean'
        );
    }

    // Restore a known baseline between tests that mutate lang.
    protected function setUp(): void
    {
        static::$db->query('UPDATE bdus_cfg_app SET lang = ? WHERE id = 1', ['en'], 'boolean');
    }

    public function testAppSettingsGetDefaultsToEnglish(): void
    {
        $this->assertSame('en', AppSettings::get(static::$db)['lang']);
    }

    public function testAppSettingsSavePersistsLang(): void
    {
        AppSettings::save(static::$db, ['lang' => 'it']);

        $this->assertSame('it', AppSettings::get(static::$db)['lang']);
    }

    public function testAppSettingsSaveIgnoresUnknownLang(): void
    {
        // save() only accepts known keys — a bogus locale code should still be
        // written as-is (no server-side whitelist of locale codes); the
        // frontend's <ASelect> is what constrains valid choices.
        AppSettings::save(static::$db, ['lang' => 'xx']);

        $this->assertSame('xx', AppSettings::get(static::$db)['lang']);
    }

    public function testGetAppInfoReturnsSavedLang(): void
    {
        AppSettings::save(static::$db, ['lang' => 'it']);

        $ctrl = $this->makeController('Bdus\\Controllers\\Info');
        $res  = $this->callController($ctrl, 'getAppInfo');

        $this->assertSame('it', $res['lang']);
    }

    public function testGetAppInfoAccessibleToReadPrivilege(): void
    {
        $this->setPrivilege(4); // reader
        $ctrl = $this->makeController('Bdus\\Controllers\\Info');
        $res  = $this->callController($ctrl, 'getAppInfo');
        $this->setPrivilege(1);

        $this->assertSame('success', $res['status']);
        $this->assertArrayHasKey('lang', $res);
    }
}
