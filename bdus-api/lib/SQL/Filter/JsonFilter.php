<?php

/**
 * @copyright 2007-2025 Julian Bogdani
 * @license AGPL-3.0; see LICENSE
 */

namespace SQL\Filter;

use Config\Config;

/**
 * Converts a Directus-style JSON filter array to a SQL WHERE clause + bound values.
 *
 * Filter format (identical to Directus):
 *
 *   // Implicit AND — multiple field conditions at root level:
 *   [ "status" => ["_eq" => "active"], "name" => ["_icontains" => "pompeii"] ]
 *
 *   // Explicit logical operators:
 *   [ "_and" => [ ["status" => ["_eq" => "active"]], ["year" => ["_gt" => 100]] ] ]
 *   [ "_or"  => [ ["type" => ["_eq" => "site"]],    ["type" => ["_eq" => "find"]] ] ]
 *
 * The filter array is what PHP natively parses from URL bracket notation:
 *   ?filter[status][_eq]=active&filter[name][_icontains]=pompeii
 * becomes $_GET['filter'] = ["status" => ["_eq" => "active"], "name" => [...]]
 *
 * Security:
 *   - Field names are validated against the table's config allow-list.
 *   - Operators are restricted to the ALLOWED_OPS constant list.
 *   - All values are bound via PDO prepared-statement placeholders (never interpolated).
 */
class JsonFilter
{
    /** Operators supported as array keys inside a field condition. */
    public const ALLOWED_OPS = [
        '_eq',
        '_neq',
        '_lt',
        '_lte',
        '_gt',
        '_gte',
        '_contains',     // case-sensitive LIKE (engine-dependent; see note in buildCondition)
        '_icontains',    // case-insensitive LIKE / ILIKE
        '_ncontains',    // NOT LIKE
        '_starts_with',
        '_ends_with',
        '_in',
        '_nin',
        '_null',         // value: true → IS NULL,  false → IS NOT NULL
        '_nnull',        // value: true → IS NOT NULL
        '_between',      // value: [low, high]
    ];

    /** Logical node keys (not field names). */
    private const LOGICAL_OPS = ['_and', '_or'];

    public function __construct(
        private readonly Config $cfg,
        private readonly string $tb
    ) {}

    // ── Public API ─────────────────────────────────────────────────────────────

    /**
     * Convert a filter array to [sql_where_clause, bound_values].
     *
     * Returns ['1=1', []] for an empty filter.
     *
     * @param  array $filter  Nested filter array (PHP-parsed from bracket notation or JSON body)
     * @return array{0: string, 1: array}
     * @throws FilterException on invalid field name or operator
     */
    public function toSql(array $filter): array
    {
        if (empty($filter)) {
            return ['1=1', []];
        }

        [$parts, $values] = $this->parseNode($filter);

        if (empty($parts)) {
            return ['1=1', []];
        }

        // Root-level conditions are joined with AND (implicit AND, like Directus).
        return ['(' . implode(' AND ', $parts) . ')', $values];
    }

    // ── Internal parsing ───────────────────────────────────────────────────────

    /**
     * Parse one filter node — either a logical group or a set of field conditions.
     *
     * @return array{0: string[], 1: array}   [sql_parts, values]
     */
    private function parseNode(array $node): array
    {
        $parts  = [];
        $values = [];

        foreach ($node as $key => $value) {
            if ($key === '_and') {
                [$sql, $vals] = $this->parseLogical('AND', $value);
                $parts[]      = $sql;
                $values       = array_merge($values, $vals);

            } elseif ($key === '_or') {
                [$sql, $vals] = $this->parseLogical('OR', $value);
                $parts[]      = $sql;
                $values       = array_merge($values, $vals);

            } else {
                // $key is a field name
                $field = $this->validateField($key);
                foreach ((array) $value as $op => $val) {
                    [$sql, $vals] = $this->buildCondition($field, $op, $val);
                    $parts[]      = $sql;
                    $values       = array_merge($values, $vals);
                }
            }
        }

        return [$parts, $values];
    }

    /**
     * Parse a _and / _or logical array: each element is a sub-filter node.
     *
     * @param  string $connector  'AND' or 'OR'
     * @param  array  $items      Array of sub-filter nodes
     * @return array{0: string, 1: array}
     */
    private function parseLogical(string $connector, array $items): array
    {
        $subParts = [];
        $values   = [];

        foreach ($items as $subFilter) {
            if (!is_array($subFilter)) {
                throw new FilterException("Each element of a logical group must be an array.");
            }
            [$subP, $subV] = $this->parseNode($subFilter);
            if (!empty($subP)) {
                $subParts[] = '(' . implode(' AND ', $subP) . ')';
                $values     = array_merge($values, $subV);
            }
        }

        if (empty($subParts)) {
            return ['1=1', []];
        }

        return ['(' . implode(" {$connector} ", $subParts) . ')', $values];
    }

