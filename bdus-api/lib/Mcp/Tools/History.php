<?php

/**
 * @copyright 2007-2026 Julian Bogdani
 * @license AGPL-3.0; see LICENSE
 */

namespace Mcp\Tools;

use Mcp\Context;
use Mcp\ToolError;

/**
 * The saved versions of a record and what changed since one of them.
 * Read-only: restoring a version stays an administrator's action.
 *
 * A version holds the record as it was *before* a change (update, delete,
 * restore), with the rows of its plugin tables.
 */
class History extends Base
{
    public function name(): string
    {
        return 'history';
    }

    public function definition(): array
    {
        return [
            'title'       => 'Record history',
            'description' => 'With `table` and `id`: the versions saved for the record, newest first (each is the state '
                . 'before a change; `create` marks the creation and holds no state). Adding `version`: that state, and the fields that differ from the record as it is '
                . 'now (`then` / `now`). `now` is null for every field when the record has been deleted.',
            'inputSchema' => [
                'type'       => 'object',
                'properties' => [
                    'table'   => ['type' => 'string'],
                    'id'      => ['type' => 'integer', 'minimum' => 1],
                    'version' => ['type' => 'integer', 'minimum' => 1, 'description' => 'A version id from the list.'],
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

        // Same rule as the REST history: when the table's rows are filtered for the
        // principal, the record must still exist and be one of the readable ones.
        if (!$ctx->access->unrestricted('read', $tb) && !$ctx->access->rowAllowed('read', $tb, $id)) {
            throw new ToolError('not_enough_privilege');
        }

        if (!isset($args['version'])) {
            $rows = $ctx->db->query(
                'SELECT * FROM bdus_versions WHERE tb = ? AND rowid = ? ORDER BY id DESC',
                [$tb, $id],
                'read'
            ) ?: [];
            return [
                'table'    => $tb,
                'id'       => $id,
                'versions' => array_map(fn($r) => [
                    'version'   => (int) $r['id'],
                    'user_id'   => (int) $r['userid'] ?: null,
                    'time'      => $this->time($r['time']),
                    'operation' => $r['operation'],
                    'channel'   => $r['channel'] ?? null,
                ], $rows),
            ];
        }

        $version = $this->int($args, 'version', 0, 1, PHP_INT_MAX);
        $rows    = $ctx->db->query(
            'SELECT * FROM bdus_versions WHERE id = ? AND tb = ? AND rowid = ?',
            [$version, $tb, $id],
            'read'
        ) ?: [];
        if (!$rows) {
            throw new ToolError('version_not_found');
        }
        $ver  = $rows[0];
        $then = json_decode((string) $ver['content'], true) ?: [];
        $core = is_array($then['core'] ?? null) ? $then['core'] : [];

        $current = $ctx->db->query("SELECT * FROM {$tb} WHERE id = ?", [$id], 'read') ?: [];
        $now     = $current[0] ?? null;

        $changed = [];
        foreach ($core as $field => $value) {
            $nowValue = $now[$field] ?? null;
            if ((string) ($value ?? '') !== (string) ($nowValue ?? '')) {
                $changed[$field] = ['then' => $value, 'now' => $nowValue];
            }
        }

        $plugins = [];
        foreach ($ctx->cfg->get("tables.{$tb}.plugin") ?: [] as $plg) {
            $plugins[$plg] = [
                'then' => array_values($then['plugins'][$plg] ?? []),
                'now'  => $now === null ? [] : ($ctx->db->query("SELECT * FROM {$plg} WHERE id_link = ?", [$id], 'read') ?: []),
            ];
        }

        return [
            'table'     => $tb,
            'id'        => $id,
            'version'   => (int) $ver['id'],
            'user_id'   => (int) $ver['userid'] ?: null,
            'time'      => $this->time($ver['time']),
            'operation' => $ver['operation'],
            'channel'   => $ver['channel'] ?? null,
            'deleted'   => $now === null,
            'then'      => $core,
            'changed'   => $changed,
            'plugins'   => $plugins,
        ];
    }

    private function time(mixed $ts): ?string
    {
        return $ts ? date('Y-m-d H:i:s', (int) $ts) : null;
    }
}
