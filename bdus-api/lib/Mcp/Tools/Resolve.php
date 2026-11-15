<?php

/**
 * @copyright 2007-2026 Julian Bogdani
 * @license AGPL-3.0; see LICENSE
 */

namespace Mcp\Tools;

use Mcp\Context;
use Mcp\ToolError;
use SQL\QueryFromRequest;

/**
 * Turns the names people give to records ("US 1023", a site name) into ids,
 * by the key field of the table. A key field is not guaranteed unique: every
 * match is returned, and it is for the caller to check them.
 */
class Resolve extends Base
{
    private const MAX_VALUES  = 100;
    private const MAX_MATCHES = 5;

    public function name(): string
    {
        return 'resolve';
    }

    public function definition(): array
    {
        return [
            'title'       => 'Resolve names to ids',
            'description' => 'For each value, the records of `table` whose key field equals it exactly: their id and main '
                . 'fields. `count` 0 means no such record, more than 1 means the name is ambiguous — ask the user.',
            'inputSchema' => [
                'type'       => 'object',
                'properties' => [
                    'table'  => ['type' => 'string'],
                    'values' => ['type' => 'array', 'items' => ['type' => ['string', 'number']], 'minItems' => 1, 'maxItems' => self::MAX_VALUES],
                ],
                'required'             => ['table', 'values'],
                'additionalProperties' => false,
            ],
            'annotations' => $this->readOnly(),
        ];
    }

    public function call(array $args, Context $ctx): array
    {
        $tb     = $this->table($args, $ctx);
        $values = $args['values'] ?? null;
        if (!is_array($values) || !$values || count($values) > self::MAX_VALUES
            || array_filter($values, fn($v) => !is_string($v) && !is_int($v) && !is_float($v))) {
            throw new ToolError('invalid_parameters', 'values');
        }
        $key = $ctx->cfg->get("tables.{$tb}.id_field") ?: 'id';

        $out = [];
        foreach (array_values($values) as $value) {
            try {
                $query = new QueryFromRequest(
                    $ctx->db,
                    $ctx->cfg,
                    ['tb' => $tb, 'type' => 'filter', 'filter' => [$key => ['_eq' => $value]]],
                    true,
                    true
                );
                $total = $query->getTotal();
                $query->setLimit(0, self::MAX_MATCHES);
                $rows = $query->getResults();
            } catch (\Throwable $e) {
                $ctx->log?->error($e);
                throw new ToolError('db_error', $e->getMessage());
            }
            $out[] = ['value' => $value, 'count' => $total, 'matches' => array_values($rows)];
        }

        return ['table' => $tb, 'key_field' => $key, 'results' => $out];
    }
}
