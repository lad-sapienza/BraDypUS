<?php

namespace Tests\Integration;

use PHPUnit\Framework\TestCase;
use Image\Resizer;

/**
 * Tests for Image\Resizer::maybeResize().
 *
 * All images are created on-the-fly with GD (the same driver used by
 * Intervention\Image in production) so no binary fixtures are needed.
 */
class ImageResizerTest extends TestCase
{
    private string $tmpDir;

    protected function setUp(): void
    {
        $this->tmpDir = sys_get_temp_dir() . '/bdus_resizer_' . uniqid();
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

    /** Creates a JPEG of the given dimensions and returns its path. */
    private function makeJpeg(int $w, int $h, string $name = 'test.jpg'): string
    {
        $path = $this->tmpDir . '/' . $name;
        $img  = imagecreatetruecolor($w, $h);
        imagefill($img, 0, 0, imagecolorallocate($img, 100, 149, 237)); // cornflower blue
        imagejpeg($img, $path, 90);
        imagedestroy($img);
        return $path;
    }

    /** Creates a PNG of the given dimensions and returns its path. */
    private function makePng(int $w, int $h, string $name = 'test.png'): string
    {
        $path = $this->tmpDir . '/' . $name;
        $img  = imagecreatetruecolor($w, $h);
        imagefill($img, 0, 0, imagecolorallocate($img, 200, 100, 50));
        imagepng($img, $path);
        imagedestroy($img);
        return $path;
    }

    /** Returns [width, height] of an image file. */
    private function dims(string $path): array
    {
        [$w, $h] = getimagesize($path);
        return [$w, $h];
    }

    // ── Downscale: landscape ─────────────────────────────────────────────

    public function testDownscalesLandscapeImageToMaxPx(): void
    {
        $path = $this->makeJpeg(3000, 2000);
        $result = Resizer::maybeResize($path, 1500);

        $this->assertTrue($result, 'Should return true when image was resized');
        [$w, $h] = $this->dims($path);
        $this->assertSame(1500, $w, 'Width should be capped at maxPx');
        $this->assertSame(1000, $h, 'Height should be scaled proportionally');
    }

    // ── Downscale: portrait ──────────────────────────────────────────────

    public function testDownscalesPortraitImageToMaxPx(): void
    {
        $path = $this->makeJpeg(1000, 4000);
        Resizer::maybeResize($path, 1500);

        [$w, $h] = $this->dims($path);
        $this->assertSame(375, $w);
        $this->assertSame(1500, $h, 'Height (longest side) should be capped at maxPx');
    }

    // ── No upscale ────────────────────────────────────────────────────────

    public function testDoesNotUpscaleSmallImage(): void
    {
        $path = $this->makeJpeg(400, 300);
        $result = Resizer::maybeResize($path, 1500);

        $this->assertFalse($result, 'Should return false — image already within bounds');
        [$w, $h] = $this->dims($path);
        $this->assertSame(400, $w, 'Width must not change');
        $this->assertSame(300, $h, 'Height must not change');
    }

    // ── Exact boundary ────────────────────────────────────────────────────

    public function testDoesNotResizeWhenExactlyAtMaxPx(): void
    {
        $path = $this->makeJpeg(1500, 1500);
        $result = Resizer::maybeResize($path, 1500);

        $this->assertFalse($result);
        [$w, $h] = $this->dims($path);
        $this->assertSame(1500, $w);
        $this->assertSame(1500, $h);
    }

    // ── PNG ───────────────────────────────────────────────────────────────

    public function testWorksWithPng(): void
    {
        $path = $this->makePng(2000, 1000);
        $result = Resizer::maybeResize($path, 800);

        $this->assertTrue($result);
        [$w, $h] = $this->dims($path);
        $this->assertSame(800, $w);
        $this->assertSame(400, $h);
    }

    // ── maxPx = 0 ─────────────────────────────────────────────────────────

    public function testNoOpWhenMaxPxIsZero(): void
    {
        $path = $this->makeJpeg(3000, 2000);
        $result = Resizer::maybeResize($path, 0);

        $this->assertFalse($result);
        [$w, $h] = $this->dims($path);
        $this->assertSame(3000, $w, 'Image must be untouched when maxPx=0');
    }

    // ── Non-image file ────────────────────────────────────────────────────

    public function testSkipsNonImageFile(): void
    {
        $path = $this->tmpDir . '/document.pdf';
        file_put_contents($path, '%PDF-1.4 fake content');

        $result = Resizer::maybeResize($path, 1500);
        $this->assertFalse($result, 'PDF must be skipped');
        $this->assertFileExists($path, 'File must not be deleted');
    }

    // ── SVG skipped ───────────────────────────────────────────────────────

    public function testSkipsSvgFile(): void
    {
        $path = $this->tmpDir . '/icon.svg';
        file_put_contents($path, '<svg xmlns="http://www.w3.org/2000/svg"><circle r="10"/></svg>');

        $result = Resizer::maybeResize($path, 1500);
        $this->assertFalse($result, 'SVG must be skipped — it is vector');
    }

    // ── Missing file ──────────────────────────────────────────────────────

    public function testReturnsFalseForMissingFile(): void
    {
        $result = Resizer::maybeResize('/nonexistent/path/image.jpg', 1500);
        $this->assertFalse($result);
    }
}
