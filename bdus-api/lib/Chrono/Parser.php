<?php

/**
 * @copyright 2007-2025 Julian Bogdani
 * @license AGPL-3.0; see LICENSE
 *
 * Parses and formats BraDypUS fuzzy-date strings.
 *
 * Grammar:
 *   input   = '?'                         → undated  (from=null, to=null)
 *           | '?' '/' token               → ante quem (from=null, to=token.to)
 *           | token '/' '?'               → post quem (from=token.from, to=null)
 *           | token '/' token             → explicit range
 *           | token                       → point or century range
 *
 *   token   = century | year
 *   century = 'c' N qualifier? era        N = 1-99, era = BCE|CE
 *   year    = '-' N                       → year BCE (negative)
 *           | N 'BCE'                     → year BCE (positive + explicit era)
 *           | N 'CE'?                     → year CE
 *
 *   qualifier = 'e' | 'm' | 'l' | 'h1' | 'h2' | 'q1' | 'q2' | 'q3' | 'q4'
 *
 * Century ranges (pct offset within the 100-year span, 0 = century start):
 *   e → [0, 24]   m → [25, 74]   l → [75, 99]
 *   h1 → [0, 49]  h2 → [50, 99]
 *   q1 → [0, 24]  q2 → [25, 49]  q3 → [50, 74]  q4 → [75, 99]
 *
 * BCE centuries:  century_base = -(N * 100),   year = base + pct
 * CE  centuries:  century_base = (N-1)*100 + 1, year = base + pct
 */

declare(strict_types=1);

namespace Chrono;

class Parser
{
    private const QUALIFIERS = [
        'e'  => [0,  24],
        'm'  => [25, 74],
        'l'  => [75, 99],
        'h1' => [0,  49],
        'h2' => [50, 99],
        'q1' => [0,  24],
        'q2' => [25, 49],
        'q3' => [50, 74],
        'q4' => [75, 99],
        ''   => [0,  99],
    ];

    private const QUALIFIER_LABELS = [
        'e'  => 'Early',
        'm'  => 'Mid',
        'l'  => 'Late',
        'h1' => 'First half of',
        'h2' => 'Second half of',
        'q1' => 'First quarter of',
        'q2' => 'Second quarter of',
        'q3' => 'Third quarter of',
        'q4' => 'Fourth quarter of',
        ''   => '',
    ];

    private const CENTURY_RE = '/^c(\d{1,2})(e|m|l|h1|h2|q[1-4])?\s+(BCE|CE)$/i';
    private const YEAR_NEG   = '/^-(\d{1,5})$/';
    private const YEAR_POS   = '/^(\d{1,5})\s*(BCE|CE)?$/i';

    /**
     * Parse a full input string.
     *
     * @return array{
     *   from: int|null,
     *   to:   int|null,
     *   label: string,
     *   valid: bool,
     *   error: string|null
     * }
     */
    public static function parse(string $input): array
    {
        $input = trim($input);

        if ($input === '' || $input === '?') {
            return ['from' => null, 'to' => null, 'label' => 'Undated', 'valid' => true, 'error' => null];
        }

        // Split on '/'
        $parts = array_map('trim', explode('/', $input, 2));

        try {
            if (count($parts) === 1) {
                // Single token: point date or century range
                $t = self::parseToken($parts[0]);
                return [
                    'from'  => $t['from'],
                    'to'    => $t['to'],
                    'label' => $t['label'],
                    'valid' => true,
                    'error' => null,
                ];
            }

            [$left, $right] = $parts;

            if ($left === '?' && $right === '?') {
                return ['from' => null, 'to' => null, 'label' => 'Undated', 'valid' => true, 'error' => null];
            }

            if ($left === '?') {
                // Ante quem
                $t = self::parseToken($right);
                return [
                    'from'  => null,
                    'to'    => $t['to'],
                    'label' => 'Ante quem: ' . $t['label'],
                    'valid' => true,
                    'error' => null,
                ];
            }

            if ($right === '?') {
                // Post quem
                $t = self::parseToken($left);
                return [
                    'from'  => $t['from'],
                    'to'    => null,
                    'label' => 'Post quem: ' . $t['label'],
                    'valid' => true,
                    'error' => null,
                ];
            }

            // Explicit range: token/token
            $tl = self::parseToken($left);
            $tr = self::parseToken($right);

            if ($tl['from'] > $tr['to']) {
                throw new \InvalidArgumentException('Range start is after range end');
            }

            $label = $tl['label'] . ' – ' . $tr['label'];
            return [
                'from'  => $tl['from'],
                'to'    => $tr['to'],
                'label' => $label,
                'valid' => true,
                'error' => null,
            ];

        } catch (\Throwable $e) {
            return ['from' => null, 'to' => null, 'label' => '', 'valid' => false, 'error' => $e->getMessage()];
        }
    }

