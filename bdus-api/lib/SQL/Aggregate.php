<?php

/**
 * @copyright 2007-2026 Julian Bogdani
 * @license AGPL-3.0; see LICENSE
 */

namespace SQL;

use DB\DBInterface;
use Config\Config;

/**
 * Counts and breakdowns over the rows of a table: COUNT, SUM, AVG, MIN, MAX,
 * optionally grouped by one or two fields. The rows are those of a
 * QueryFromRequest request (a filter, a text search, or everything), so the
 * row-level rules of the principal's groups apply as in any list.
 *
 * Field names come from the caller and end up in SQL: every one is checked
 * against the table configuration first.
 */
class Aggregate
{
    public const FUNCTIONS = ['COUNT', 'COUNT_DISTINCT', 'SUM', 'AVG', 'MIN', 'MAX'];

    public const MAX_GROUP_BY = 2;

    public function __construct(
        private readonly DBInterface $db,
        private readonly Config $cfg,
    ) {}

    /** Is $field a column of $tb that may be named in an aggregate? (`id`, or a configured field) */
    public function isValidField(string $tb, string $field): bool
    {
        if ($field === 'id') {
            return true;
        }
        foreach ($this->cfg->get("tables.{$tb}.fields") ?: [] as $fld) {
            if (($fld['name'] ?? null) === $field) {
                return true;
            }
        }
        return false;
    }

    /** SQL of one aggregate; $function is one of FUNCTIONS, $field a checked (optionally qualified) column. */
    public static function expression(string $function, string $field): string
    {
        return $function === 'COUNT_DISTINCT'
            ? "COUNT(DISTINCT {$field})"
            : "{$function}({$field})";
    }

    /**
     * Runs the aggregate.
     *
     * @param string   $tb       table
     * @param string[] $groupBy  0 to MAX_GROUP_BY field names
     * @param array<string, array{0: string, 1: string}> $metrics  alias => [FUNCTION, field]
     * @param array    $request  QueryFromRequest request for the rows to count (`tb` is set here)
     * @param int      $limit    groups to return at most
     * @return array{rows: array[], truncated: bool}  one row per group: the group fields, then each alias
     *
     * @throws \InvalidArgumentException on a field, function or alias that is not allowed
     */
    public function run(string $tb, array $groupBy, array $metrics, array $request, int $limit): array
    {
        if (count($groupBy) > self::MAX_GROUP_BY || !$metrics) {
            throw new \InvalidArgumentException('group_by');
        }
        foreach ($groupBy as $field) {
            if (!is_string($field) || !$this->isValidField($tb, $field)) {
                throw new \InvalidArgumentException('field: ' . (is_string($field) ? $field : '?'));
            }
        }

        $select = array_map(fn($f) => "{$tb}.{$f} AS {$f}", $groupBy);
        foreach ($metrics as $alias => [$function, $field]) {
            if (!in_array($function, self::FUNCTIONS, true)) {
                throw new \InvalidArgumentException('function: ' . $function);
            }
            if (!$this->isValidField($tb, $field)) {
                throw new \InvalidArgumentException('field: ' . $field);
            }
            if (preg_match('/^[a-z][a-z0-9_]*$/', (string) $alias) !== 1 || in_array($alias, $groupBy, true)) {
                throw new \InvalidArgumentException('alias: ' . $alias);
            }
            $select[] = self::expression($function, "{$tb}.{$field}") . " AS {$alias}";
        }

        [$where, $values] = (new QueryFromRequest($this->db, $this->cfg, ['tb' => $tb] + $request))
            ->getWhereClause();

        $sql = 'SELECT ' . implode(', ', $select) . " FROM {$tb} WHERE {$where}";
        if ($groupBy) {
            $cols = implode(', ', array_map(fn($f) => "{$tb}.{$f}", $groupBy));
            $sql .= " GROUP BY {$cols} ORDER BY {$cols}";
        }
        $sql .= ' LIMIT ' . ($limit + 1);

        $rows = $this->db->query($sql, $values, 'read') ?: [];

        $aliases = array_keys($metrics);
        foreach ($rows as &$row) {
            foreach ($aliases as $alias) {
                if (is_numeric($row[$alias] ?? null)) {
                    $row[$alias] += 0;
                }
            }
        }
        unset($row);

        return ['rows' => array_slice($rows, 0, $limit), 'truncated' => count($rows) > $limit];
    }
}