    /**
     * Build a single SQL condition for one field + one operator + value.
     *
     * @return array{0: string, 1: array}   [sql_fragment, bound_values]
     * @throws FilterException on unknown operator or wrong value type
     */
    private function buildCondition(string $field, string $op, mixed $val): array
    {
        if (!in_array($op, self::ALLOWED_OPS, true)) {
            throw new FilterException("Unknown filter operator: {$op}");
        }

        $col = $this->tb . '.' . $field;

        return match ($op) {
            '_eq'          => ["{$col} = ?",         [$val]],
            '_neq'         => ["{$col} != ?",         [$val]],
            '_lt'          => ["{$col} < ?",          [$val]],
            '_lte'         => ["{$col} <= ?",         [$val]],
            '_gt'          => ["{$col} > ?",          [$val]],
            '_gte'         => ["{$col} >= ?",         [$val]],

            // Note: SQLite LIKE and MySQL LIKE (default collation) are both
            // case-insensitive for ASCII. _contains is an alias here; a future
            // iteration will use BINARY LIKE (MySQL) / GLOB (SQLite) for strict
            // case sensitivity when _contains is requested.
            '_contains'    => ["{$col} LIKE ?",       ['%' . $val . '%']],
            '_icontains'   => ["{$col} LIKE ?",       ['%' . $val . '%']],
            '_ncontains'   => ["{$col} NOT LIKE ?",   ['%' . $val . '%']],
            '_starts_with' => ["{$col} LIKE ?",       [$val . '%']],
            '_ends_with'   => ["{$col} LIKE ?",       ['%' . $val]],

            '_in'  => $this->buildIn($col, (array) $val, false),
            '_nin' => $this->buildIn($col, (array) $val, true),

            // URL query params arrive as strings; normalise "false"/"0" → false.
            '_null'  => $this->isTruthy($val) ? ["{$col} IS NULL",     []] : ["{$col} IS NOT NULL", []],
            '_nnull' => $this->isTruthy($val) ? ["{$col} IS NOT NULL", []] : ["{$col} IS NULL",     []],

            '_between' => $this->buildBetween($col, $val),
        };
    }

    /**
     * Normalise a value that may be a PHP bool, a JSON-decoded bool, or a
     * URL query-string that arrived as a string ("true"/"false"/"1"/"0").
     * Used for _null / _nnull where the semantic is boolean.
     */
    private function isTruthy(mixed $val): bool
    {
        if (is_bool($val)) return $val;
        if (is_int($val))  return $val !== 0;
        $s = strtolower(trim((string) $val));
        return !in_array($s, ['false', '0', 'no', 'off', ''], true);
    }

    /** Build IN / NOT IN with a placeholder per value. */
    private function buildIn(string $col, array $vals, bool $negate): array
    {
        if (empty($vals)) {
            // IN () is a SQL error; use a condition that is always false / true.
            return $negate ? ['1=1', []] : ['1=0', []];
        }
        $placeholders = implode(', ', array_fill(0, count($vals), '?'));
        $not          = $negate ? 'NOT ' : '';
        return ["{$col} {$not}IN ({$placeholders})", array_values($vals)];
    }

    /** Build BETWEEN from a two-element array. */
    private function buildBetween(string $col, mixed $val): array
    {
        if (!is_array($val) || count($val) !== 2) {
            throw new FilterException("_between requires a two-element array [low, high].");
        }
        return ["{$col} BETWEEN ? AND ?", [$val[0], $val[1]]];
    }

    // ── Field validation ───────────────────────────────────────────────────────

    /**
     * Validate that $name is a real column in this table (config allow-list).
     * The implicit `id` primary key is always permitted.
     *
     * @throws FilterException if the field is not found in the table config
     */
    private function validateField(string $name): string
    {
        // Reject anything that looks like a logical operator key.
        if (in_array($name, self::LOGICAL_OPS, true)) {
            throw new FilterException("'{$name}' is a logical operator, not a field name.");
        }

        // 'id' is always allowed (implicit primary key, not in fields config).
        if ($name === 'id') {
            return $name;
        }

        $fields    = $this->cfg->get("tables.{$this->tb}.fields") ?? [];
        $fieldNames = array_column(is_array($fields) ? $fields : [], 'name');

        if (!in_array($name, $fieldNames, true)) {
            throw new FilterException(
                "Field '{$name}' does not exist in table '{$this->tb}'."
            );
        }

        return $name;
    }
}
