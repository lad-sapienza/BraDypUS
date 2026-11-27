<?php

namespace Tests\Integration;

use Tests\Support\BdusTestCase;

/**
 * Config::save_app_properties() rejects invalid derived-image presets before
 * anything is persisted (so no config file is written by these tests).
 */
class ConfigImagePresetsTest extends BdusTestCase
{
    /** @var array<string,string> fixture config files, restored after a valid save */
    private static array $snapshot = [];
    private static string $dir;

    public static function setUpBeforeClass(): void
    {
        parent::setUpBeforeClass();
        self::$dir = __DIR__ . '/../fixtures/cfg';
        foreach (glob(self::$dir . '/*.json') as $f) {
            self::$snapshot[$f] = file_get_contents($f);
        }
    }

    public static function tearDownAfterClass(): void
    {
        foreach (glob(self::$dir . '/*.json') as $f) {
            if (!isset(self::$snapshot[$f])) {
                unlink($f);
            }
        }
        foreach (self::$snapshot as $f => $c) {
            file_put_contents($f, $c);
        }
        parent::tearDownAfterClass();
    }

    public function testChangedOrRemovedPresetsAreRebuiltUnchangedOnesKept(): void
    {
        $mk = function (string $name): string {
            $d = \Image\Derivative::cacheDir(PROJ_DIR, $name);
            @mkdir($d, 0775, true);
            file_put_contents($d . '/1.webp', 'x');
            return $d . '/1.webp';
        };
        $save = function (array $presets): array {
            $ctrl = $this->makeController('Bdus\\Controllers\\Config', [], ['imagePresets' => $presets]);
            return $this->callController($ctrl, 'save_app_properties');
        };

        $a = ['name' => 'pa', 'width' => 100, 'height' => 100, 'format' => 'webp', 'quality' => 80];
        $b = ['name' => 'pb', 'width' => 200, 'height' => 200, 'format' => 'webp', 'quality' => 80];
        $this->assertSame('success', $save([$a, $b])['status']);

        $fa = $mk('pa');
        $fb = $mk('pb');
        $ft = $mk('thumb');

        // pa unchanged, pb changed, thumb untouched (built in)
        $this->assertSame('success', $save([$a, array_merge($b, ['width' => 300, 'height' => 300])])['status']);
        $this->assertFileExists($fa);
        $this->assertFileDoesNotExist($fb);
        $this->assertFileExists($ft);

        // pa removed
        $this->assertSame('success', $save([array_merge($b, ['width' => 300, 'height' => 300])])['status']);
        $this->assertFileDoesNotExist($fa);

        @unlink($ft);
        $this->assertSame('success', $save([])['status']);
    }

    public function testPurgeImagePresetEmptiesOnePresetOnly(): void
    {
        $mk = function (string $name): string {
            $d = \Image\Derivative::cacheDir(PROJ_DIR, $name);
            @mkdir($d, 0775, true);
            file_put_contents($d . '/1.webp', 'x');
            return $d . '/1.webp';
        };
        $fa = $mk('pa');
        $fb = $mk('pb');

        $res = $this->callController($this->makeController('Bdus\\Controllers\\Config', ['name' => 'pa'], []), 'purgeImagePreset');
        $this->assertSame('success', $res['status']);
        $this->assertSame(1, $res['removed']);
        $this->assertFileDoesNotExist($fa);
        $this->assertFileExists($fb, 'other presets are left alone');

        // A name that is not a valid preset name is refused, nothing is removed
        $bad = $this->callController($this->makeController('Bdus\\Controllers\\Config', ['name' => '../files'], []), 'purgeImagePreset');
        $this->assertSame('error', $bad['status']);
        $this->assertSame('parameter_invalid', $bad['code']);

        \Image\Derivative::purgePreset(PROJ_DIR, 'pb');
    }

    public function testInvalidWatermarkIsRejectedWithACode(): void
    {
        $ctrl = $this->makeController('Bdus\\Controllers\\Config', [], [
            'imagePresets' => [['name' => 'wm', 'width' => 100, 'watermark' => ['text' => '{{nope}}']]],
        ]);
        $res = $this->callController($ctrl, 'save_app_properties');
        $this->assertSame('error', $res['status']);
        $this->assertSame('invalid_image_presets', $res['code']);
    }

