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
        return array_merge(['name' => 'medium', 'max_px' => 800, 'format' => 'webp', 'quality' => 80], $over);
    }

    public function testNormalizeAppliesDefaultsAndDropsUnknownKeys(): void
    {
        $out = Presets::normalize([['name' => 'small', 'max_px' => '300', 'evil' => 'x']]);

        $this->assertSame([['name' => 'small', 'max_px' => 300, 'format' => 'webp', 'quality' => 80]], $out);
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
            'max_px too small' => [['max_px' => 15]],
            'max_px too big'   => [['max_px' => 10001]],
            'max_px text'      => [['max_px' => 'big']],
            'max_px missing'   => [['max_px' => null]],
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
        $custom = $this->p(['name' => 'thumb', 'max_px' => 120, 'format' => 'jpg']);
        $map    = Presets::resolve([$custom]);

        $this->assertSame(['thumb'], array_keys($map));
        $this->assertSame(120, $map['thumb']['max_px']);
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
}
