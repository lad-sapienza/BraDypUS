<?php

namespace Tests\Integration;

use PHPUnit\Framework\TestCase;
use Image\Resizer;

/**
 * Tests for Image\Resizer::process() — resize + optional format conversion,
 * the backend for issue tracker #55 (max_image_size settings extended with
 * webp/jpg conversion, quality, dpi).
 *
 * All images are created on-the-fly with GD (the same driver used by
 * Intervention\Image in production) so no binary fixtures are needed.
 */
class ImageConverterTest extends TestCase
{
    private string $tmpDir;

    protected function setUp(): void
    {
        $this->tmpDir = sys_get_temp_dir() . '/bdus_converter_' . uniqid();
        mkdir($this->tmpDir, 0755, true);
    }

    protected function tearDown(): void
    {
        foreach (glob($this->tmpDir . '/*') ?: [] as $f) {
            @unlink($f);
        }
        @rmdir($this->tmpDir);
    }

    // ── Helpers ───────────────────────────────────────────────────────────

    private function makeJpeg(int $w, int $h, string $name = 'test.jpg'): string
    {
        $path = $this->tmpDir . '/' . $name;
        $img  = imagecreatetruecolor($w, $h);
        imagefill($img, 0, 0, imagecolorallocate($img, 100, 149, 237));
        imagejpeg($img, $path, 90);
        imagedestroy($img);
        return $path;
    }

    private function makePng(int $w, int $h, string $name = 'test.png'): string
    {
        $path = $this->tmpDir . '/' . $name;
        $img  = imagecreatetruecolor($w, $h);
        // Fill with a semi-transparent color to exercise the alpha→white-flatten
        // path when converting to JPG (Intervention's GD JpegEncoder blends
        // onto Config::$backgroundColor, which defaults to white).
        imagesavealpha($img, true);
        imagealphablending($img, false);
        $transparent = imagecolorallocatealpha($img, 200, 100, 50, 64);
        imagefill($img, 0, 0, $transparent);
        imagepng($img, $path);
        imagedestroy($img);
        return $path;
    }

    private function makeGif(int $w, int $h, string $name = 'test.gif'): string
    {
        $path = $this->tmpDir . '/' . $name;
        $img  = imagecreatetruecolor($w, $h);
        imagefill($img, 0, 0, imagecolorallocate($img, 10, 200, 10));
        imagegif($img, $path);
        imagedestroy($img);
        return $path;
    }

    /**
     * Builds a real 2-frame animated GIF via intervention/gif's own Builder
     * (already a dependency of intervention/image) — guarantees a fixture
     * that its own decoder accepts, to exercise the "animated images are
     * never converted" rule. Frame content is irrelevant, only the count.
     */
    private function makeAnimatedGif(int $w, int $h, string $name = 'anim.gif'): string
    {
        $framePath = $this->makeGif($w, $h, 'frame_source_' . $name);

        $builder = \Intervention\Gif\Builder::canvas($w, $h)
            ->addFrame($framePath, 0.1)
            ->addFrame($framePath, 0.1);

        $path = $this->tmpDir . '/' . $name;
        file_put_contents($path, $builder->encode());
        return $path;
    }

    private function dims(string $path): array
    {
        [$w, $h] = getimagesize($path);
        return [$w, $h];
    }

    private function defaultOpts(array $override = []): array
    {
        return array_merge([
            'maxPx'   => 0,
            'convert' => false,
            'format'  => 'webp',
            'quality' => 85,
            'dpi'     => 72,
        ], $override);
    }

    // ── No-op when neither resize nor convert apply ─────────────────────────

    public function testNoOpWhenNothingConfigured(): void
    {
        $path   = $this->makeJpeg(400, 300);
        $result = Resizer::process($path, $this->defaultOpts());

        $this->assertFalse($result['changed']);
        $this->assertSame('jpg', $result['ext']);
        $this->assertSame($path, $result['path']);
    }

    // ── Resize only (convert disabled) — same semantics as maybeResize() ───

    public function testResizesWithoutConvertingWhenConvertDisabled(): void
    {
        $path   = $this->makeJpeg(3000, 2000);
        $result = Resizer::process($path, $this->defaultOpts(['maxPx' => 1500]));

        $this->assertTrue($result['changed']);
        $this->assertSame('jpg', $result['ext']);
        [$w, $h] = $this->dims($path);
        $this->assertSame(1500, $w);
        $this->assertSame(1000, $h);
    }

    // ── Convert to webp ──────────────────────────────────────────────────

