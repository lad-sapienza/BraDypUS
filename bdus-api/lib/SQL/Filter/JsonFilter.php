<?php

/**
 * @copyright 2007-2025 Julian Bogdani
 * @license AGPL-3.0; see LICENSE
 */

namespace SQL\Filter;

use Config\Config;

/**
 * Converts a Directus-compatible JSON filter array to a SQL WHERE clause + bound values.
 *
 * ## Main-table field condition
 *   [ "status" => ["_eq" => "active"], "name" => ["_icontains" => "pompeii"] ]
 *
 * ## Cross-table (plugin) condition
 *   [ "photos" => [ "description" => ["_icontains" => "amphora"] ] ]
 *   → id IN (SELECT id_link FROM photos WHERE table_link = ? AND description LIKE ?)
 *
 * ## Logical groups
 *   [ "_and" => [ [...], [...] ] ]
 *   [ "_or"  => [ [...], [...] ] ]
 *
 * ## URL bracket notation (GET)
 *   ?filter[status][_eq]=active
 *   ?filter[photos][description][_icontains]=amphora
 *   ?filter[_or][0][type][_eq]=site&filter[_or][1][type][_eq]=find
 *
 * ## Base64-encoded GET param
 *   ?filter=BASE64_JSON   (backend auto-decodes; used for URL persistence)
 *
 * Security: field names are validated against the table config allow-list.
 * All values are bound via PDO prepared-statement placeholders.
 */
class JsonFilter
{
    /** Operators supported as array keys inside a field condition. */
    public const ALLOWED_OPS = [
        '_eq', '_neq',
        '_lt', '_lte', '_gt', '_gte',
        '_contains', '_icontains', '_ncontains',
        '_starts_with', '_ends_with',
        '_in', '_nin',
        '_null', '_nnull',
        '_empty', '_nempty',  // convenience: IS NULL OR = '' / IS NOT NULL AND != ''
        '_between',           // value: [low, high]
    ];

    private const LOGICAL_OPS = ['_and', '_or'];

    public function __construct(
        private readonly Config $cfg,
        private readonly string $tb
    ) {}

    // ── Public API ─────────────────────────────────────────────────────────────

    /**
     * Convert a filter array to [sql_where_clause, bound_values].
     * Returns ['1=1', []] for an empty filter.
     *
     * @param  array $filter  Nested filter array
     * @return array{0: string, 1: array}
     * @throws FilterException on invalid field name, operator, or plugin reference
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
        return ['(' . implode(' AND ', $parts) . ')', $values];
    }

    // ── Parsing ───────────��────────────────────────────��───────────────────────

    /**
     * Parse one filter node — a logical group, main-table field conditions,
     * or cross-table (plugin) conditions.
     *
     * Detection rule: if a key's value object has its first sub-key starting
     * with '_', it is a field condition  → { field: { _op: val } }.
     * Otherwise the key is a related table → { plugin: { field: { _op: val } } }.
     *
     * @return array{0: string[], 1: array}  [sql_parts, values]
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
                $valueArr    = (array) $value;
                $firstSubKey = array_key_first($valueArr);

                if ($firstSubKey === null) {
                    continue;  // empty condition — skip
                }

                if (str_starts_with((string) $firstSubKey, '_')) {
                    // Main-table field condition: { field: { _op: val, ... } }
                    $field = $this->validateField($key);
                    $col   = $this->tb . '.' . $field;
                    foreach ($valueArr as $op => $val) {
                        [$sql, $vals] = $this->buildCondition($col, $op, $val);
                        $parts[]      = $sql;
                        $values       = array_merge($values, $vals);
                    }
                } else {
                    // Cross-table (plugin) condition: { plugin: { field: { _op: val }, ... } }
                    [$sql, $vals] = $this->buildRelatedCondition($key, $valueArr);
                    $parts[]      = $sql;
                    $values       = array_merge($values, $vals);
                }
            }
        }

        return [$parts, $values];
    }

    /** @return array{0: string, 1: array} */
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
     * Builds an `id IN (SELECT id_link FROM plugin WHERE table_link=? AND …)` subquery.
     *
     * @param string $relatedTb   Plugin table name (validated against $this->tb plugin list)
     * @param array  $conditions  { field: { _op: val }, ... }
     * @return array{0: string, 1: array}
     * @throws FilterException if $relatedTb is not a plugin of $this->tb
     */
    private function buildRelatedCondition(string $relatedTb, array $conditions): array
    {
        $plugins = (array) ($this->cfg->get("tables.{$this->tb}.plugin") ?? []);
        if (!in_array($relatedTb, $plugins, true)) {
            throw new FilterException("'{$relatedTb}' is not a plugin of '{$this->tb}'.");
        }

        $subParts  = [];
        $subValues = [];

        foreach ($conditions as $field => $opConditions) {
            $this->validatePluginField($relatedTb, $field);
            foreach ((array) $opConditions as $op => $val) {
                [$condSql, $condVals] = $this->buildCondition($field, $op, $val);
                $subParts[]  = $condSql;
                $subValues   = array_merge($subValues, $condVals);
            }
        }

        if (empty($subParts)) {
            return ['1=1', []];
        }

        $sql = "{$this->tb}.id IN "
             . "(SELECT id_link FROM {$relatedTb} "
             . "WHERE table_link = ? AND " . implode(' AND ', $subParts) . ')';

        return [$sql, array_merge([$this->tb], $subValues)];
    }

