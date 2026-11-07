<?php

/**
 * @copyright 2007-2026 Julian Bogdani
 * @license AGPL-3.0; see LICENSE
 */

namespace Image;

use Intervention\Image\Interfaces\ImageInterface;

/**
 * Optional text watermark of a preset (see Presets), burnt into derived versions only.
 *
 * Shape: {text, position, color, background, opacity, margin, size}.
 *  - `text`: template, at most TEXT_MAX characters; the only placeholder is
 *    `{{attribution}}`, replaced by the file's attribution. When the template uses
 *    the placeholder and the file has no attribution, no watermark is drawn.
 *  - `position`: one of POSITIONS (3×3 grid).
 *  - `color`, `background`: `#rrggbb`; `background` may be null (no box).
 *  - `opacity`: 0–100, applied to text and box.
 *  - `margin`, `size`: percent of the shorter side (distance from the edge; font size).
 *
 * The text is data (it comes from user input and from the file's attribution):
 * it is validated when the preset is saved and sanitised again at render time
 * (control characters removed, whitespace collapsed, length capped).
 * One bundled font (DejaVu Sans, see fonts/LICENSE-DejaVu.txt): GD needs a font file.
 */
final class Watermark
{
    public const PLACEHOLDER = '{{attribution}}';
    public const TEXT_MAX    = 200;
    /** Rendered text is cut to this many characters, whatever the attribution holds. */
    public const RENDER_MAX  = 300;
    public const POSITIONS   = [
        'top-left', 'top', 'top-right',
        'left', 'center', 'right',
        'bottom-left', 'bottom', 'bottom-right',
    ];
    public const MIN_SIZE    = 1;
    public const MAX_SIZE    = 20;
    public const MAX_MARGIN  = 25;
    public const MIN_FONT_PX = 6;

    public static function fontPath(): string
    {
        return __DIR__ . '/fonts/DejaVuSans.ttf';
    }

    /** Default style, used for every key the preset leaves out. */
    public static function defaults(): array
    {
        return [
            'text' => '© ' . self::PLACEHOLDER, 'position' => 'bottom-right',
            'color' => '#ffffff', 'background' => '#000000',
            'opacity' => 60, 'margin' => 2, 'size' => 4,
        ];
    }

    /**
     * Validates a raw watermark definition and returns it normalised.
     *
     * @throws \InvalidArgumentException With a message naming the problem.
     */
    public static function normalize(array $w, string $presetName): array
    {
        $w   = array_replace(self::defaults(), array_filter($w, static fn ($v, $k) => $k !== 'background' ? $v !== null && $v !== '' : true, ARRAY_FILTER_USE_BOTH));
        $err = static fn (string $m) => new \InvalidArgumentException("preset '{$presetName}': watermark {$m}");

        $text = $w['text'];
        if (!is_string($text) || trim($text) === '') {
            throw $err('text is required');
        }
        if (mb_strlen($text) > self::TEXT_MAX) {
            throw $err('text is too long (max ' . self::TEXT_MAX . ' characters)');
        }
        if (preg_match('/[\p{C}]/u', $text) === 1 || !mb_check_encoding($text, 'UTF-8')) {
            throw $err('text contains invalid or control characters');
        }
        // The only template syntax is {{attribution}}: any other {{...}} is a typo.
        $rest = str_replace(self::PLACEHOLDER, '', $text);
        if (str_contains($rest, '{{') || str_contains($rest, '}}')) {
            throw $err('text may only use the placeholder ' . self::PLACEHOLDER);
        }

        if (!in_array($w['position'], self::POSITIONS, true)) {
            throw $err('position must be one of ' . implode(', ', self::POSITIONS));
        }

        $isHex = static fn ($c) => is_string($c) && preg_match('/^#[0-9a-fA-F]{6}$/', $c) === 1;
        if (!$isHex($w['color'])) {
            throw $err('color must be like #rrggbb');
        }
        $bg = $w['background'];
        if ($bg === '' || $bg === null) {
            $bg = null;
        } elseif (!$isHex($bg)) {
            throw $err('background must be like #rrggbb or empty');
        }

        $int = static function ($v, int $min, int $max, string $what) use ($err): int {
            if (!is_int($v) && !(is_string($v) && ctype_digit($v))) {
                throw $err("{$what} must be an integer");
            }
            $v = (int) $v;
            if ($v < $min || $v > $max) {
                throw $err("{$what} must be between {$min} and {$max}");
            }
            return $v;
        };

        return [
            'text'       => $text,
            'position'   => $w['position'],
            'color'      => strtolower($w['color']),
            'background' => $bg === null ? null : strtolower($bg),
            'opacity'    => $int($w['opacity'], 0, 100, 'opacity'),
            'margin'     => $int($w['margin'], 0, self::MAX_MARGIN, 'margin'),
            'size'       => $int($w['size'], self::MIN_SIZE, self::MAX_SIZE, 'size'),
        ];
    }

