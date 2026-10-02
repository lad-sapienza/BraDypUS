<?php

/**
 * @copyright 2007-2026 Julian Bogdani
 * @license AGPL-3.0; see LICENSE
 */

declare(strict_types=1);

namespace SQL;

/**
 * Reads the search parameters of an export request (`filter`, `qt`, `q`) into
 * the pieces QueryFromRequest wants.
 *
 * The export mirrors the record list's URL state, so it accepts the same few
 * shapes — and nothing else. A parameter it cannot read is an error, never a
 * quiet fallback: exporting "everything" because a filter could not be parsed
 * hands the user far more data than the list on their screen (issue #75).
 *
 *  - `filter`            a JsonFilter object (bracket notation, or a JSON string)
 *  - `qt=fast`           `q` is the text searched for
 *  - `qt=expert`         `q` is a raw SQL condition
 *  - `qt=filter`         `q` is a JsonFilter object as JSON
 *  - `qt=advanced`       `q` is the query builder's state, `{"tree"|"rows": …, "filter": <JsonFilter>}`
 *
 * No `filter` and no `qt` means the whole table.
 */
final class ExportSearch
{
    public const MODES = ['fast', 'expert', 'filter', 'advanced'];

    /**
     * @param mixed $filter the `filter` parameter: array (bracket notation), JSON string or null
     * @param mixed $qt     the `qt` parameter (a string, or null)
     * @param mixed $q      the `q` parameter (a string, or null)
     *
     * @return array<string, mixed> keys to merge into the QueryFromRequest request
     *                              (`type` plus `filter` / `string` / `querytext` / `join`)
     *
     * @throws \InvalidArgumentException with a message naming the offending parameter
     */
    public static function fromParams(mixed $filter, mixed $qt, mixed $q): array
    {
        // An empty value is the same as an absent one (`?filter=&qt=`) — but
        // `qt=fast&q=` is still an error below: the mode asks for a value.
        $filter = $filter === '' ? null : $filter;
        $qt     = $qt === '' ? null : $qt;
        $q      = $q === '' ? null : $q;

        // `qt[]=x` and the like arrive as arrays: say so, instead of a type error.
        foreach (['qt' => $qt, 'q' => $q] as $name => $value) {
            if ($value !== null && !is_string($value)) {
                throw new \InvalidArgumentException("{$name}: expected a string");
            }
        }

        // A ready-made filter wins over qt/q, as it always has.
        if ($filter !== null) {
            return ['type' => 'filter', 'filter' => self::filterObject($filter, 'filter')];
        }

        if ($qt === null) {
            if ($q !== null) {
                throw new \InvalidArgumentException("q was given without qt");
            }
            return ['type' => 'all'];
        }

        if (!in_array($qt, self::MODES, true)) {
            throw new \InvalidArgumentException(
                "qt: unknown value '{$qt}' (expected " . implode(', ', self::MODES) . ")"
            );
        }
        if ($q === null) {
            throw new \InvalidArgumentException("q is required (and not empty) with qt={$qt}");
        }

        switch ($qt) {
            case 'fast':
                return ['type' => 'fast', 'string' => $q];

            case 'expert':
                return ['type' => 'sqlExpert', 'querytext' => $q, 'join' => ''];

            case 'filter':
                return ['type' => 'filter', 'filter' => self::filterObject($q, 'q (qt=filter)')];

            default: // advanced
                $state = json_decode($q, true);
                if (!is_array($state) || !array_key_exists('filter', $state)) {
                    throw new \InvalidArgumentException(
                        'q (qt=advanced): expected a JSON object with a "filter" key'
                    );
                }
                if (!is_array($state['filter'])) {
                    throw new \InvalidArgumentException('q (qt=advanced), "filter": expected a filter object');
                }
                return ['type' => 'filter', 'filter' => $state['filter']];
        }
    }

    /**
     * @param mixed $value an already-parsed array, or a JSON string
     * @return array<string|int, mixed>
     */
    private static function filterObject(mixed $value, string $where): array
    {
        if (is_string($value)) {
            $decoded = json_decode($value, true);
            if (json_last_error() !== JSON_ERROR_NONE) {
                throw new \InvalidArgumentException("{$where}: not valid JSON (" . json_last_error_msg() . ')');
            }
            $value = $decoded;
        }
        if (!is_array($value)) {
            throw new \InvalidArgumentException("{$where}: expected a filter object");
        }
        return $value;
    }
}
