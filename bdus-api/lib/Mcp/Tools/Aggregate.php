<?php

/**
 * @copyright 2007-2026 Julian Bogdani
 * @license AGPL-3.0; see LICENSE
 */

namespace Mcp\Tools;

use Mcp\Context;
use Mcp\ToolError;

/**
 * Counts and breakdowns for reports: how many records, grouped by which
 * values, with sums, averages and extremes. Same rows as `records_read`.
 */
class Aggregate extends Base
{
    private const DEFAULT_LIMIT = 200;
    private const MAX_LIMIT     = 1000;
    private const MAX_METRICS   = 6;

    public function name(): string
    {
        return 'aggregate';
    }

    public function definition(): array
    {
        return [
            'title'       => 'Count and group records',
            'description' => 'Counts and statistics over the records of one table, optionally grouped by one or two fields. '
                . 'Use it for totals and breakdowns instead of reading rows. `metrics` defaults to a count; `sum` and '
                . '`avg` need a numeric field. A lookup field in `group_by` also returns its readable label as "@field".',
            'inputSchema' => [
                'type'       => 'object',
                'properties' => [
                    'table'    => ['type' => 'string'],
                    'filter'   => ['type' => 'object', 'description' => 'Same JSON filter as records_read.'],
                    'search'   => ['type' => 'string'],
                    'group_by' => ['type' => 'array', 'items' => ['type' => 'string'], 'maxItems' => \SQL\Aggregate::MAX_GROUP_BY],
                    'metrics'  => [
                        'type'     => 'array',
                        'maxItems' => self::MAX_METRICS,
                        'items'    => [
                            'type'       => 'object',
                            'properties' => [
                                'fn'    => ['type' => 'string', 'enum' => ['count', 'count_distinct', 'sum', 'avg', 'min', 'max']],
                                'field' => ['type' => 'string', 'description' => 'Not needed for count.'],
                            ],
                            'required' => ['fn'],
                        ],
                    ],
                    'limit' => ['type' => 'integer', 'minimum' => 1, 'maximum' => self::MAX_LIMIT, 'default' => self::DEFAULT_LIMIT],
                ],
                'required'             => ['table'],
                'additionalProperties' => false,
            ],
            'annotations' => $this->readOnly(),
        ];
    }

    public function call(array $args, Context $ctx): array
    {
        $tb    = $this->table($args, $ctx);
        $limit = $this->int($args, 'limit', self::DEFAULT_LIMIT, 1, self::MAX_LIMIT);

        $groupBy = $args['group_by'] ?? [];
        if (!is_array($groupBy) || count($groupBy) > \SQL\Aggregate::MAX_GROUP_BY) {
            throw new ToolError('invalid_parameters', 'group_by');
        }
        $groupBy = array_values($groupBy);

        $wanted = $args['metrics'] ?? [['fn' => 'count']];
        if (!is_array($wanted) || !$wanted || count($wanted) > self::MAX_METRICS) {
            throw new ToolError('invalid_parameters', 'metrics');
        }
        $metrics = [];
        foreach ($wanted as $m) {
            $fn    = is_array($m) && is_string($m['fn'] ?? null) ? strtolower($m['fn']) : '';
            $field = is_array($m) && is_string($m['field'] ?? null) ? $m['field'] : null;
            if ($fn === 'count' && $field === null) {
                $metrics['count'] = ['COUNT', 'id'];
                continue;
            }
            if ($field === null || preg_match('/^[a-z0-9_]+$/i', $field) !== 1) {
                throw new ToolError('invalid_parameters', 'metrics.field');
            }
            $metrics[$fn . '_' . strtolower($field)] = [strtoupper($fn), $field];
        }

        $request = ['type' => 'all'];
        $filter  = $args['filter'] ?? null;
        $search  = isset($args['search']) && is_string($args['search']) ? trim($args['search']) : '';
        if ($filter !== null && !is_array($filter)) {
            throw new ToolError('invalid_parameters', 'filter');
        }
        if ($filter) {
            $request = ['type' => 'filter', 'filter' => $filter];
        } elseif ($search !== '') {
            $request = ['type' => 'fast', 'string' => $search];
        }

        try {
            $out = (new \SQL\Aggregate($ctx->db, $ctx->cfg))->run($tb, $groupBy, $metrics, $request, $limit);
        } catch (\InvalidArgumentException $e) {
            throw new ToolError('invalid_parameters', $e->getMessage());
        } catch (\SQL\Filter\FilterException $e) {
            throw new ToolError('invalid_filter', $e->getMessage());
        } catch (\Throwable $e) {
            $ctx->log?->error($e);
            throw new ToolError('db_error', $e->getMessage());
        }

        return [
            'table'     => $tb,
            'group_by'  => $groupBy,
            'truncated' => $out['truncated'],
            'groups'    => $this->withLabels($tb, $groupBy, $out['rows'], $ctx),
        ];
    }

    /**
     * Adds "@field" (the readable label) next to a group field that is a
     * lookup, when the principal may read the table it points to.
     */
    private function withLabels(string $tb, array $groupBy, array $rows, Context $ctx): array
    {
        foreach ($groupBy as $field) {
            $ref = $ctx->cfg->get("tables.{$tb}.fields.{$field}.id_from_tb");
            if (!$ref || !\Auth\Authorization::can('read', table: $ref)) {
                continue;
            }
            $ids = array_values(array_unique(array_filter(
                array_column($rows, $field),
                fn($v) => is_numeric($v)
            )));
            $labels = [];
            if ($ids) {
                $label = $ctx->cfg->get("tables.{$ref}.id_field") ?: 'id';
                [$cond, $values] = $ctx->access->rowCondition('read', $ref);
                $found = $ctx->db->query(
                    "SELECT {$ref}.id AS id, {$ref}.{$label} AS lbl FROM {$ref} WHERE {$ref}.id IN ("
                        . implode(',', array_fill(0, count($ids), '?')) . ") AND ({$cond})",
                    [...$ids, ...$values],
                    'read'
                ) ?: [];
                $labels = array_column($found, 'lbl', 'id');
            }
            foreach ($rows as &$row) {
                $row['@' . $field] = $labels[$row[$field] ?? ''] ?? null;
            }
            unset($row);
        }
        return $rows;
    }
}