    /**
     * Format from/to integers back to a human-readable label.
     * Used when displaying stored values without the original input string.
     */
    public static function format(?int $from, ?int $to): string
    {
        if ($from === null && $to === null) {
            return 'Undated';
        }
        if ($from === null) {
            return 'Ante quem: ' . self::yearLabel($to);
        }
        if ($to === null) {
            return 'Post quem: ' . self::yearLabel($from);
        }
        if ($from === $to) {
            return self::yearLabel($from);
        }
        return self::yearLabel($from) . ' – ' . self::yearLabel($to);
    }

    // ── Private helpers ───────────────────────────────────────────────────────

    /**
     * Parse a single token (century or year).
     *
     * @return array{from: int, to: int, label: string}
     * @throws \InvalidArgumentException on parse failure
     */
    private static function parseToken(string $token): array
    {
        $token = trim($token);

        // Century: starts with 'c'
        if (preg_match(self::CENTURY_RE, $token, $m)) {
            return self::centuryToRange((int)$m[1], strtolower($m[2] ?? ''), strtoupper($m[3]));
        }

        // Year: negative integer (BCE implicit)
        if (preg_match(self::YEAR_NEG, $token, $m)) {
            $y = -(int)$m[1];
            return ['from' => $y, 'to' => $y, 'label' => self::yearLabel($y)];
        }

        // Year: positive integer with optional era
        if (preg_match(self::YEAR_POS, $token, $m)) {
            $n   = (int)$m[1];
            $era = strtoupper($m[2] ?? 'CE');
            $y   = ($era === 'BCE') ? -$n : $n;
            return ['from' => $y, 'to' => $y, 'label' => self::yearLabel($y)];
        }

        throw new \InvalidArgumentException("Cannot parse token: '$token'");
    }

    /**
     * @return array{from: int, to: int, label: string}
     */
    private static function centuryToRange(int $century, string $qualifier, string $era): array
    {
        if ($century < 1 || $century > 99) {
            throw new \InvalidArgumentException("Century must be between 1 and 99, got $century");
        }
        if (!array_key_exists($qualifier, self::QUALIFIERS)) {
            throw new \InvalidArgumentException("Unknown qualifier: '$qualifier'");
        }

        [$pFrom, $pTo] = self::QUALIFIERS[$qualifier];

        if ($era === 'BCE') {
            $base = -($century * 100);      // e.g. 4th BCE → -400
            $from = $base + $pFrom;         // e.g. l → -400+75 = -325
            $to   = $base + $pTo;           // e.g. l → -400+99 = -301
        } else {
            $base = ($century - 1) * 100 + 1; // e.g. 4th CE → 301
            $from = $base + $pFrom;
            $to   = $base + $pTo;
        }

        $label = self::centuryLabel($century, $qualifier, $era);
        return compact('from', 'to', 'label');
    }

    private static function centuryLabel(int $century, string $qualifier, string $era): string
    {
        $ordinal   = self::ordinal($century);
        $qualLabel = self::QUALIFIER_LABELS[$qualifier] ?? '';
        $centPart  = $ordinal . ' cent. ' . $era;
        return $qualLabel ? $qualLabel . ' ' . $centPart : $centPart;
    }

    private static function yearLabel(int $year): string
    {
        if ($year < 0) {
            return abs($year) . ' BCE';
        }
        return $year . ' CE';
    }

    private static function ordinal(int $n): string
    {
        $abs = abs($n);
        $mod100 = $abs % 100;
        $mod10  = $abs % 10;

        if ($mod100 >= 11 && $mod100 <= 13) {
            return $n . 'th';
        }
        return match ($mod10) {
            1 => $n . 'st',
            2 => $n . 'nd',
            3 => $n . 'rd',
            default => $n . 'th',
        };
    }
}
