<?php

/**
 * @copyright 2007-2026 Julian Bogdani
 * @license AGPL-3.0; see LICENSE
 */

namespace Mcp\Tools;

use Mcp\Context;
use Mcp\ToolError;
use Record\Read;
use Record\Rs;

/**
 * The stratigraphic relations of one record, with the ids `rs_write` removes them by.
 * A relation whose other record the principal may not read is left out, as if it
 * did not exist.
 */
class RsRead extends Base
{
    public function name(): string
    {
        return 'rs_read';
    }

    public function definition(): array
    {
        return [
            'title'       => 'Stratigraphic relations of a record',
            'description' => 'The relations (Harris matrix) a record is in, each read "first <relation> second", with the id '
                . 'to remove it by and the readable label of both records. Only for tables with stratigraphic relations.',
            'inputSchema' => [
                'type'       => 'object',
                'properties' => [
                    'table' => ['type' => 'string'],
                    'id'    => ['type' => 'integer', 'minimum' => 1],
                ],
                'required'             => ['table', 'id'],
                'additionalProperties' => false,
            ],
            'annotations' => $this->readOnly(),
        ];
    }

    public function call(array $args, Context $ctx): array
    {
        $tb = $this->table($args, $ctx);
        $id = $this->int($args, 'id', 0, 0, PHP_INT_MAX);
        if ($id < 1) {
            throw new ToolError('parameter_missing', 'id');
        }
        if (!(new Rs($ctx->db, $ctx->cfg, $ctx->access))->enabled($tb)) {
            throw new ToolError('rs_not_configured', $tb);
        }
        if (!$this->readable($ctx, $tb, $id)) {
            throw new ToolError('not_enough_privilege');
        }
        if (!$ctx->db->query("SELECT 1 FROM {$tb} WHERE id = ?", [$id], 'read')) {
            throw new ToolError('record_not_found');
        }

        $relations = [];
        foreach ((new Read($id, null, $tb, $ctx->db, $ctx->cfg))->getRs() as $row) {
            $other = (int) $row['first'] === $id ? (int) $row['second'] : (int) $row['first'];
            if (!$this->readable($ctx, $tb, $other)) {
                continue;
            }
            $name        = Rs::RELATIONS[$row['relation']] ?? (string) $row['relation'];
            $relations[] = [
                'id'           => (int) $row['id'],
                'first'        => (int) $row['first'],
                'first_label'  => $row['first_label'],
                'relation'     => (int) $row['relation'],
                'name'         => $name,
                'second'       => (int) $row['second'],
                'second_label' => $row['second_label'],
                'reading'      => $row['first_label'] . ' ' . str_replace('_', ' ', $name) . ' ' . $row['second_label'],
            ];
        }

        return ['table' => $tb, 'id' => $id, 'relations' => $relations];
    }

    private function readable(Context $ctx, string $tb, int $id): bool
    {
        return $ctx->access->unrestricted('read', $tb) || $ctx->access->rowAllowed('read', $tb, $id);
    }
}
