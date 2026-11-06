<?php

/**
 * @copyright 2007-2026 Julian Bogdani
 * @license AGPL-3.0; see LICENSE
 */

namespace Image;

/**
 * Derived-image presets: validation, normalisation and lookup.
 *
 * A preset is {name, max_px, format, quality}. The list is stored as JSON in
 * bdus_cfg_app.image_presets (see Config\AppSettings). One preset, `thumb`, is
 * always available: when the stored list does not define it, the built-in
 * default is used, and it can be redefined but never removed.
 *
 * The preset name is only ever a lookup key into this list — nothing is
 * generated for a name that is not defined — and it ends up in file paths and
 * URLs, hence the strict ASCII pattern.
 */
final class Presets
{
    public const SYSTEM      = 'thumb';
    public const FORMATS     = ['webp', 'jpg'];
    public const NAME_REGEX  = '/^[a-z0-9][a-z0-9_-]{0,31}$/';
    public const MAX_PRESETS = 20;
    public const MIN_PX      = 16;
    public const MAX_PX      = 10000;

    /** The built-in `thumb` preset. */
    public static function defaultThumb(): array
    {
        return ['name' => self::SYSTEM, 'max_px' => 200, 'format' => 'webp', 'quality' => 80];
    }

    /** True when $name is an acceptable preset name (does not imply it is defined). */
    public static function isValidName(mixed $name): bool
    {
        return is_string($name) && preg_match(self::NAME_REGEX, $name) === 1;
    }

    /**
     * Validates a list of presets and returns it normalised (known keys only,
     * defaults applied). Strict: nothing is silently corrected.
     *
     * @param  array $list Raw list, e.g. decoded from the request or from JSON.
     * @return array<int, array{name: string, max_px: int, format: string, quality: int}>
     * @throws \InvalidArgumentException With a message naming the problem.
     */
    public static function normalize(array $list): array
    {
        if (count($list) > self::MAX_PRESETS) {
            throw new \InvalidArgumentException('too many presets (max ' . self::MAX_PRESETS . ')');
        }

        $out  = [];
        $seen = [];
        foreach (array_values($list) as $i => $p) {
            if (!is_array($p)) {
                throw new \InvalidArgumentException("preset #{$i} is not an object");
            }
            $name = $p['name'] ?? null;
            if (!self::isValidName($name)) {
                throw new \InvalidArgumentException("preset #{$i}: invalid name (use a-z, 0-9, _ and -, max 32 chars)");
            }
            if (isset($seen[$name])) {
                throw new \InvalidArgumentException("duplicate preset name '{$name}'");
            }
            $seen[$name] = true;

            $maxPx = $p['max_px'] ?? null;
            if (!is_int($maxPx) && !(is_string($maxPx) && ctype_digit($maxPx))) {
                throw new \InvalidArgumentException("preset '{$name}': max_px must be an integer");
            }
            $maxPx = (int) $maxPx;
            if ($maxPx < self::MIN_PX || $maxPx > self::MAX_PX) {
                throw new \InvalidArgumentException(
                    "preset '{$name}': max_px must be between " . self::MIN_PX . ' and ' . self::MAX_PX
                );
            }

            $format = $p['format'] ?? 'webp';
            if (!in_array($format, self::FORMATS, true)) {
                throw new \InvalidArgumentException("preset '{$name}': format must be one of " . implode(', ', self::FORMATS));
            }

            $quality = $p['quality'] ?? 80;
            if (!is_int($quality) && !(is_string($quality) && ctype_digit($quality))) {
                throw new \InvalidArgumentException("preset '{$name}': quality must be an integer");
            }
            $quality = (int) $quality;
            if ($quality < 1 || $quality > 100) {
                throw new \InvalidArgumentException("preset '{$name}': quality must be between 1 and 100");
            }

            $out[] = ['name' => $name, 'max_px' => $maxPx, 'format' => $format, 'quality' => $quality];
        }

        return $out;
    }

    /**
     * The effective presets keyed by name: the stored list plus the built-in
     * `thumb` when the list does not define it.
     *
     * @param  array $stored Already-normalised stored list.
     * @return array<string, array{name: string, max_px: int, format: string, quality: int}>
     */
    public static function resolve(array $stored): array
    {
        $map = [];
        foreach ($stored as $p) {
            if (is_array($p) && isset($p['name'])) {
                $map[$p['name']] = $p;
            }
        }
        if (!isset($map[self::SYSTEM])) {
            $map = [self::SYSTEM => self::defaultThumb()] + $map;
        }
        return $map;
    }

    /** One effective preset by name, or null when it is not defined. */
    public static function find(array $stored, string $name): ?array
    {
        return self::resolve($stored)[$name] ?? null;
    }

    /** File extension produced by a preset. */
    public static function extension(array $preset): string
    {
        return ($preset['format'] ?? 'webp') === 'jpg' ? 'jpg' : 'webp';
    }
}
