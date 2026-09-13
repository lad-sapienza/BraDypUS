<?php

/**
 * @copyright 2007-2025 Julian Bogdani
 * @license AGPL-3.0; see LICENSE
 */

namespace Image;

use Intervention\Image\ImageManager;
use Intervention\Image\Drivers\Gd\Driver;
use Intervention\Image\Encoders\JpegEncoder;
use Intervention\Image\Encoders\WebpEncoder;

/**
 * Thin wrapper around Intervention\Image for the single resize-on-upload use case.
 *
 * Extracted from record_ctrl::uploadFile() so it can be unit-tested without
 * requiring a real HTTP multipart upload (move_uploaded_file always fails in CLI).
 */
class Resizer
{
    /**
     * Raster image extensions that can be processed by Intervention\Image / GD.
     * SVG is intentionally excluded — it is vector and has no pixel dimensions.
     */
    private const RASTER_EXTENSIONS = ['jpg', 'jpeg', 'png', 'gif', 'webp', 'bmp', 'tiff'];

    /**
     * Downscale $path so that neither dimension exceeds $maxPx.
     *
     * Rules:
     *   – Only raster images are processed (SVG and non-image files are skipped).
     *   – Only downscales: images already within $maxPx x $maxPx are untouched.
     *   – Aspect ratio is always preserved (scaleDown fits within a square box).
     *   – The file is overwritten in-place.
     *   – If $maxPx <= 0 the call is a no-op.
     *   – Failures are non-fatal: the method returns false so callers can log/warn.
     *
     * @param  string $path  Absolute path to the image file.
     * @param  int    $maxPx Maximum width and height in pixels.
     * @return bool          True when the image was resized, false when skipped or failed.
     */
    public static function maybeResize(string $path, int $maxPx): bool
    {
        if ($maxPx <= 0 || !file_exists($path)) {
            return false;
        }

        $ext = strtolower(pathinfo($path, PATHINFO_EXTENSION));
        if (!in_array($ext, self::RASTER_EXTENSIONS, true)) {
            return false; // Vector or non-image file — skip.
        }

        try {
            $manager = new ImageManager(new Driver());
            $img     = $manager->decode($path);

            if ($img->width() <= $maxPx && $img->height() <= $maxPx) {
                return false; // Already within bounds — no resize needed.
            }

            $img->scaleDown($maxPx, $maxPx)->save($path);
            return true;
        } catch (\Throwable $e) {
            return false; // Propagated by caller as a warning log.
        }
    }

    /**
     * Resize (as maybeResize) and optionally convert format on an uploaded image.
     *
     * Rules:
     *   – Only raster images are processed (SVG and non-image files pass through
     *     unchanged, same guard as maybeResize()).
     *   – Resize: same rules as maybeResize() — downscale only, aspect preserved.
     *   – Conversion (only when $opts['convert'] is true): re-encodes to
     *     $opts['format'] ('webp'|'jpg') at $opts['quality'] / $opts['dpi'].
     *     Animated images (frame count > 1, e.g. animated GIF) are never
     *     converted — collapsing them to a single-frame format would discard
     *     the animation — but are still resized if oversized, same as today.
     *   – EXIF: the GD driver (the only one available here) does not carry EXIF
     *     metadata through decode→encode at all — this already applies to the
     *     plain resize above and is not something this method changes; GPS/EXIF
     *     data is lost whenever an image is re-encoded, resize or conversion.
     *   – The physical file is overwritten in place. When conversion changes
     *     the extension, the old file is deleted and the new path/ext is
     *     returned so the caller can keep bdus_files.ext in sync.
     *   – Failures are non-fatal: returns the original path/ext, changed=false.
     *
     * @param  string $path Absolute path to the image file, named {id}.{ext}.
     * @param  array{maxPx?: int, convert?: bool, format?: string, quality?: int, dpi?: int} $opts
     * @return array{path: string, ext: string, changed: bool}
     */
    public static function process(string $path, array $opts): array
    {
        $origExt = strtolower(pathinfo($path, PATHINFO_EXTENSION));
        $noop    = ['path' => $path, 'ext' => $origExt, 'changed' => false];

        if (!file_exists($path) || !in_array($origExt, self::RASTER_EXTENSIONS, true)) {
            return $noop;
        }

        $maxPx   = (int) ($opts['maxPx'] ?? 0);
        $convert = (bool) ($opts['convert'] ?? false);
        $format  = ($opts['format'] ?? 'webp') === 'jpg' ? 'jpg' : 'webp';
        $quality = max(1, min(100, (int) ($opts['quality'] ?? 85)));
        $dpi     = (int) ($opts['dpi'] ?? 72);

        try {
            $manager = new ImageManager(new Driver());
            $img     = $manager->decode($path);

            $resized = false;
            if ($maxPx > 0 && ($img->width() > $maxPx || $img->height() > $maxPx)) {
                $img->scaleDown($maxPx, $maxPx);
                $resized = true;
            }

            if (!$convert || $img->isAnimated()) {
                if (!$resized) {
                    return $noop;
                }
                $img->save($path);
                return ['path' => $path, 'ext' => $origExt, 'changed' => true];
            }

            if ($dpi > 0) {
                $img->setResolution($dpi, $dpi);
            }
            $encoded = $img->encode($format === 'jpg' ? new JpegEncoder($quality) : new WebpEncoder($quality));

            $newPath = $format === $origExt
                ? $path
                : pathinfo($path, PATHINFO_DIRNAME) . '/' . pathinfo($path, PATHINFO_FILENAME) . '.' . $format;

            $encoded->save($newPath);
            if ($newPath !== $path) {
                @unlink($path);
            }

            return ['path' => $newPath, 'ext' => $format, 'changed' => true];
        } catch (\Throwable $e) {
            return $noop; // Non-fatal: caller keeps the original file untouched.
        }
    }
}
