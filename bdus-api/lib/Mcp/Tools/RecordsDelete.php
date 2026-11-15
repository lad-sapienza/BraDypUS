<?php

/**
 * @copyright 2007-2026 Julian Bogdani
 * @license AGPL-3.0; see LICENSE
 */

namespace Mcp\Tools;

use Mcp\Context;
use Mcp\ToolError;
use Record\Delete;

/**
 * Deletes records with everything that belongs to them. Off unless the
 * application's `delete` switch is on. One call is one transaction. A deleted
 * record stays in the history and an administrator can restore it.
 */
class RecordsDelete extends Base
{
    private const MAX_IDS = 50;

    public function name(): string
    {
        return 'records_delete';
    }

    public function needs(): ?string
    {
        return 'delete';
    }

    public function definition(): array
    {
        return [
            'title'       => 'Delete records',
            'description' => 'Deletes records of one table by id, with their plugin rows, links and relations. '
                . 'Call it only after the user has explicitly confirmed which records to delete. All are deleted '
                . 'or none is. An administrator can restore a deleted record from the history.',
            'inputSchema' => [
                'type'       => 'object',
                'properties' => [
                    'table' => ['type' => 'string'],
                    'ids'   => ['type' => 'array', 'items' => ['type' => 'integer', 'minimum' => 1], 'minItems' => 1, 'maxItems' => self::MAX_IDS],
                ],
                'required'             => ['table', 'ids'],
                'additionalProperties' => false,
            ],
            'annotations' => ['readOnlyHint' => false, 'destructiveHint' => true, 'idempotentHint' => true, 'openWorldHint' => false],
        ];
    }

    public function call(array $args, Context $ctx): array
    {
        $tb  = $this->table($args, $ctx);
        $ids = $args['ids'] ?? null;
        if (!is_array($ids) || !$ids || count($ids) > self::MAX_IDS
            || array_filter($ids, fn($id) => !is_int($id) || $id < 1)) {
            throw new ToolError('invalid_parameters', 'ids');
        }
        $ids = array_values(array_unique($ids));

        if (!Delete::mayOnTable($tb)) {
            throw new ToolError('not_enough_privilege');
        }

        $deleter = new Delete($ctx->db, $ctx->cfg, $ctx->access);

        $ctx->db->beginTransaction();
        try {
            foreach ($ids as $id) {
                $outcome = $deleter->delete($tb, $id);
                if ($outcome !== Delete::DELETED) {
                    $ctx->db->rollBack();
                    throw new ToolError('delete_refused', ['id' => $id, 'code' => $outcome, 'note' => 'Nothing was deleted.']);
                }
            }
            $ctx->db->commit();
        } catch (ToolError $e) {
            throw $e;
        } catch (\Throwable $e) {
            if ($ctx->db->inTransaction()) {
                $ctx->db->rollBack();
            }
            $ctx->log?->error($e);
            throw new ToolError('delete_refused', ['code' => 'db_error', 'note' => 'Nothing was deleted.']);
        }

        return ['table' => $tb, 'deleted' => count($ids), 'ids' => $ids];
    }
}