    public function testChangingTheWatermarkRebuildsThePresetAndAttributionEditsRegenerateTheVersion(): void
    {
        $save = function (array $presets): array {
            $ctrl = $this->makeController('Bdus\\Controllers\\Config', [], ['imagePresets' => $presets]);
            return $this->callController($ctrl, 'save_app_properties');
        };
        $serve = function (string $uuid): string {
            $ctrl = $this->makeController('Bdus\\Controllers\\File', ['preset' => 'wmp', 'uuid' => $uuid, 'ext' => 'jpg'], []);
            ob_start();
            $ctrl->serveDerived();
            return ob_get_clean();
        };

        $id   = 301;
        $uuid = static::fileUuid($id);
        $src  = PROJ_DIR . "files/{$uuid}.png";
        $im  = imagecreatetruecolor(400, 300);
        imagefill($im, 0, 0, imagecolorallocate($im, 100, 149, 237));
        imagepng($im, $src);
        imagedestroy($im);
        static::$db->query("INSERT INTO bdus_files (id, uuid, ext, filename, creator, attribution) VALUES ({$id}, '{$uuid}', 'png', 'w', 'test', 'Alpha')", [], 'boolean');

        $srcHash = md5_file($src);
        $base = ['name' => 'wmp', 'width' => 400, 'height' => 300, 'fit' => 'inside', 'format' => 'jpg', 'quality' => 95];
        $this->assertSame('success', $save([$base + ['watermark' => ['text' => '© {{attribution}}']]])['status']);
        $cached = \Image\Derivative::cachePath(PROJ_DIR, 'wmp', $uuid, 'jpg');

        $first = $serve($uuid);
        $this->assertFileExists($cached);

        // Attribution edited: the cached version is purged and rebuilt with the new text
        $upd = $this->makeController('Bdus\\Controllers\\File', ['fileId' => $id], ['attribution' => 'Beta, a longer credit']);
        $this->assertSame('success', $this->callController($upd, 'updateFile')['status']);
        $this->assertFileDoesNotExist($cached);
        $second = $serve($uuid);
        $this->assertNotSame(md5($first), md5($second));

        // Preset watermark edited: the preset folder is purged
        $this->assertSame('success', $save([$base + ['watermark' => ['text' => '© {{attribution}}', 'position' => 'top-left']]])['status']);
        $this->assertFileDoesNotExist($cached);
        $third = $serve($uuid);
        $this->assertNotSame(md5($second), md5($third));

        // Watermark removed: the version is plain again; the original never changed
        $this->assertSame('success', $save([$base])['status']);
        $this->assertFileDoesNotExist($cached);
        $this->assertSame($srcHash, md5_file($src), 'the original is never modified');

        \Image\Derivative::purgeFile(PROJ_DIR, $uuid);
        @unlink($src);
        $this->assertSame('success', $save([])['status']);
    }

    public function testInvalidPresetsAreRejectedWithACode(): void
    {
        $ctrl = $this->makeController('Bdus\\Controllers\\Config', [], [
            'imagePresets' => [['name' => 'Bad Name', 'width' => 100]],
        ]);
        $res = $this->callController($ctrl, 'save_app_properties');

        $this->assertSame('error', $res['status']);
        $this->assertSame('invalid_image_presets', $res['code']);
        $this->assertArrayHasKey('detail', $res);
    }

    // ── the administration screen lists every preset, with its access and state ──

    private function listed(): array
    {
        $res = $this->callController($this->makeController('Bdus\\Controllers\\Config'), 'getAppProperties');
        $this->assertSame('success', $res['status'], json_encode($res));
        return array_column($res['main']['imagePresets'], null, 'name');
    }

    public function testTheBuiltInThumbIsAlwaysListedWithItsAccessAndState(): void
    {
        $this->callController($this->makeController('Bdus\\Controllers\\Config', [], ['imagePresets' => []]), 'save_app_properties');

        $thumb = $this->listed()['thumb'];

        $this->assertSame('inherit', $thumb['access'], 'the built-in thumb follows the original');
        $this->assertTrue($thumb['enabled']);
    }

    public function testEveryPresetIsListedWithWhetherItIsPublicAndEnabled(): void
    {
        $save = $this->callController($this->makeController('Bdus\\Controllers\\Config', [], ['imagePresets' => [
            ['name' => 'site', 'width' => 600, 'access' => 'public'],
            ['name' => 'hidden', 'width' => 300, 'access' => 'inherit', 'enabled' => false],
        ]]), 'save_app_properties');
        $this->assertSame('success', $save['status'], json_encode($save));

        $listed = $this->listed();

        $this->assertSame(['thumb', 'site', 'hidden'], array_keys($listed));
        $this->assertSame('public', $listed['site']['access']);
        $this->assertTrue($listed['site']['enabled']);
        $this->assertFalse($listed['hidden']['enabled']);

        $this->callController($this->makeController('Bdus\\Controllers\\Config', [], ['imagePresets' => []]), 'save_app_properties');
    }

    public function testAnInvalidAccessIsRefusedWhenSaving(): void
    {
        $res = $this->callController($this->makeController('Bdus\\Controllers\\Config', [], ['imagePresets' => [
            ['name' => 'x', 'width' => 100, 'access' => 'everyone'],
        ]]), 'save_app_properties');

        $this->assertSame('invalid_image_presets', $res['code']);
    }
}
