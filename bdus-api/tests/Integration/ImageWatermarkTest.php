<?php

namespace Tests\Integration;

use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use Image\Derivative;
use Image\Presets;
use Image\Watermark;

/**
 * Text watermark of a preset: validation, text resolution and rendering.
 */
class ImageWatermarkTest extends TestCase
{
    private string $proj;

    protected function setUp(): void
    {
        $this->proj = sys_get_temp_dir() . '/bdus_wm_' . uniqid() . '/';
        mkdir($this->proj . 'files', 0755, true);
    }

    protected function tearDown(): void
    {
        $it = new \RecursiveIteratorIterator(
            new \RecursiveDirectoryIterator(rtrim($this->proj, '/'), \FilesystemIterator::SKIP_DOTS),
            \RecursiveIteratorIterator::CHILD_FIRST
        );
        foreach ($it as $f) {
            $f->isDir() ? @rmdir($f->getPathname()) : @unlink($f->getPathname());
        }
        @rmdir(rtrim($this->proj, '/'));
    }

    private function makeJpeg(int $id, int $w = 400, int $h = 300): string
    {
        $path = $this->proj . "files/{$id}.jpg";
        $img  = imagecreatetruecolor($w, $h);
        imagefill($img, 0, 0, imagecolorallocate($img, 100, 149, 237));
        imagejpeg($img, $path, 95);
        imagedestroy($img);
        return $path;
    }

    private function preset(array $wm = []): array
    {
        return Presets::normalize([[
            'name' => 'web', 'width' => 400, 'height' => 300, 'fit' => 'inside', 'format' => 'jpg', 'quality' => 95,
            'watermark' => $wm,
        ]])[0];
    }

    /** Image with the watermark vs the same without it: number of differing pixels. */
    private function diffPixels(string $a, string $b): int
    {
        $ia = imagecreatefromjpeg($a);
        $ib = imagecreatefromjpeg($b);
        $n  = 0;
        for ($y = 0; $y < imagesy($ia); $y++) {
            for ($x = 0; $x < imagesx($ia); $x++) {
                if (abs((imagecolorat($ia, $x, $y) & 0xFF) - (imagecolorat($ib, $x, $y) & 0xFF)) > 20) {
                    $n++;
                }
            }
        }
        return $n;
    }

    public function testFontAndFreeTypeAreAvailable(): void
    {
        $this->assertFileExists(Watermark::fontPath());
        $this->assertTrue(function_exists('imagettfbbox'));
    }

    public function testNormalizeAppliesDefaultsAndIsAbsentWhenNotSet(): void
    {
        $p = Presets::normalize([['name' => 'a', 'width' => 100]])[0];
        $this->assertArrayNotHasKey('watermark', $p);

        $p = Presets::normalize([['name' => 'a', 'width' => 100, 'watermark' => ['text' => 'CC {{attribution}}']]])[0];
        $this->assertSame('CC {{attribution}}', $p['watermark']['text']);
        $this->assertSame('bottom-right', $p['watermark']['position']);
        $this->assertSame(60, $p['watermark']['opacity']);
    }

    #[DataProvider('invalidWatermarks')]
    public function testRejectsInvalidWatermarks(array $wm): void
    {
        $this->expectException(\InvalidArgumentException::class);
        Presets::normalize([['name' => 'a', 'width' => 100, 'watermark' => $wm]]);
    }

    public static function invalidWatermarks(): array
    {
        return [
            'too long'         => [['text' => str_repeat('x', 201)]],
            'control chars'    => [['text' => "a\nb"]],
            'unknown template' => [['text' => '{{title}}']],
            'bad position'     => [['text' => 'x', 'position' => 'middle-ish']],
            'bad colour'       => [['text' => 'x', 'color' => 'red']],
            'bad background'   => [['text' => 'x', 'background' => '#12']],
            'opacity > 100'    => [['text' => 'x', 'opacity' => 101]],
            'size 0'           => [['text' => 'x', 'size' => 0]],
            'margin huge'      => [['text' => 'x', 'margin' => 99]],
            'text not string'  => [['text' => ['x']]],
        ];
    }

