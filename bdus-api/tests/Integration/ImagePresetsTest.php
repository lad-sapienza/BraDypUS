<?php

namespace Tests\Integration;

use PHPUnit\Framework\TestCase;
use Image\Presets;
use PHPUnit\Framework\Attributes\DataProvider;

/**
 * Image\Presets: validation, normalisation, built-in `thumb`, lookup.
 */
class ImagePresetsTest extends TestCase
{
    private function p(array $over = []): array
    {
        return array_merge(['name' => 'medium', 'width' => 800, 'height' => 600, 'fit' => 'cover', 'format' => 'webp', 'quality' => 80], $over);
    }

    public function testNormalizeAppliesDefaultsAndDropsUnknownKeys(): void
    {
        $out = Presets::normalize([['name' => 'small', 'width' => '300', 'evil' => 'x']]);

        $this->assertSame([[
            'name' => 'small', 'width' => 300, 'height' => null, 'fit' => 'inside', 'format' => 'webp', 'quality' => 80,
            'access' => 'inherit', 'enabled' => true,
        ]], $out);
    }

    public function testFitDefaultsToCoverWithBothSidesAndInsideOtherwise(): void
    {
        $out = Presets::normalize([
            ['name' => 'a', 'width' => 200, 'height' => 100],
            ['name' => 'b', 'height' => 100],
            ['name' => 'c', 'width' => 200, 'height' => 100, 'fit' => 'inside'],
        ]);
        $this->assertSame(['cover', 'inside', 'inside'], array_column($out, 'fit'));
    }

    #[DataProvider('invalidNames')]
    public function testRejectsInvalidNames(mixed $name): void
    {
        $this->expectException(\InvalidArgumentException::class);
        Presets::normalize([$this->p(['name' => $name])]);
    }

    public static function invalidNames(): array
    {
        return [
            'empty'      => [''],
            'uppercase'  => ['Thumb'],
            'dot'        => ['a.b'],
            'slash'      => ['a/b'],
            'traversal'  => ['../x'],
            'space'      => ['a b'],
            'non-ascii'  => ['200×200'],
            'leading -'  => ['-a'],
            'too long'   => [str_repeat('a', 33)],
            'not string' => [123],
            'null'       => [null],
        ];
    }

    public function testAcceptsAsciiNamesLike200x200(): void
    {
        $out = Presets::normalize([$this->p(['name' => '200x200']), $this->p(['name' => 'web_large-1'])]);
        $this->assertSame(['200x200', 'web_large-1'], array_column($out, 'name'));
    }

    public function testRejectsDuplicateNames(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        Presets::normalize([$this->p(), $this->p()]);
    }

    #[DataProvider('invalidValues')]
    public function testRejectsInvalidValues(array $over): void
    {
        $this->expectException(\InvalidArgumentException::class);
        Presets::normalize([$this->p($over)]);
    }

    public static function invalidValues(): array
    {
        return [
            'width too small'  => [['width' => 15]],
            'width too big'    => [['width' => 10001]],
            'height text'      => [['height' => 'big']],
            'no side at all'   => [['width' => null, 'height' => null]],
            'cover needs both' => [['width' => 300, 'height' => null, 'fit' => 'cover']],
            'unknown fit'      => [['fit' => 'stretch']],
            'area too large'   => [['width' => 10000, 'height' => 10000]],
            'format png'       => [['format' => 'png']],
            'quality 0'        => [['quality' => 0]],
            'quality 101'      => [['quality' => 101]],
            'quality text'     => [['quality' => 'high']],
        ];
    }

    public function testRejectsNonObjectEntryAndTooManyPresets(): void
    {
        try {
            Presets::normalize(['nope']);
            $this->fail('expected exception');
        } catch (\InvalidArgumentException) {
            $this->addToAssertionCount(1);
        }

        $many = [];
        for ($i = 0; $i <= Presets::MAX_PRESETS; $i++) {
            $many[] = $this->p(['name' => 'p' . $i]);
        }
        $this->expectException(\InvalidArgumentException::class);
        Presets::normalize($many);
    }

    public function testResolveAddsBuiltInThumbWhenNotDefined(): void
    {
        $map = Presets::resolve([$this->p()]);

        $this->assertSame(['thumb', 'medium'], array_keys($map));
        $this->assertSame(Presets::defaultThumb(), $map['thumb']);
    }

