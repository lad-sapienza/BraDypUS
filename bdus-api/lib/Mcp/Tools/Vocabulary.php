<?php

/**
 * @copyright 2007-2026 Julian Bogdani
 * @license AGPL-3.0; see LICENSE
 */

namespace Mcp\Tools;

use Mcp\Context;
use Mcp\ToolError;
use Record\FieldOptions;

/**
 * The values a field accepts: from a vocabulary, a static list, the column
 * of another table or the records of a lookup table (limited to readable rows).
 */
class Vocabulary extends Base
{
    private const DEFAULT_LIMIT = 200;
    private const MAX_LIMIT     = 1000;

    public function name(): string
    {
        return 'vocabulary';
    }

    public function definition(): array
    {
        return [
            'title'       => 'Vocabulary',
            'description' => 'The values a field accepts, as { value, label }. For a lookup field `value` is the id '
                . 'to store and `label` what people read. Fields without a source of values return an empty list.',
            'inputSchema' => [
                'type'       => 'object',
                'properties' => [
                    'table'  => ['type' => 'string', 'description' => 'Table (or plugin table) the field belongs to.'],
                    'field'  => ['type' => 'string'],
                    'search' => ['type' => 'string', 'description' => 'Keep only the values whose label contains this text.'],
                    'limit'  => ['type' => 'integer', 'minimum' => 1, 'maximum' => self::MAX_LIMIT, 'default' => self::DEFAULT_LIMIT],
                ],
                'required'             => ['table', 'field'],
                'additionalProperties' => false,
            ],
            'annotations' => $this->readOnly(),
        ];
    }

    public function call(array $args, Context $ctx): array
    {
        $tb  = $this->table($args, $ctx, allowPlugin: true);
        $fld = $args['field'] ?? null;
        if (!is_string($fld) || !in_array($fld, array_column($ctx->cfg->get("tables.{$tb}.fields") ?: [], 'name'), true)) {
            throw new ToolError('unknown_field', is_string($fld) ? $fld : null);
        }
        $limit = $this->int($args, 'limit', self::DEFAULT_LIMIT, 1, self::MAX_LIMIT);

        $values = (new FieldOptions($ctx->db, $ctx->cfg, $ctx->access))->resolve($tb, $fld);

        $search = isset($args['search']) && is_string($args['search']) ? mb_strtolower(trim($args['search'])) : '';
        if ($search !== '') {
            $values = array_values(array_filter(
                $values,
                fn($v) => str_contains(mb_strtolower((string) $v['label']), $search)
            ));
        }

        return [
            'table'     => $tb,
            'field'     => $fld,
            'total'     => count($values),
            'truncated' => count($values) > $limit,
            'values'    => array_slice($values, 0, $limit),
        ];
    }
}
