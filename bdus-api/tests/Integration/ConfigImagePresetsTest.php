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
}