    /**
     * Build a single SQL condition for one column + one operator + value.
     *
     * @param string $col  Qualified column (e.g. 'us.status') or bare field name
     *                     when used inside a subquery context.
     * @return array{0: string, 1: array}
     * @throws FilterException on unknown operator or invalid value shape
     */
    private function buildCondition(string $col, string $op, mixed $val): array
    {
        if (!in_array($op, self::ALLOWED_OPS, true)) {
            throw new FilterException("Unknown filter operator: {$op}");
        }

        return match ($op) {
            '_eq'          => ["{$col} = ?",          [$val]],
            '_neq'         => ["{$col} != ?",          [$val]],
            '_lt'          => ["{$col} < ?",           [$val]],
            '_lte'         => ["{$col} <= ?",          [$val]],
            '_gt'          => ["{$col} > ?",           [$val]],
            '_gte'         => ["{$col} >= ?",          [$val]],
            '_contains'    => ["{$col} LIKE ?",        ['%' . $val . '%']],
            '_icontains'   => ["{$col} LIKE ?",        ['%' . $val . '%']],
            '_ncontains'   => ["{$col} NOT LIKE ?",    ['%' . $val . '%']],
            '_starts_with' => ["{$col} LIKE ?",        [$val . '%']],
            '_ends_with'   => ["{$col} LIKE ?",        ['%' . $val]],
            '_in'          => $this->buildIn($col, (array) $val, false),
            '_nin'         => $this->buildIn($col, (array) $val, true),
            '_null'        => $this->isTruthy($val) ? ["{$col} IS NULL",     []] : ["{$col} IS NOT NULL", []],
            '_nnull'       => $this->isTruthy($val) ? ["{$col} IS NOT NULL", []] : ["{$col} IS NULL",     []],
            '_empty'       => ["({$col} IS NULL OR {$col} = '')",        []],
            '_nempty'      => ["({$col} IS NOT NULL AND {$col} != '')",  []],
            '_between'     => $this->buildBetween($col, $val),
        };
    }

    // ── Helpers ────────────────────────────────────────────────────────────────

    private function isTruthy(mixed $val): bool
    {
        if (is_bool($val)) return $val;
        if (is_int($val))  return $val !== 0;
        return !in_array(strtolower(trim((string) $val)), ['false', '0', 'no', 'off', ''], true);
    }

    private function buildIn(string $col, array $vals, bool $negate): array
    {
        if (empty($vals)) {
            return $negate ? ['1=1', []] : ['1=0', []];
        }
        $placeholders = implode(', ', array_fill(0, count($vals), '?'));
        $not          = $negate ? 'NOT ' : '';
        return ["{$col} {$not}IN ({$placeholders})", array_values($vals)];
    }

    private function buildBetween(string $col, mixed $val): array
    {
        if (!is_array($val) || count($val) !== 2) {
            throw new FilterException("_between requires a two-element array [low, high].");
        }
        return ["{$col} BETWEEN ? AND ?", [$val[0], $val[1]]];
    }

    // ── Field validation ───────────────────────────────────────────────────────

    private function validateField(string $name): string
    {
        if (in_array($name, self::LOGICAL_OPS, true)) {
            throw new FilterException("'{$name}' is a logical operator, not a field name.");
        }
        if ($name === 'id') {
            return $name;
        }
        $fields     = $this->cfg->get("tables.{$this->tb}.fields") ?? [];
        $fieldNames = array_column(is_array($fields) ? $fields : [], 'name');
        if (!in_array($name, $fieldNames, true)) {
            throw new FilterException(
                "Field '{$name}' does not exist in table '{$this->tb}'."
            );
        }
        return $name;
    }

    private function validatePluginField(string $tb, string $field): void
    {
        // Structural plugin columns are always permitted
        if (in_array($field, ['id', 'id_link', 'table_link'], true)) {
            return;
        }
        $fields     = $this->cfg->get("tables.{$tb}.fields") ?? [];
        $fieldNames = array_column(is_array($fields) ? $fields : [], 'name');
        if (!in_array($field, $fieldNames, true)) {
            throw new FilterException(
                "Field '{$field}' does not exist in plugin table '{$tb}'."
            );
        }
    }
}