    /**
     * The text to draw, or null when nothing must be drawn (the template needs
     * the attribution and the file has none, or the result is empty).
     */
    public static function resolveText(array $w, ?string $attribution): ?string
    {
        $text = (string) ($w['text'] ?? '');
        $attr = self::clean((string) $attribution);

        if (str_contains($text, self::PLACEHOLDER) && $attr === '') {
            return null;
        }
        $out = self::clean(str_replace(self::PLACEHOLDER, $attr, $text));
        if ($out === '') {
            return null;
        }
        return mb_strlen($out) > self::RENDER_MAX ? mb_substr($out, 0, self::RENDER_MAX - 1) . '…' : $out;
    }

    /** Drops control characters, collapses whitespace, enforces valid UTF-8. */
    private static function clean(string $s): string
    {
        $s = mb_convert_encoding($s, 'UTF-8', 'UTF-8');
        $s = preg_replace('/[\p{C}\p{Z}]+/u', ' ', $s) ?? '';
        return trim($s);
    }

    /**
     * Draws the watermark onto $img (a derived version, never the original).
     * Does nothing when resolveText() has no text; throws on rendering failure.
     */
    public static function apply(ImageInterface $img, array $w, ?string $attribution): void
    {
        $text = self::resolveText($w, $attribution);
        if ($text === null) {
            return;
        }
        $font = self::fontPath();
        if (!is_file($font) || !function_exists('imagettfbbox')) {
            throw new \RuntimeException('watermark font or FreeType support is not available');
        }

        $iw      = $img->width();
        $ih      = $img->height();
        $short   = min($iw, $ih);
        $margin  = (int) round($short * $w['margin'] / 100);
        $size    = max((float) self::MIN_FONT_PX, $short * $w['size'] / 100);

        // Shrink until the box fits the image width (long credits on small thumbnails).
        do {
            $bbox = imagettfbbox($size, 0, $font, $text);
            if ($bbox === false) {
                throw new \RuntimeException('cannot measure watermark text');
            }
            $tw  = $bbox[2] - $bbox[0];
            $th  = $bbox[1] - $bbox[7];
            $pad = (int) round($size * 0.4);
            $bw  = $tw + 2 * $pad;
            $bh  = $th + 2 * $pad;
            if ($bw <= $iw - 2 * $margin || $size <= self::MIN_FONT_PX) {
                break;
            }
            $size = max((float) self::MIN_FONT_PX, $size * 0.9);
        } while (true);

        $v = str_contains($w['position'], 'top') ? 'top' : (str_contains($w['position'], 'bottom') ? 'bottom' : 'middle');
        $h = str_contains($w['position'], 'left') ? 'left' : (str_contains($w['position'], 'right') ? 'right' : 'center');
        $x = $h === 'left' ? $margin : ($h === 'right' ? $iw - $margin - $bw : intdiv($iw - $bw, 2));
        $y = $v === 'top' ? $margin : ($v === 'bottom' ? $ih - $margin - $bh : intdiv($ih - $bh, 2));
        $x = max(0, $x);
        $y = max(0, $y);

        $alpha = round($w['opacity'] / 100, 2);
        if ($w['background'] !== null) {
            $bgColor = self::rgba($w['background'], $alpha);
            $img->drawRectangle(static function ($rect) use ($x, $y, $bw, $bh, $bgColor) {
                $rect->at($x, $y)->size($bw, $bh)->background($bgColor);
            });
        }

        $fg = self::rgba($w['color'], $alpha);
        $img->text($text, $x + $pad - (int) $bbox[0], $y + $pad - (int) $bbox[7], static function ($f) use ($font, $size, $fg) {
            $f->filepath($font)->size($size)->color($fg)->align('left', 'top');
        });
    }

    private static function rgba(string $hex, float $alpha): string
    {
        [$r, $g, $b] = sscanf($hex, '#%02x%02x%02x');
        return "rgba({$r}, {$g}, {$b}, {$alpha})";
    }
}
