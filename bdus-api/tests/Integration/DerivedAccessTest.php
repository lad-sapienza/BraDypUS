<?php

namespace Tests\Integration;

use Image\Derivative;
use Tests\Support\BdusTestCase;

/**
 * Who is sent a derived image: anyone, only when the preset is `public` AND the file is marked
 * public; otherwise only who may read the original (a token or the session cookie identifies them).
 * A disabled preset is not served to anyone. Every refusal is a plain 404.
 *
 * File 850 is attached to items 1. Presets: `pub` (public), `priv` (inherit), `off` (public, disabled).
 */
class DerivedAccessTest extends BdusTestCase
{
    private string $uuid;

    /** save_app_properties() writes tests/fixtures/cfg/*.json, shared by every test: put them back. */
    private static array $snapshot = [];

    public static function setUpBeforeClass(): void
    {
        parent::setUpBeforeClass();
        foreach (glob(__DIR__ . '/../fixtures/cfg/*.json') as $f) {
            self::$snapshot[$f] = file_get_contents($f);
        }
    }

    public static function tearDownAfterClass(): void
    {
        foreach (glob(__DIR__ . '/../fixtures/cfg/*.json') as $f) {
            if (!isset(self::$snapshot[$f])) {
                unlink($f);
            }
        }
        foreach (self::$snapshot as $f => $c) {
            file_put_contents($f, $c);
        }
        parent::tearDownAfterClass();
    }

    protected function setUp(): void
    {
        $this->setPrivilege(1);
        $this->uuid = static::insertFile(850, 'png', 'a');
        $im = imagecreatetruecolor(300, 200);
        imagefill($im, 0, 0, imagecolorallocate($im, 30, 90, 200));
        imagepng($im, PROJ_DIR . "files/{$this->uuid}.png");
        imagedestroy($im);
        static::$db->exec("INSERT INTO bdus_file_links (file_id, table_name, record_id, sort) VALUES (850, 'items', 1, 1)");
        static::$db->exec("INSERT INTO bdus_users (id, name, email, password, privilege) VALUES (74, 'da', 'da74@example.com', 'x', 39)");

        $save = $this->callController($this->makeController('Bdus\\Controllers\\Config', [], ['imagePresets' => [
            ['name' => 'pub',  'width' => 100, 'access' => 'public'],
            ['name' => 'priv', 'width' => 100, 'access' => 'inherit'],
            ['name' => 'off',  'width' => 100, 'access' => 'public', 'enabled' => false],
        ]]), 'save_app_properties');
        $this->assertSame('success', $save['status'], json_encode($save));
    }

    protected function tearDown(): void
    {
        Derivative::purgeFile(PROJ_DIR, $this->uuid);
        @unlink(PROJ_DIR . "files/{$this->uuid}.png");
        static::$db->exec('DELETE FROM bdus_file_links WHERE file_id = 850');
        static::$db->exec('DELETE FROM bdus_files WHERE id = 850');
        static::$db->exec('DELETE FROM bdus_user_groups');
        static::$db->exec('DELETE FROM bdus_group_rules');
        static::$db->exec('DELETE FROM bdus_groups');
        static::$db->exec('DELETE FROM bdus_users WHERE id = 74');
        $this->callController($this->makeController('Bdus\\Controllers\\Config', [], ['imagePresets' => []]), 'save_app_properties');
        \Auth\CurrentUser::set([]);
        $this->setPrivilege(1);
    }

    private function anonymous(): void
    {
        \Auth\CurrentUser::set([]);
    }

    private function reader(): void
    {
        \Auth\CurrentUser::set(['id' => 74, 'name' => 'da', 'email' => 'da74@example.com', 'privilege' => 30, 'app' => 'test']);
    }

    /** Someone who may read `categories` only: not the file, which is on items. */
    private function scoped(): void
    {
        $g = (int) static::$db->query("INSERT INTO bdus_groups (name) VALUES ('G')", [], 'id');
        static::$db->exec("INSERT INTO bdus_group_rules (group_id, table_name, action) VALUES ({$g}, 'categories', 'read')");
        static::$db->exec("INSERT INTO bdus_user_groups (user_id, group_id) VALUES (74, {$g})");
        \Auth\CurrentUser::set(['id' => 74, 'name' => 'da', 'email' => 'da74@example.com', 'privilege' => 39, 'app' => 'test']);
    }

    private function makePublic(bool $on): void
    {
        static::$db->exec('UPDATE bdus_files SET public = ' . ($on ? 1 : 0) . ' WHERE id = 850');
    }

    private function serve(string $preset): int
    {
        http_response_code(200);
        $ctrl = $this->makeController('Bdus\\Controllers\\File', ['preset' => $preset, 'uuid' => $this->uuid, 'ext' => 'webp'], []);
        ob_start();
        $ctrl->serveDerived();
        $body = ob_get_clean();
        $code = http_response_code();
        return $body === 'Not found' ? 404 : ($code === false ? 200 : $code);
    }

