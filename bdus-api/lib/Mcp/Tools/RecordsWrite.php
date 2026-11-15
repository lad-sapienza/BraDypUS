<?php

/**
 * @copyright 2007-2026 Julian Bogdani
 * @license AGPL-3.0; see LICENSE
 */

namespace Mcp\Tools;

use Mcp\Context;
use Mcp\ToolError;
use Record\Save;

/**
 * Creates and updates records, with their plugin rows, through Record\Save —
 * the write path of the REST API. One call is one transaction: if any record
 * is refused, nothing is written.
 */
class RecordsWrite extends Base
{
    public function name(): string
    {
        return 'records_write';
    }

    public function needs(): ?string
    {
        return 'write';
    }

    public function definition(): array
    {
        return [
            'title'       => 'Create or update records',
            'description' => 'Writes the records validated by `records_validate`: same `table`, same `records`, and its '
                . '`validation_token`. Call it only after the user has seen and approved what will change. All records '
                . 'are written together or none is: on a refusal nothing changes and the failing record is named.',
            'inputSchema' => RecordsValidate::inputSchema(true),
            'annotations' => ['readOnlyHint' => false, 'destructiveHint' => true, 'idempotentHint' => false, 'openWorldHint' => false],
        ];
    }

    public function call(array $args, Context $ctx): array
    {
        $tb      = $this->table($args, $ctx);
        $records = RecordsValidate::records($args);

        if (!RecordsValidate::tokenMatches($args['validation_token'] ?? null, $tb, $records, $ctx)) {
            throw new ToolError(
                'validation_token_invalid',
                'Call records_validate with exactly this table and these records, then use the token it returns (valid 15 minutes).'
            );
        }

        $save    = new Save($ctx->db, $ctx->cfg, $ctx->access, $ctx->log);
        $results = [];

        $ctx->db->beginTransaction();
        try {
            foreach ($records as $i => $rec) {
                $res = $save->save($tb, $rec['id'], $rec['core'], $rec['plugins']);
                if (($res['status'] ?? '') !== 'success') {
                    $ctx->db->rollBack();
                    throw new ToolError('write_refused', array_filter([
                        'index'  => $i,
                        'code'   => $res['code'] ?? 'error',
                        'errors' => $res['errors'] ?? null,
                        'detail' => $res['detail'] ?? null,
                        'note'   => 'Nothing was written.',
                    ], fn($v) => $v !== null));
                }
                $results[] = ['index' => $i, 'action' => $rec['id'] ? 'updated' : 'created', 'id' => (int) $res['id']];
            }
            $ctx->db->commit();
        } catch (ToolError $e) {
            throw $e;
        } catch (\Throwable $e) {
            if ($ctx->db->inTransaction()) {
                $ctx->db->rollBack();
            }
            $ctx->log?->error($e);
            throw new ToolError('write_refused', ['code' => 'error_saved', 'note' => 'Nothing was written.']);
        }

        return ['table' => $tb, 'written' => count($results), 'results' => $results];
    }
}
