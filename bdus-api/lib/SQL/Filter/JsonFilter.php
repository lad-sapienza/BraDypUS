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
 * ## Cross-table condition — plugin (table_link / id_link)
 *   [ "photos" => [ "description" => ["_icontains" => "amphora"] ] ]
 *   → id IN (SELECT id_link FROM photos WHERE table_link = ? AND description LIKE ?)
 *
 * ## Cross-table condition — backlinked table (explicit FK column)
 *   [ "m_msplaces" => [ "type" => ["_eq" => "discovery"] ] ]
 *   → places.id IN (SELECT place FROM m_msplaces WHERE type = ?)
 *   (requires "manuscripts:m_msplaces:place" in cfg.tables.places.backlinks)
 *
 * ## Two-hop cross-table condition (backlink → plugin_of parent)
 *   [ "m_msplaces" => [ "manuscripts" => [ "palimpsest" => ["_eq" => 1] ] ] ]
 *   → places.id IN (
 *        SELECT place FROM m_msplaces
 *        WHERE table_link = 'manuscripts'
 *          AND id_link IN (SELECT id FROM manuscripts WHERE palimpsest = ?)
 *      )
 *   Requires: "manuscripts:m_msplaces:place" in places.backlinks
 *           AND cfg.tables.m_msplaces.plugin_of = 'manuscripts'
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
        '_empty', '_nempty',       // convenience: IS NULL OR = '' / IS NOT NULL AND != ''
        '_between',                // value: [low, high]
        '_chrono_overlap',         // fuzzy-date: [low, high] with NULL ante/post quem semantics
    ];

    /** chrono_* columns added to core tables by the fuzzy-date plugin. */
    private const CHRONO_FIELDS = [
        'chrono_from', 'chrono_to', 'chrono_label', 'chrono_certainty', 'chrono_period',
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
                        if ($op === '_chrono_overlap') {
                            [$sql, $vals] = $this->buildChronoOverlap($field, $val);
                        } else {
                            [$sql, $vals] = $this->buildCondition($col, $op, $val);
                        }
                        $parts[]  = $sql;
                        $values   = array_merge($values, $vals);
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
     * Dispatches a cross-table filter condition to the appropriate subquery builder.
     *
     * Two relationship styles are supported:
     *
     * 1. **Plugin table** (table_link / id_link convention)
     *    Configured via `cfg.tables.{main}.plugin[]`.
     *    → `{main}.id IN (SELECT id_link FROM {plugin} WHERE table_link = ? AND ...)`
     *
     * 2. **Backlinked table** (explicit FK column)
     *    Configured via `cfg.tables.{main}.backlinks[]` as `"{refTb}:{viaTb}:{fkCol}"`.
     *    The filter key is matched against `viaTb`; `fkCol` is the column in `viaTb`
     *    that points back to the current table.
     *    → `{main}.id IN (SELECT {fkCol} FROM {viaTb} WHERE ...)`
     *    Example: `places` backlink `manuscripts:m_msplaces:place`
     *    → `places.id IN (SELECT place FROM m_msplaces WHERE ...)`
     *
     * @param string $relatedTb   Related table name as supplied in the filter key
     * @param array  $conditions  { field: { _op: val }, ... }
     * @return array{0: string, 1: array}
     * @throws FilterException if $relatedTb is neither a plugin nor a backlinked table
     */
    private function buildRelatedCondition(string $relatedTb, array $conditions): array
    {
        // Path 1: direct plugin (table_link / id_link convention)
        $plugins = (array) ($this->cfg->get("tables.{$this->tb}.plugin") ?? []);
        if (in_array($relatedTb, $plugins, true)) {
            return $this->buildPluginSubquery($relatedTb, $conditions);
        }

        // Path 2: backlinked table (explicit FK column)
        $fkCol = $this->findBacklinkFkCol($relatedTb);
        if ($fkCol !== null) {
            return $this->buildFkSubquery($relatedTb, $fkCol, $conditions);
        }

        throw new FilterException(
            "'{$relatedTb}' is not a plugin or backlinked table of '{$this->tb}'."
        );
    }

    /**
     * Builds: `{main}.id IN (SELECT id_link FROM {plugin} WHERE table_link = ? AND ...)`
     *
     * @return array{0: string, 1: array}
     */
    private function buildPluginSubquery(string $pluginTb, array $conditions): array
    {
        [$subParts, $subValues] = $this->buildSubconditions($pluginTb, $conditions);

        if (empty($subParts)) {
            return ['1=1', []];
        }

        $sql = "{$this->tb}.id IN "
             . "(SELECT id_link FROM {$pluginTb} "
             . "WHERE table_link = ? AND " . implode(' AND ', $subParts) . ')';

        return [$sql, array_merge([$this->tb], $subValues)];
    }

    /**
     * Builds: `{main}.id IN (SELECT {fkCol} FROM {viaTb} WHERE ...)`
     *
     * Used when the related table references the current table via an explicit
     * FK column (backlink style), rather than the table_link / id_link convention.
     *
     * @return array{0: string, 1: array}
     */
    private function buildFkSubquery(string $viaTb, string $fkCol, array $conditions): array
    {
        [$subParts, $subValues] = $this->buildSubconditions($viaTb, $conditions);

        if (empty($subParts)) {
            return ['1=1', []];
        }

        $sql = "{$this->tb}.id IN "
             . "(SELECT {$fkCol} FROM {$viaTb} "
             . "WHERE " . implode(' AND ', $subParts) . ')';

        return [$sql, $subValues];
    }

    /**
     * Validates and compiles field/table conditions for a subquery on a related table.
     *
     * Each entry in $conditions is classified as one of:
     *
     * 1. **Field condition** — value's first key is a field operator (in ALLOWED_OPS):
     *    `{ field: { _eq: val } }` → compiled directly via buildCondition.
     *
     * 2. **Nested table reference** — value's first key is NOT a field operator
     *    (either a logical op `_and`/`_or`, or a bare field name):
     *    `{ nestedTb: { field: { _op: val } } }`
     *    `{ nestedTb: { _and: [ { field: { _op: val } }, … ] } }`
     *    → compiled via buildNestedCondition, which delegates to a recursive JsonFilter.
     *
     * The distinction is: field operators (`_eq`, `_lt`, …) vs logical operators
     * (`_and`, `_or`) vs non-operator keys (bare field/table names).
     * Only ALLOWED_OPS keys signal a field condition; `_and`/`_or` as a first
     * sub-key mean the enclosing key is a nested table, not a field name.
     *
     * @return array{0: string[], 1: array}  [sql_parts, bound_values]
     */
    private function buildSubconditions(string $relatedTb, array $conditions): array
    {
        $subParts  = [];
        $subValues = [];

        foreach ($conditions as $key => $opConditions) {
            $opArr    = (array) $opConditions;
            $firstKey = (string) (array_key_first($opArr) ?? '');

            if ($firstKey === '') {
                continue; // empty condition — skip
            }

            // A field condition has a field-operator (e.g. _eq, _lt) as its first key.
            // Logical operators (_and, _or) as the first key mean $key is a nested table.
            $isFieldOp = in_array($firstKey, self::ALLOWED_OPS, true);

            if ($isFieldOp) {
                // Field condition: { field: { _op: val } }
                $this->validatePluginField($relatedTb, $key);
                foreach ($opArr as $op => $val) {
                    [$condSql, $condVals] = $this->buildCondition($key, $op, $val);
                    $subParts[]  = $condSql;
                    $subValues   = array_merge($subValues, $condVals);
                }
            } else {
                // Nested table reference (field conditions or logical groups on a parent table).
                // { nestedTb: { field: { _op } } }  or  { nestedTb: { _and: [ … ] } }
                [$nestedSql, $nestedVals] = $this->buildNestedCondition($relatedTb, $key, $opArr);
                $subParts[]  = $nestedSql;
                $subValues   = array_merge($subValues, $nestedVals);
            }
        }

        return [$subParts, $subValues];
    }

    /**
     * Builds the WHERE fragments for a one-hop join from $viaTb to its plugin_of parent.
     *
     * This is the "second hop" when a filter traverses:
     *   main → via backlink/plugin ($viaTb) → plugin_of parent ($nestedTb)
     *
     * Example (PAThs, simple):
     *   filter[m_msplaces][manuscripts][palimpsest][_eq]=1
     *   → table_link = 'manuscripts' AND id_link IN (SELECT id FROM manuscripts WHERE manuscripts.palimpsest = ?)
     *
     * Example (PAThs, with logical group):
     *   filter[m_msplaces][manuscripts][_and][][chronofrom][_gt]=599
     *   filter[m_msplaces][manuscripts][_and][][chronofrom][_lt]=700
     *   → table_link = 'manuscripts' AND id_link IN (SELECT id FROM manuscripts WHERE (manuscripts.chronofrom > ? AND manuscripts.chronofrom < ?))
     *
     * Conditions on $nestedTb are compiled by creating a recursive JsonFilter for
     * that table, so all operators, logical groups, and field validation work correctly.
     *
     * Restriction: $nestedTb MUST equal cfg.tables.{viaTb}.plugin_of.
     * Three-or-more hops are not supported.
     *
     * @param string $viaTb      Intermediate table (e.g. 'm_msplaces')
     * @param string $nestedTb   Target table (e.g. 'manuscripts'); must be viaTb's plugin_of
     * @param array  $conditions Any filter structure valid for $nestedTb
     * @return array{0: string, 1: array}
     * @throws FilterException if $nestedTb is not viaTb's plugin_of parent
     */
    private function buildNestedCondition(string $viaTb, string $nestedTb, array $conditions): array
    {
        $pluginOf = $this->cfg->get("tables.{$viaTb}.plugin_of") ?: null;

        if ($pluginOf !== $nestedTb) {
            throw new FilterException(
                "'{$nestedTb}' is not a supported nested filter table of '{$viaTb}'. "
                . ($pluginOf
                    ? "Only '{$pluginOf}' (plugin_of parent) is supported."
                    : "'{$viaTb}' has no plugin_of parent configured.")
            );
        }

        // Delegate to a recursive JsonFilter for $nestedTb.
        // This handles all operators and logical groups (_and/_or) correctly,
        // with full field validation against the nested table's config.
        $nestedFilter = new self($this->cfg, $nestedTb);
        [$nestedSql, $nestedValues] = $nestedFilter->toSql($conditions);

        if ($nestedSql === '1=1') {
            return ['1=1', []];
        }

        $idField = $this->cfg->get("tables.{$nestedTb}.id_field") ?: 'id';

        // table_link = 'manuscripts' AND id_link IN (SELECT id FROM manuscripts WHERE …)
        $sql = "table_link = ? AND id_link IN "
             . "(SELECT {$idField} FROM {$nestedTb} WHERE {$nestedSql})";

        return [$sql, array_merge([$nestedTb], $nestedValues)];
    }

    /**
     * Returns the FK column name in $relatedTb that points back to $this->tb,
     * by scanning the backlinks config of $this->tb.
     *
     * Backlink format: "{refTb}:{viaTb}:{fkCol}"
     * We look for entries where viaTb === $relatedTb.
     *
     * Returns null if no matching backlink entry is found.
     */
    private function findBacklinkFkCol(string $relatedTb): ?string
    {
        $blData = (array) ($this->cfg->get("tables.{$this->tb}.backlinks") ?? []);
        foreach ($blData as $bl) {
            $parts = array_values(
                array_filter(array_map('trim', explode(':', (string) $bl)), 'strlen')
            );
            // parts: [0 => refTb, 1 => viaTb, 2 => fkCol]
            if (count($parts) === 3 && $parts[1] === $relatedTb) {
                return $parts[2];
            }
        }
        return null;
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

    /**
     * Chrono overlap: finds records whose fuzzy date intersects the range [low, high].
     *
     * Handles the three non-null date types:
     *   - Normal range (from ≤ high AND to ≥ low)
     *   - Ante quem   (from IS NULL AND to ≥ low)
     *   - Post quem   (to IS NULL AND from ≤ high)
     * Undated records (both NULL) never match.
     *
     * Must be applied to 'chrono_from'; pairs it automatically with 'chrono_to'.
     *
     * @param  string $field  Must be 'chrono_from'
     * @param  mixed  $val    Two-element array [low, high] (integer year values)
     * @return array{0: string, 1: array}
     * @throws FilterException on wrong field or invalid value shape
     */
    private function buildChronoOverlap(string $field, mixed $val): array
    {
        if ($field !== 'chrono_from') {
            throw new FilterException(
                "_chrono_overlap must be applied to 'chrono_from', got '{$field}'."
            );
        }
        if (!is_array($val) || count($val) !== 2) {
            throw new FilterException(
                "_chrono_overlap requires a two-element array [low, high]."
            );
        }
        [$low, $high] = [(int) $val[0], (int) $val[1]];

        $tb  = $this->tb;
        $sql = "("
             . "({$tb}.chrono_from <= ? AND {$tb}.chrono_to >= ?)"        // normal range overlap
             . " OR ({$tb}.chrono_from IS NULL AND {$tb}.chrono_to >= ?)"  // ante quem
             . " OR ({$tb}.chrono_to IS NULL AND {$tb}.chrono_from <= ?)"  // post quem
             . ")";

        return [$sql, [$high, $low, $low, $high]];
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
        // Allow chrono_* columns when the fuzzy-date plugin is active for this table.
        if (
            in_array($name, self::CHRONO_FIELDS, true) &&
            $this->cfg->get("tables.{$this->tb}.fuzzy_date")
        ) {
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