    public function testStoredThumbOverridesTheDefaultAndIsNotDuplicated(): void
    {
        $custom = $this->p(['name' => 'thumb', 'width' => 120, 'height' => 120, 'format' => 'jpg']);
        $map    = Presets::resolve([$custom]);

        $this->assertSame(['thumb'], array_keys($map));
        $this->assertSame(120, $map['thumb']['width']);
    }

    public function testFindReturnsNullForUndefinedName(): void
    {
        $this->assertNull(Presets::find([], 'nope'));
        $this->assertSame('thumb', Presets::find([], 'thumb')['name']);
    }

    public function testExtensionFollowsFormat(): void
    {
        $this->assertSame('webp', Presets::extension(['format' => 'webp']));
        $this->assertSame('jpg', Presets::extension(['format' => 'jpg']));
    }

    // ── who may be sent a preset, and whether it is served ────────────────────

    public function testANewPresetFollowsTheOriginalAndIsEnabled(): void
    {
        $p = Presets::normalize([$this->p()])[0];
        $this->assertSame('inherit', $p['access'], 'the safe default: nothing leaves the system anonymously unless asked');
        $this->assertTrue($p['enabled']);
        $this->assertFalse(Presets::isPublic($p));
        $this->assertTrue(Presets::isEnabled($p));
    }

    public function testAPresetCanBePublicAndCanBeDisabled(): void
    {
        $p = Presets::normalize([$this->p(['access' => 'public', 'enabled' => false])])[0];
        $this->assertTrue(Presets::isPublic($p));
        $this->assertFalse(Presets::isEnabled($p));
        $this->assertTrue(Presets::normalize([$this->p(['enabled' => '0'])])[0]['enabled'] === false, 'as sent by a form');
        $this->assertTrue(Presets::normalize([$this->p(['enabled' => 1])])[0]['enabled'] === true);
    }

    #[DataProvider('invalidAccess')]
    public function testRejectsAnUnknownAccessOrEnabled(array $over): void
    {
        $this->expectException(\InvalidArgumentException::class);
        Presets::normalize([$this->p($over)]);
    }

    public static function invalidAccess(): array
    {
        return [
            'anonymous' => [['access' => 'anonymous']],
            'everyone'  => [['access' => 'everyone']],
            'upper'     => [['access' => 'PUBLIC']],
            'empty'     => [['access' => '']],
            'enabled text' => [['enabled' => 'maybe']],
            'enabled array' => [['enabled' => []]],
        ];
    }

    public function testANullOrMissingAccessMeansTheSafeDefault(): void
    {
        $this->assertSame('inherit', Presets::normalize([$this->p(['access' => null])])[0]['access']);
        $this->assertSame('inherit', Presets::normalize([$this->p()])[0]['access']);
    }

    public function testTheSystemThumbCannotBeDisabled(): void
    {
        $thumb = Presets::normalize([$this->p(['name' => 'thumb', 'width' => 100, 'height' => 100, 'enabled' => false])])[0];
        $this->assertTrue($thumb['enabled'], 'the table and card views depend on it');
    }

    public function testTheBuiltInThumbIsListedEnabledAndFollowsTheOriginal(): void
    {
        $thumb = Presets::resolve([])['thumb'];
        $this->assertSame('inherit', $thumb['access']);
        $this->assertTrue(Presets::isEnabled($thumb));
    }

    public function testAnEntryWithoutTheKeysIsEnabledAndFollowsTheOriginal(): void
    {
        $this->assertTrue(Presets::isEnabled(['name' => 'x']));
        $this->assertFalse(Presets::isPublic(['name' => 'x']));
        $this->assertFalse(Presets::isPublic(['name' => 'x', 'access' => 'nonsense']), 'only the exact word opens a preset');
    }

    public function testRenderKeyIgnoresWhatDoesNotChangeTheImages(): void
    {
        $a = Presets::normalize([$this->p()])[0];
        $b = Presets::normalize([$this->p(['access' => 'public', 'enabled' => false])])[0];
        $this->assertSame(Presets::renderKey($a), Presets::renderKey($b), 'switching a preset on/off keeps its cache');
        $c = Presets::normalize([$this->p(['width' => 801])])[0];
        $this->assertNotSame(Presets::renderKey($a), Presets::renderKey($c));
    }
}
