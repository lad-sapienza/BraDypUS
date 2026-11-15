<?php

/**
 * @copyright 2007-2026 Julian Bogdani
 * @license AGPL-3.0; see LICENSE
 */

namespace Mcp\Tools;

use Mcp\Context;
use Mcp\ToolError;
use SQL\QueryFromRequest;
use Record\Expand;

/**
 * A page of records of a table: filter or text search, chosen fields
 * (related rows nested), sort, paging. Built on the same classes as
 * GET /api/records/{tb}, so row rules apply exactly as there.
 */
class RecordsRead extends Base
{
    private const DEFAULT_LIMIT = 25;
    private const MAX_LIMIT     = 200;

    public function name(): string
    {
        return 'records_read';
    }

    public function definition(): array
    {
        return [
            'title'       => 'Read records',
            'description' => 'A page of records of one table. `filter` is a JSON filter (see `guide`), `search` a plain '
                . 'text search. `fields` chooses the columns and nests related rows ("plugin_table.*", "lookup_field.*"); '
                . 'without it the main fields of the table are returned. One record: filter { "id": { "_eq": 12 } }.',
            'inputSchema' => [
                'type'       => 'object',
                'properties' => [
                    'table'  => ['type' => 'string'],
                    'filter' => ['type' => 'object', 'description' => '{ "field": { "_op": value } }, with _and / _or.'],
                    'search' => ['type' => 'string'],
                    'fields' => ['type' => 'array', 'items' => ['type' => 'string']],
                    'sort'   => ['type' => 'array', 'items' => ['type' => 'string'], 'description' => 'Field names; prefix with "-" for descending.'],
                    'limit'  => ['type' => 'integer', 'minimum' => 1, 'maximum' => self::MAX_LIMIT, 'default' => self::DEFAULT_LIMIT],
                    'offset' => ['type' => 'integer', 'minimum' => 0, 'default' => 0],
                ],
                'required'             => ['table'],
                'additionalProperties' => false,
            ],
            'annotations' => $this->readOnly(),
        ];
    }

    public function call(array $args, Context $ctx): array
    {
        $tb     = $this->table($args, $ctx);
        $limit  = $this->int($args, 'limit', self::DEFAULT_LIMIT, 1, self::MAX_LIMIT);
        $offset = $this->int($args, 'offset', 0, 0, PHP_INT_MAX);

        $request = ['tb' => $tb, 'type' => 'all'];
        $filter  = $args['filter'] ?? null;
        $search  = isset($args['search']) && is_string($args['search']) ? trim($args['search']) : '';
        if ($filter !== null && !is_array($filter)) {
            throw new ToolError('invalid_parameters', 'filter');
        }
        if ($filter) {
            $request['type']   = 'filter';
            $request['filter'] = $filter;
        } elseif ($search !== '') {
            $request['type']   = 'fast';
            $request['string'] = $search;
        }

        $known  = $ctx->cfg->get("tables.{$tb}.fields.*") ?? [];
        $expand = null;
        $fields = $args['fields'] ?? null;
        if ($fields !== null) {
            if (!is_array($fields) || array_filter($fields, fn($f) => !is_string($f))) {
                throw new ToolError('invalid_parameters', 'fields');
            }
            if ($fields) {
                try {
                    $expand = Expand::parse(array_values($fields), $ctx->db, $ctx->cfg, $tb);
                } catch (\InvalidArgumentException $e) {
                    throw new ToolError('invalid_parameters', $e->getMessage());
                }
                $map = ['id' => 'id'];
                foreach ($expand->mainColumns() as $col) {
                    if ($col !== 'id' && isset($known[$col])) {
                        $map[$col] = $known[$col]['label'] ?? $col;
                    }
                }
                $request['fields'] = $map;
            }
        }

        $sort = [];
        foreach ((array) ($args['sort'] ?? []) as $tok) {
            if (!is_string($tok)) {
                throw new ToolError('invalid_parameters', 'sort');
            }
            $name = ltrim($tok, '-');
            if ($name !== 'id' && !isset($known[$name])) {
                throw new ToolError('unknown_field', $name);
            }
            $sort[] = [$name, str_starts_with($tok, '-') ? 'desc' : 'asc'];
        }

        try {
            $query = new QueryFromRequest($ctx->db, $ctx->cfg, $request, !isset($request['fields']), true);
            $total = $query->getTotal();
            if (count($sort) === 1) {
                $query->setOrder($sort[0][0], $sort[0][1]);
            } elseif ($sort) {
                $query->setOrderBy($sort);
            }
            $query->setLimit($offset, $limit);
            $rows = $query->getResults();
            if ($expand) {
                $rows = $expand->apply($rows);
            }
        } catch (\LengthException $e) {
            throw new ToolError('too_many_nested_rows', $e->getMessage());
        } catch (\SQL\Filter\FilterException $e) {
            throw new ToolError('invalid_filter', $e->getMessage());
        } catch (ToolError $e) {
            throw $e;
        } catch (\Throwable $e) {
            $ctx->log?->error($e);
            throw new ToolError('db_error', $e->getMessage());
        }

        return [
            'table'     => $tb,
            'total'     => $total,
            'returned'  => count($rows),
            'offset'    => $offset,
            'truncated' => $total > $offset + count($rows),
            'rows'      => array_values($rows),
        ];
    }
}
