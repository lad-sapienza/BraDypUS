<?php

/**
 * @copyright 2007-2026 Julian Bogdani
 * @license AGPL-3.0; see LICENSE
 */

namespace Image;

use Intervention\Image\ImageManager;
use Intervention\Image\Drivers\Gd\Driver;
use Intervention\Image\Encoders\JpegEncoder;
use Intervention\Image\Encoders\WebpEncoder;

/**
 * Derived versions of an uploaded image, generated on demand from a preset
 * (see Presets) and cached as projects/{app}/cache/{preset}/{id}.{ext}.
 *
 * The cache is a rebuildable mirror of the originals: nothing here ever
 * touches the original file. Its lifecycle is tied to the parent file —
 * purgeFile() when the file or its metadata changes, purgePreset() when a
 * preset definition changes.
 */
final class Derivative
{
    /** Source extensions a derivative can be generated from (GIF as a still image). */
    public const SOURCE_EXTENSIONS = ['jpg', 'jpeg', 'png', 'gif', 'webp'];

    /** Refuse to decode sources larger than this many pixels (GD holds them in RAM). */
    public const MAX_SOURCE_PIXELS = 100_000_000;

    public static function isSupportedSource(string $ext): bool
    {
        return in_array(strtolower($ext), self::SOURCE_EXTENSIONS, true);
    }

    public static function cacheDir(string $projDir, string $preset): string
    {
        return rtrim($projDir, '/') . '/cache/' . $preset;
    }

    public static function cachePath(string $projDir, string $preset, int $id, string $ext): string
    {
        return self::cacheDir($projDir, $preset) . '/' . $id . '.' . $ext;
    }

    /**
     * Generates $dest from $src for $preset. The original is never modified;
     * $dest is written to a temporary file in the same directory and then
     * renamed, so a concurrent reader never sees a partial file.
     *
     * Downscale only (a smaller original is not enlarged); aspect ratio kept;
     * animated sources are reduced to their first frame.
     *
     * @param  array{max_px: int, format: string, quality: int} $preset A normalised preset.
     * @throws \RuntimeException When the source is unusable or encoding fails.
     */
    public static function generate(string $src, string $dest, array $preset): void
    {
        if (!is_file($src)) {
            throw new \RuntimeException('source file not found');
        }
        if (!self::isSupportedSource(pathinfo($src, PATHINFO_EXTENSION))) {
            throw new \RuntimeException('unsupported source type');
        }

        $size = @getimagesize($src);
        if ($size === false) {
            throw new \RuntimeException('source is not a readable image');
        }
        if ($size[0] * $size[1] > self::MAX_SOURCE_PIXELS) {
            throw new \RuntimeException('source image is too large');
        }

        $dir = dirname($dest);
        if (!is_dir($dir) && !@mkdir($dir, 0775, true) && !is_dir($dir)) {
            throw new \RuntimeException('cannot create cache directory');
        }

        $tmp = $dir . '/.' . basename($dest) . '.' . uniqid('tmp', true);
        try {
            $img = (new ImageManager(new Driver()))->decode($src);
            if ($img->isAnimated()) {
                $img->removeAnimation();
            }
            $max = (int) $preset['max_px'];
            $img->scaleDown($max, $max);

            $quality = max(1, min(100, (int) $preset['quality']));
            $encoder = ($preset['format'] ?? 'webp') === 'jpg'
                ? new JpegEncoder($quality)
                : new WebpEncoder($quality);
            $img->encode($encoder)->save($tmp);

            if (!@rename($tmp, $dest)) {
                throw new \RuntimeException('cannot move generated file into place');
            }
        } catch (\Throwable $e) {
            @unlink($tmp);
            throw $e instanceof \RuntimeException ? $e : new \RuntimeException($e->getMessage(), 0, $e);
        }
    }

    /**
     * Removes every cached version of file $id (all presets).
     *
     * @return int Number of files removed.
     */
    public static function purgeFile(string $projDir, int $id): int
    {
        $n = 0;
        foreach (glob(rtrim($projDir, '/') . '/cache/*/' . $id . '.*') ?: [] as $f) {
            if (is_file($f) && @unlink($f)) {
                $n++;
            }
        }
        return $n;
    }

    /**
     * Removes the whole cache folder of one preset.
     *
     * @return int Number of files removed.
     */
    public static function purgePreset(string $projDir, string $preset): int
    {
        // The name ends up in a path: refuse anything that is not a valid preset name.
        if (!Presets::isValidName($preset)) {
            return 0;
        }
        $dir = self::cacheDir($projDir, $preset);
        if (!is_dir($dir)) {
            return 0;
        }
        $n = 0;
        foreach (glob($dir . '/{,.}*', GLOB_BRACE) ?: [] as $f) {
            if (is_file($f) && @unlink($f)) {
                $n++;
            }
        }
        @rmdir($dir);
        return $n;
    }
}
