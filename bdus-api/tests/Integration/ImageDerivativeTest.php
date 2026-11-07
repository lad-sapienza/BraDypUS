<?php

namespace Tests\Integration;

use PHPUnit\Framework\TestCase;
use Image\Derivative;

/**
 * Image\Derivative: generation from an original into the cache, and purging.
 * Images are created on the fly with GD, no binary fixtures.
 */
class ImageDerivativeTest extends TestCase
{
    private string $proj;

    protected function setUp(): void
    {
        $this->proj = sys_get_temp_dir() . '/bdus_deriv_' . uniqid() . '/';
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

    private function makeJpeg(int $id, int $w, int $h): string
    {
        $path = $this->proj . "files/{$id}.jpg";
        $img  = imagecreatetruecolor($w, $h);
        imagefill($img, 0, 0, imagecolorallocate($img, 100, 149, 237));
        imagejpeg($img, $path, 90);
        imagedestroy($img);
        return $path;
    }

    private function preset(array $over = []): array
    {
        return array_merge(['name' => 'thumb', 'width' => 100, 'height' => 100, 'fit' => 'inside', 'format' => 'webp', 'quality' => 80], $over);
    }

    public function testGeneratesDownscaledWebpKeepingAspectAndOriginal(): void
    {
        $src  = $this->makeJpeg(7, 400, 200);
        $before = md5_file($src);
        $dest = Derivative::cachePath($this->proj, 'thumb', 7, 'webp');

        Derivative::generate($src, $dest, $this->preset());

        $this->assertFileExists($dest);
        [$w, $h, $type] = getimagesize($dest);
        $this->assertSame(IMAGETYPE_WEBP, $type);
        $this->assertSame(100, $w);
        $this->assertSame(50, $h);
        $this->assertSame($before, md5_file($src), 'the original must never change');
        $this->assertSame([], glob(dirname($dest) . '/.*.tmp*') ?: [], 'no temp file left behind');
    }

    public function testGeneratesJpegWhenPresetSaysJpg(): void
    {
        $src  = $this->makeJpeg(8, 300, 300);
        $dest = Derivative::cachePath($this->proj, 'thumb', 8, 'jpg');

        Derivative::generate($src, $dest, $this->preset(['format' => 'jpg']));

        $this->assertSame(IMAGETYPE_JPEG, getimagesize($dest)[2]);
    }

    public function testDoesNotEnlargeASmallerOriginal(): void
    {
        $src  = $this->makeJpeg(9, 40, 30);
        $dest = Derivative::cachePath($this->proj, 'thumb', 9, 'webp');

        Derivative::generate($src, $dest, $this->preset());

        [$w, $h] = getimagesize($dest);
        $this->assertSame([40, 30], [$w, $h]);
    }

    public function testCoverGivesExactSizeCroppingAndEnlargingWhenNeeded(): void
    {
        $big   = $this->makeJpeg(20, 400, 200);
        $small = $this->makeJpeg(21, 40, 30);

        foreach ([$big, $small] as $i => $src) {
            $dest = Derivative::cachePath($this->proj, 'sq', 20 + $i, 'webp');
            Derivative::generate($src, $dest, $this->preset(['fit' => 'cover', 'width' => 120, 'height' => 90]));
            $this->assertSame([120, 90], array_slice(getimagesize($dest), 0, 2));
        }
    }

    public function testInsideWithOneSideOnlyScalesToThatSide(): void
    {
        $src  = $this->makeJpeg(22, 400, 200);
        $dest = Derivative::cachePath($this->proj, 'w', 22, 'webp');
        Derivative::generate($src, $dest, $this->preset(['width' => 50, 'height' => null]));
        $this->assertSame([50, 25], array_slice(getimagesize($dest), 0, 2));

        $dest = Derivative::cachePath($this->proj, 'h', 22, 'webp');
        Derivative::generate($src, $dest, $this->preset(['width' => null, 'height' => 40]));
        $this->assertSame([80, 40], array_slice(getimagesize($dest), 0, 2));
    }

    public function testRejectsMissingNonImageAndUnsupportedSources(): void
    {
        $dest = Derivative::cachePath($this->proj, 'thumb', 1, 'webp');

        $cases = [
            'missing'     => $this->proj . 'files/none.jpg',
            'unsupported' => (function () { $p = $this->proj . 'files/2.txt'; file_put_contents($p, 'hi'); return $p; })(),
            'not image'   => (function () { $p = $this->proj . 'files/3.jpg'; file_put_contents($p, 'not an image'); return $p; })(),
        ];
        foreach ($cases as $label => $src) {
            try {
                Derivative::generate($src, $dest, $this->preset());
                $this->fail("expected RuntimeException for {$label}");
            } catch (\RuntimeException) {
                $this->assertFileDoesNotExist($dest, $label);
            }
        }
    }

    public function testPurgeFileRemovesOnlyThatFileAcrossPresets(): void
    {
        foreach ([[1, 'thumb'], [1, 'medium'], [2, 'thumb']] as [$id, $preset]) {
            $src = $this->makeJpeg($id, 200, 200);
            Derivative::generate($src, Derivative::cachePath($this->proj, $preset, $id, 'webp'), $this->preset());
        }

        $this->assertSame(2, Derivative::purgeFile($this->proj, 1));

        $this->assertFileDoesNotExist(Derivative::cachePath($this->proj, 'thumb', 1, 'webp'));
        $this->assertFileDoesNotExist(Derivative::cachePath($this->proj, 'medium', 1, 'webp'));
        $this->assertFileExists(Derivative::cachePath($this->proj, 'thumb', 2, 'webp'));
        $this->assertSame(0, Derivative::purgeFile($this->proj, 99), 'nothing to purge is fine');
    }

    public function testPurgeFileDoesNotMatchIdsSharingAPrefix(): void
    {
        $src = $this->makeJpeg(1, 100, 100);
        Derivative::generate($src, Derivative::cachePath($this->proj, 'thumb', 12, 'webp'), $this->preset());

        $this->assertSame(0, Derivative::purgeFile($this->proj, 1));
        $this->assertFileExists(Derivative::cachePath($this->proj, 'thumb', 12, 'webp'));
    }

    public function testPurgePresetRemovesFolderAndRefusesBadNames(): void
    {
        $src = $this->makeJpeg(5, 100, 100);
        Derivative::generate($src, Derivative::cachePath($this->proj, 'thumb', 5, 'webp'), $this->preset());
        Derivative::generate($src, Derivative::cachePath($this->proj, 'big', 5, 'webp'), $this->preset());

        $this->assertSame(0, Derivative::purgePreset($this->proj, '../files'), 'path traversal refused');
        $this->assertFileExists($src);

        $this->assertSame(1, Derivative::purgePreset($this->proj, 'thumb'));
        $this->assertDirectoryDoesNotExist($this->proj . 'cache/thumb');
        $this->assertFileExists(Derivative::cachePath($this->proj, 'big', 5, 'webp'));
    }
}
