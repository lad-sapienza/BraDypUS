<?php

/**
 * @copyright 2007-2025 Julian Bogdani
 * @license AGPL-3.0; see LICENSE
 */

namespace Image;

use Intervention\Image\ImageManager;
use Intervention\Image\Drivers\Gd\Driver;

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
            $img     = $manager->read($path);

            if ($img->width() <= $maxPx && $img->height() <= $maxPx) {
                return false; // Already within bounds — no resize needed.
            }

            $img->scaleDown($maxPx, $maxPx)->save($path);
            return true;
        } catch (\Throwable $e) {
            return false; // Propagated by caller as a warning log.
        }
    }
}