    public function testResolveTextUsesAttributionAndSanitisesIt(): void
    {
        $w = Watermark::normalize(['text' => '© {{attribution}}'], 'a');
        $this->assertSame('© Jane Doe, CC-BY', Watermark::resolveText($w, "Jane  Doe,\n CC-BY"));
        $this->assertSame('© A B', Watermark::resolveText($w, "A\x00\x07 B"));
        $this->assertNull(Watermark::resolveText($w, null));
        $this->assertNull(Watermark::resolveText($w, '  '));

        $long = Watermark::resolveText($w, str_repeat('é', 1000));
        $this->assertLessThanOrEqual(Watermark::RENDER_MAX, mb_strlen($long));
    }

    public function testStaticTextNeedsNoAttribution(): void
    {
        $w = Watermark::normalize(['text' => 'Property of the project'], 'a');
        $this->assertSame('Property of the project', Watermark::resolveText($w, null));
    }

    public function testDerivativeCarriesTheTextAndOriginalIsUntouched(): void
    {
        $src    = $this->makeJpeg(1);
        $before = md5_file($src);
        $plain  = $this->proj . 'plain.jpg';
        $marked = $this->proj . 'marked.jpg';

        // Same preset without a watermark key at all
        $noWm = $this->preset();
        unset($noWm['watermark']);
        Derivative::generate($src, $plain, $noWm, 'Jane Doe');
        Derivative::generate($src, $marked, $this->preset(['text' => '© {{attribution}}']), 'Jane Doe');

        $this->assertGreaterThan(200, $this->diffPixels($plain, $marked));
        $this->assertSame($before, md5_file($src), 'original must not change');
    }

    public function testNoAttributionMeansNoWatermarkWhenTemplateNeedsIt(): void
    {
        $src    = $this->makeJpeg(2);
        $plain  = $this->proj . 'plain.jpg';
        $marked = $this->proj . 'marked.jpg';
        $noWm   = $this->preset();
        unset($noWm['watermark']);

        Derivative::generate($src, $plain, $noWm, null);
        Derivative::generate($src, $marked, $this->preset(['text' => '© {{attribution}}']), null);

        $this->assertSame(0, $this->diffPixels($plain, $marked));
    }

    public function testDifferentAttributionsGiveDifferentImages(): void
    {
        $src = $this->makeJpeg(3);
        $a   = $this->proj . 'a.jpg';
        $b   = $this->proj . 'b.jpg';
        $p   = $this->preset(['text' => '{{attribution}}']);

        Derivative::generate($src, $a, $p, 'Alpha');
        Derivative::generate($src, $b, $p, 'A much longer credit line');

        $this->assertGreaterThan(50, $this->diffPixels($a, $b));
    }

    #[DataProvider('positions')]
    public function testEveryPositionRendersWithinTheImage(string $pos): void
    {
        $src = $this->makeJpeg(4, 300, 200);
        $out = $this->proj . "p_{$pos}.jpg";
        Derivative::generate($src, $out, $this->preset(['text' => 'Credit', 'position' => $pos]), null);
        [$w, $h] = getimagesize($out);
        $this->assertSame([300, 200], [$w, $h]);
    }

    public static function positions(): array
    {
        return array_map(static fn ($p) => [$p], Watermark::POSITIONS);
    }

    public function testLongTextOnTinyImageDoesNotFail(): void
    {
        $src = $this->makeJpeg(5, 120, 80);
        $out = $this->proj . 'tiny.jpg';
        Derivative::generate($src, $out, $this->preset(['text' => str_repeat('Long credit ', 15)]), null);
        $this->assertFileExists($out);
    }

    public function testNoBackgroundBoxIsAccepted(): void
    {
        $w = Watermark::normalize(['text' => 'x', 'background' => ''], 'a');
        $this->assertNull($w['background']);
        $src = $this->makeJpeg(6);
        Derivative::generate($src, $this->proj . 'nb.jpg', $this->preset(['text' => 'Credit', 'background' => '']), null);
        $this->assertFileExists($this->proj . 'nb.jpg');
    }
}
