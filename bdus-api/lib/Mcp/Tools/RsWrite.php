<?php

/**
 * @copyright 2007-2026 Julian Bogdani
 * @license AGPL-3.0; see LICENSE
 */

namespace Mcp\Tools;

use Mcp\Context;
use Mcp\ToolError;
use Mcp\ValidationToken;
use Record\Rs;

/**
 * Adds and removes stratigraphic relations, through Record\Rs — the rules of the REST
 * endpoints. One call is one transaction: if any relation is refused, nothing changes.
 * Removals are done before additions. Relations are not part of a record's version
 * history (as with the REST API).
 */
class RsWrite extends Base
{
    public function name(): string
    {
        return 'rs_write';
    }

    public function needs(): ?string
    {
        return 'write';
    }

    public function definition(): array
    {
        return [
            'title'       => 'Add or remove stratigraphic relations',
            'description' => 'Writes the relations validated by `rs_validate`: same `table`, `add`, `remove` and its '
                . '`validation_token`. Call it only after the user has seen and approved what will change. All changes are '
                . 'made together or none is: on a refusal nothing changes and the failing item is named.',
            'inputSchema' => RsPayload::inputSchema(true),
            'annotations' => ['readOnlyHint' => false, 'destructiveHint' => true, 'idempotentHint' => false, 'openWorldHint' => false],
        ];
    }

    public function call(array $args, Context $ctx): array
    {
        [$tb, $payload] = RsValidate::prepare($args, $ctx, $this);

        if (!ValidationToken::valid($args['validation_token'] ?? null, 'rs', ['table' => $tb] + $payload, $ctx)) {
            throw new ToolError(
                'validation_token_invalid',
                'Call rs_validate with exactly this table, add and remove, then use the token it returns (valid 15 minutes).'
            );
        }
        $this->budgetRows(count($payload['add']) + count($payload['remove']));

        $rs      = new Rs($ctx->db, $ctx->cfg, $ctx->access);
        $added   = [];
        $removed = 0;

        $ctx->db->beginTransaction();
        try {
            foreach ($payload['remove'] as $i => $id) {
                $rel = $rs->find($id);
                $res = $rel !== null && $rel['tb'] === $tb ? $rs->remove($id) : ['status' => 'error', 'code' => 'not_found'];
                if ($res['status'] !== 'success') {
                    $ctx->db->rollBack();
                    throw $this->refused('remove', $i, $res['code']);
                }
                $removed++;
            }
            foreach ($payload['add'] as $i => $rel) {
                $res = $rs->add($tb, $rel['first'], $rel['relation'], $rel['second']);
                if ($res['status'] !== 'success') {
                    $ctx->db->rollBack();
                    throw $this->refused('add', $i, $res['code']);
                }
                $added[] = ['index' => $i, 'id' => $res['id']];
            }
            $ctx->db->commit();
        } catch (ToolError $e) {
            throw $e;
        } catch (\Throwable $e) {
            if ($ctx->db->inTransaction()) {
                $ctx->db->rollBack();
            }
            $ctx->log?->error($e);
            throw new ToolError('write_refused', ['code' => 'error_saved', 'note' => 'Nothing was changed.']);
        }

        return ['table' => $tb, 'added' => $added, 'removed' => $removed];
    }

    private function refused(string $kind, int $index, string $code): ToolError
    {
        return new ToolError('write_refused', ['kind' => $kind, 'index' => $index, 'code' => $code, 'note' => 'Nothing was changed.']);
    }
}