    // ── public preset ─────────────────────────────────────────────────────────

    public function testAPublicPresetSendsAPublicFileToAnyone(): void
    {
        $this->makePublic(true);
        $this->anonymous();
        $this->assertSame(200, $this->serve('pub'));
    }

    public function testAPublicPresetDoesNotSendAFileNotMarkedPublicToAnonymous(): void
    {
        $this->makePublic(false);
        $this->anonymous();
        $this->assertSame(404, $this->serve('pub'), 'the preset alone publishes nothing: the file must be public too');
    }

    public function testAFileNotMarkedPublicStillGoesToWhoMayReadTheOriginal(): void
    {
        $this->makePublic(false);
        $this->reader();
        $this->assertSame(200, $this->serve('pub'));
        $this->setPrivilege(1);
        $this->assertSame(200, $this->serve('pub'), 'an administrator too');
    }

    // ── inherit preset ────────────────────────────────────────────────────────

    public function testAnInheritPresetNeverGoesToAnonymousNotEvenForAPublicFile(): void
    {
        $this->makePublic(true);
        $this->anonymous();
        $this->assertSame(404, $this->serve('priv'), 'only a public PRESET lets anonymous see anything');
    }

    public function testAnInheritPresetGoesToWhoMayReadTheOriginal(): void
    {
        $this->makePublic(false);
        $this->reader();
        $this->assertSame(200, $this->serve('priv'));
    }

    public function testAnInheritPresetIsRefusedToSomeoneWhoCannotReadTheFile(): void
    {
        $this->makePublic(false);
        $this->scoped();
        $this->assertSame(404, $this->serve('priv'), 'signed in is not enough: the file is on a table this person cannot read');
    }

    public function testASignedInPersonWhoCannotReadTheFileGetsNothingMoreThanAnonymousForAPrivateFile(): void
    {
        $this->makePublic(false);
        $this->scoped();
        $this->assertSame(404, $this->serve('pub'));
    }

    public function testAPublicFileOnAPublicPresetStillGoesToAScopedPerson(): void
    {
        $this->makePublic(true);
        $this->scoped();
        $this->assertSame(200, $this->serve('pub'), 'it is public: the same as for anyone');
    }

    // ── disabled preset ───────────────────────────────────────────────────────

    public function testADisabledPresetIsServedToNobody(): void
    {
        $this->makePublic(true);
        foreach (['anonymous', 'reader'] as $who) {
            $this->$who();
            $this->assertSame(404, $this->serve('off'), $who);
        }
        $this->setPrivilege(1);
        $this->assertSame(404, $this->serve('off'), 'not even an administrator');
    }

    public function testDisablingKeepsTheDefinitionAndTheCache(): void
    {
        $this->setPrivilege(1);
        $this->makePublic(true);
        $this->assertSame(200, $this->serve('pub'));
        $cached = Derivative::cachePath(PROJ_DIR, 'pub', $this->uuid, 'webp');
        $this->assertFileExists($cached);

        $save = $this->callController($this->makeController('Bdus\\Controllers\\Config', [], ['imagePresets' => [
            ['name' => 'pub', 'width' => 100, 'access' => 'public', 'enabled' => false],
        ]]), 'save_app_properties');
        $this->assertSame('success', $save['status'], json_encode($save));

        $this->assertFileExists($cached, 'switching a preset off must not throw its cache away');
        $this->assertSame(404, $this->serve('pub'));
        $this->assertArrayHasKey('pub', \Image\Presets::resolve((array) static::$cfg->get('main.imagePresets')), 'the definition stays');
    }

    public function testChangingWhoMayBeSentAPresetKeepsItsCacheButChangingItsSizeDoesNot(): void
    {
        $this->makePublic(true);
        $this->serve('pub');
        $cached = Derivative::cachePath(PROJ_DIR, 'pub', $this->uuid, 'webp');
        $this->assertFileExists($cached);

        $this->callController($this->makeController('Bdus\\Controllers\\Config', [], ['imagePresets' => [
            ['name' => 'pub', 'width' => 100, 'access' => 'inherit'],
        ]]), 'save_app_properties');
        $this->assertFileExists($cached, 'the images are the same');

        $this->callController($this->makeController('Bdus\\Controllers\\Config', [], ['imagePresets' => [
            ['name' => 'pub', 'width' => 120, 'access' => 'inherit'],
        ]]), 'save_app_properties');
        $this->assertFileDoesNotExist($cached, 'a different size invalidates it');
    }

    // ── the rest still holds ──────────────────────────────────────────────────

    public function testAnUnknownPresetOrAddressIsStill404ForEveryone(): void
    {
        $this->makePublic(true);
        $this->anonymous();
        $this->assertSame(404, $this->serve('nope'));
    }
}