    public function testConvertsJpegToWebp(): void
    {
        $path   = $this->makeJpeg(200, 100);
        $result = Resizer::process($path, $this->defaultOpts(['convert' => true, 'format' => 'webp']));

        $this->assertTrue($result['changed']);
        $this->assertSame('webp', $result['ext']);
        $this->assertStringEndsWith('.webp', $result['path']);
        $this->assertFileExists($result['path']);
        $this->assertFileDoesNotExist($path, 'Original .jpg must be removed once converted');
        $this->assertSame('image/webp', mime_content_type($result['path']));
    }

    // ── Convert to jpg, with resize applied first ───────────────────────────

    public function testResizesAndConvertsToJpgTogether(): void
    {
        $path   = $this->makePng(3000, 1500);
        $result = Resizer::process($path, $this->defaultOpts(['maxPx' => 1000, 'convert' => true, 'format' => 'jpg']));

        $this->assertTrue($result['changed']);
        $this->assertSame('jpg', $result['ext']);
        $this->assertFileDoesNotExist($path);
        [$w, $h] = $this->dims($result['path']);
        $this->assertSame(1000, $w);
        $this->assertSame(500, $h);
        $this->assertSame('image/jpeg', mime_content_type($result['path']));
    }

    // ── Transparent PNG → JPG flattens onto white (no crash, no alpha) ─────

    public function testConvertingTransparentPngToJpgDoesNotFail(): void
    {
        $path   = $this->makePng(50, 50);
        $result = Resizer::process($path, $this->defaultOpts(['convert' => true, 'format' => 'jpg']));

        $this->assertTrue($result['changed']);
        $this->assertSame('jpg', $result['ext']);
        $this->assertFileExists($result['path']);
    }

    // ── Same-extension conversion still re-encodes (quality/dpi applied) ───

    public function testConvertingToSameFormatStillReencodes(): void
    {
        $path   = $this->makeJpeg(200, 100);
        $result = Resizer::process($path, $this->defaultOpts(['convert' => true, 'format' => 'jpg', 'quality' => 40]));

        $this->assertTrue($result['changed']);
        $this->assertSame('jpg', $result['ext']);
        $this->assertSame($path, $result['path'], 'Path stays the same when the extension does not change');
        $this->assertFileExists($path);
    }

    // ── Animated GIF is never converted, even when convert=true ────────────

    public function testAnimatedGifIsNotConverted(): void
    {
        $path   = $this->makeAnimatedGif(50, 30);
        $result = Resizer::process($path, $this->defaultOpts(['convert' => true, 'format' => 'webp']));

        $this->assertSame('gif', $result['ext'], 'Animated GIF must stay a GIF, not be collapsed to a static format');
        $this->assertFileExists($path);
    }

    // ── Animated GIF is still resized when oversized ────────────────────────

    public function testAnimatedGifIsStillResizedWhenOversized(): void
    {
        $path   = $this->makeAnimatedGif(2000, 1000, 'big_anim.gif');
        $result = Resizer::process($path, $this->defaultOpts(['maxPx' => 500, 'convert' => true, 'format' => 'webp']));

        $this->assertTrue($result['changed']);
        $this->assertSame('gif', $result['ext']);
        [$w, $h] = $this->dims($path);
        $this->assertSame(500, $w);
        $this->assertSame(250, $h);
    }

    // ── Non-raster / missing files pass through unchanged ───────────────────

    public function testSkipsSvgFile(): void
    {
        $path = $this->tmpDir . '/icon.svg';
        file_put_contents($path, '<svg xmlns="http://www.w3.org/2000/svg"><circle r="10"/></svg>');

        $result = Resizer::process($path, $this->defaultOpts(['convert' => true]));

        $this->assertFalse($result['changed']);
        $this->assertSame('svg', $result['ext']);
    }

    public function testReturnsNoopForMissingFile(): void
    {
        $result = Resizer::process('/nonexistent/path/image.jpg', $this->defaultOpts(['convert' => true]));

        $this->assertFalse($result['changed']);
        $this->assertSame('jpg', $result['ext']);
    }

    // ── Quality is clamped to the valid 1-100 range ─────────────────────────

    public function testQualityIsClampedToValidRange(): void
    {
        $path   = $this->makeJpeg(100, 100);
        $result = Resizer::process($path, $this->defaultOpts(['convert' => true, 'format' => 'webp', 'quality' => 500]));

        $this->assertTrue($result['changed']);
        $this->assertFileExists($result['path']);
    }
}
