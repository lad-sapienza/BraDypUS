<?php

/**
 * @copyright 2007-2025 Julian Bogdani
 * @license AGPL-3.0; see LICENSE
 *
 * Converts Directus-style ?filter=... parameters into a SQL WHERE clause
 * with bound values.
 *
 * Accepted input formats:
 *   1. JSON string: ?filter={"field":{"_eq":"value"}}
 *   2. PHP array:   ?filter[field][_eq]=value  (parsed automatically by PHP)
 *
 * Supported operators:
 *   _eq, _neq, _lt, _lte, _gt, _gte,
 *   _contains, _ncontains, _starts_with, _ends_with,
 *   _null, _nnull, _empty, _nempty,
 *   _in, _nin, _between,
 *   _and, _or  (logical grouping)
 */

namespace API\V1;

class Filter
{
    /** Collected bind values in order of appearance. */
    private array $values = [];

    /** Fully-qualified table name used to prefix column references. */
    private string $tb;

    public function __construct(string $tb)
    {
        $this->tb = $tb;
    }

    /**
     * Parse the ?filter parameter from the current request.
     *
     * @return array [string|null $whereSql, array $values]
     *               $whereSql is null when no valid filter was found.
     */
    public function parse(): array
    {
        $raw = $_GET['filter'] ?? null;
        if (!$raw) {
            return [null, []];
        }

        // PHP may have already decoded ?filter[field][op]=val into an array.
        if (is_string($raw)) {
            $filter = json_decode($raw, true);
            if (!is_array($filter)) {
                return [null, []];
            }
        } else {
            $filter = $raw;
        }

        $this->values = [];
        $sql = $this->buildGroup($filter, 'AND');

        return $sql ? [$sql, $this->values] : [null, []];
    }

    // ── Private helpers ───────────────────────────────────────────────────────

    private function buildGroup(array $filter, string $logic): string
    {
        $parts = [];

        foreach ($filter as $key => $value) {
            if ($key === '_and' && is_array($value)) {
                $sub = [];
                foreach ($value as $group) {
                    $s = $this->buildGroup($group, 'AND');
                    if ($s) { $sub[] = "($s)"; }
                }
                if ($sub) { $parts[] = '(' . implode(' AND ', $sub) . ')'; }

            } elseif ($key === '_or' && is_array($value)) {
                $sub = [];
                foreach ($value as $group) {
                    $s = $this->buildGroup($group, 'AND');
                    if ($s) { $sub[] = "($s)"; }
                }
                if ($sub) { $parts[] = '(' . implode(' OR ', $sub) . ')'; }

            } elseif (is_array($value) && !str_starts_with($key, '_')) {
                // Field-level: { field: { _op: val } }
                $col = $this->tb . '.' . preg_replace('/[^a-zA-Z0-9_]/', '', $key);
                foreach ($value as $op => $val) {
                    $clause = $this->buildClause($col, $op, $val);
                    if ($clause !== null) { $parts[] = $clause; }
                }
            }
        }

        return implode(" $logic ", $parts);
    }

    private function buildClause(string $col, string $op, $val): ?string
    {
        switch ($op) {
            case '_eq':
                $this->values[] = $val;
                return "$col = ?";

            case '_neq':
                $this->values[] = $val;
                return "$col != ?";

            case '_lt':
                $this->values[] = $val;
                return "$col < ?";

            case '_lte':
                $this->values[] = $val;
                return "$col <= ?";

            case '_gt':
                $this->values[] = $val;
                return "$col > ?";

            case '_gte':
                $this->values[] = $val;
                return "$col >= ?";

            case '_contains':
                $this->values[] = "%$val%";
                return "$col LIKE ?";

            case '_ncontains':
                $this->values[] = "%$val%";
                return "$col NOT LIKE ?";

            case '_starts_with':
                $this->values[] = "$val%";
                return "$col LIKE ?";

            case '_ends_with':
                $this->values[] = "%$val";
                return "$col LIKE ?";

            case '_null':
                return "$col IS NULL";

            case '_nnull':
                return "$col IS NOT NULL";

            case '_empty':
                return "($col IS NULL OR $col = '')";

            case '_nempty':
                return "($col IS NOT NULL AND $col != '')";

            case '_in':
                $items = array_map('trim', explode(',', (string)$val));
                foreach ($items as $i) { $this->values[] = $i; }
                $ph = implode(',', array_fill(0, count($items), '?'));
                return "$col IN ($ph)";

            case '_nin':
                $items = array_map('trim', explode(',', (string)$val));
                foreach ($items as $i) { $this->values[] = $i; }
                $ph = implode(',', array_fill(0, count($items), '?'));
                return "$col NOT IN ($ph)";

            case '_between':
                $p = array_map('trim', explode(',', (string)$val));
                if (count($p) === 2) {
                    $this->values[] = $p[0];
                    $this->values[] = $p[1];
                    return "$col BETWEEN ? AND ?";
                }
                return null;

            default:
                return null;
        }
    }
}
