<?php

/**
 * @copyright 2007-2026 Julian Bogdani
 * @license AGPL-3.0; see LICENSE
 */

namespace Mcp\Tools;

use Mcp\Context;
use Mcp\ToolError;
use Record\ManualLinks;

/**
 * The `links` section of a record in records_validate / records_write: manual
 * links to records of any table (bdus_userlinks) to add, and links to remove.
 * It is parsed and judged here and written by Record\ManualLinks, the class the
 * REST endpoints use, inside the transaction of the record.
 *
 * A link shows on both records it joins, so the key must be allowed to change
 * both; the section only ever touches links of the record it belongs to.
 */
class Links
{
    /** Most links added or removed for one record in one call. */
    public const MAX_ITEMS = 20;

    /** The input schema of the section. */
    public static function schema(): array
    {
        return [
            'type'        => 'object',
            'description' => 'Manual links of the record to other records, of any table; a link shows on both records. '
                . '"add": { "table", "id" of the other record, "label"?, "sort"? }; "remove": link ids as returned by records_read with links. '
                . 'You must be allowed to change both records. Written in the same transaction as the record.',
            'properties' => [
                'add'    => ['type' => 'array', 'items' => ['type' => 'object']],
                'remove' => ['type' => 'array', 'items' => ['type' => 'integer', 'minimum' => 1]],
            ],
            'additionalProperties' => false,
        ];
    }

    /**
     * The section of record $index, normalised: { add: [...], remove: int[] }.
     * Empty when absent. Shape errors are refused here, before anything is judged.
     */
    public static function parse(mixed $raw, int $index): array
    {
        $out = ['add' => [], 'remove' => []];
        if ($raw === null || $raw === [] || $raw === '') {
            return $out;
        }
        $at = "records[{$index}].links";
        if (!is_array($raw) || array_is_list($raw) || array_diff(array_keys($raw), array_keys($out))) {
            throw new ToolError('invalid_parameters', $at);
        }
        $posInt = fn($v, string $where) => (is_int($v) || (is_string($v) && ctype_digit($v))) && (int) $v >= 1
            ? (int) $v : throw new ToolError('invalid_parameters', "{$at}.{$where}");

        foreach (['add', 'remove'] as $kind) {
            $items = $raw[$kind] ?? [];
            if (!is_array($items) || !array_is_list($items)) {
                throw new ToolError('invalid_parameters', "{$at}.{$kind}");
            }
            foreach ($items as $i => $item) {
                $w = "{$kind}[{$i}]";
                if ($kind === 'remove') {
                    $out['remove'][] = $posInt($item, $w);
                    continue;
                }
                if (!is_array($item) || array_diff(array_keys($item), ['table', 'id', 'label', 'sort'])) {
                    throw new ToolError('invalid_parameters', "{$at}.{$w}");
                }
                if (!is_string($item['table'] ?? null) || $item['table'] === '' || !isset($item['id'])) {
                    throw new ToolError('parameter_missing', "{$at}.{$w}");
                }
                $row = ['table' => $item['table'], 'id' => $posInt($item['id'], "{$w}.id")];
                if (array_key_exists('label', $item) && $item['label'] !== null) {
                    if (!is_scalar($item['label']) || is_bool($item['label'])) {
                        throw new ToolError('invalid_parameters', "{$at}.{$w}.label");
                    }
                    $row['label'] = (string) $item['label'] === '' ? null : (string) $item['label'];
                }
                if (array_key_exists('sort', $item) && $item['sort'] !== null) {
                    $row['sort'] = is_int($item['sort']) || (is_string($item['sort']) && preg_match('/^-?\d+$/', $item['sort']))
                        ? (int) $item['sort'] : throw new ToolError('invalid_parameters', "{$at}.{$w}.sort");
                }
                $out['add'][] = $row;
            }
        }
        if (count($out['add']) + count($out['remove']) > self::MAX_ITEMS) {
            throw new ToolError('invalid_parameters', "{$at} (at most " . self::MAX_ITEMS . ' items)');
        }
        return $out;
    }

    public static function isEmpty(array $links): bool
    {
        return !$links['add'] && !$links['remove'];
    }

    public static function count(array $links): int
    {
        return count($links['add']) + count($links['remove']);
    }

    /**
     * Would the section be accepted for record $id of $tb (null: a record being created)?
     * Judges what Record\ManualLinks will do, without writing.
     *
     * @return array|null null when fine, else { code, detail? }
     */
    public static function check(string $tb, ?int $id, array $links, Context $ctx): ?array
    {
        if (self::isEmpty($links)) {
            return null;
        }
        // Same right as the REST routes: changing the record (a new record: its creator may).
        if ($id === null && !\Auth\Authorization::can('edit', \Auth\CurrentUser::id() ?: null, $tb)) {
            return ['code' => 'not_enough_privilege'];
        }

        $manual   = new ManualLinks($ctx->db, $ctx->cfg, $ctx->access);
        $gate     = new \Auth\WriteGate($ctx->db, $ctx->cfg, $ctx->access);
        $existing = $id === null ? [] : ($manual->forRecords($tb, [$id])[$id] ?? []);
        $byKey    = array_column($existing, null, 'key');

        $removed = [];
        foreach ($links['remove'] as $key) {
            if (!isset($byKey[$key])) {
                return ['code' => 'link_not_found', 'detail' => $key];
            }
            // The other end must be changeable too.
            if (!$gate->mayChange('update', $byKey[$key]['tb_id'], $byKey[$key]['ref_id'])) {
                return ['code' => 'not_enough_privilege', 'detail' => $key];
            }
            $removed[$byKey[$key]['tb_id'] . ':' . $byKey[$key]['ref_id']] = true;
        }

        $taken = [];
        foreach ($existing as $l) {
            $k = $l['tb_id'] . ':' . $l['ref_id'];
            if (!isset($removed[$k])) {
                $taken[$k] = true;
            }
        }
        $reader = new RecordsRead();
        foreach ($links['add'] as $a) {
            try {
                $toTb = $reader->tableOf(['table' => $a['table']], $ctx);
            } catch (ToolError) {
                return ['code' => 'unknown_table', 'detail' => $a['table']];
            }
            if ($ctx->cfg->get("tables.{$toTb}.is_view") && ($ctx->cfg->get("tables.{$toTb}.view_writable") ?: 'none') === 'none') {
                return ['code' => 'view_read_only', 'detail' => $toTb];
            }
            if ($id !== null && $toTb === $tb && $a['id'] === $id) {
                return ['code' => 'link_with_itself'];
            }
            // The other record is changed too. After the gate: whether a record exists is not told to someone who may not change it.
            if (!$gate->mayChange('update', $toTb, $a['id'])) {
                return ['code' => 'not_enough_privilege', 'detail' => "{$toTb}#{$a['id']}"];
            }
            if (!$ctx->db->query("SELECT 1 FROM {$toTb} WHERE id = ?", [$a['id']], 'read')) {
                return ['code' => 'record_not_found', 'detail' => "{$toTb}#{$a['id']}"];
            }
            $k = $toTb . ':' . $a['id'];
            // `taken` holds the links of the record seen from its side, so a pair stored the other way round counts too.
            if (isset($taken[$k])) {
                return ['code' => 'link_already_exists', 'detail' => $k];
            }
            $taken[$k] = true;
        }
        return null;
    }

    /**
     * Writes the section for record $id. Call it inside the record's transaction,
     * after check() and after the record itself is saved.
     *
     * @return array|null null when written, else { code, detail? } (the caller rolls back)
     */
    public static function apply(string $tb, int $id, array $links, Context $ctx): ?array
    {
        if (self::isEmpty($links)) {
            return null;
        }
        // The record exists now: the one gate every write to a record goes through.
        if (!(new \Auth\WriteGate($ctx->db, $ctx->cfg, $ctx->access))->mayChange('update', $tb, $id)) {
            return ['code' => 'not_enough_privilege'];
        }
        if ($refused = self::check($tb, $id, $links, $ctx)) {
            return $refused;
        }
        $manual = new ManualLinks($ctx->db, $ctx->cfg, $ctx->access);
        foreach ($links['remove'] as $key) {
            $res = $manual->remove($key);
            if ($res['status'] !== 'success') {
                return ['code' => $res['code'], 'detail' => $key];
            }
        }
        foreach ($links['add'] as $a) {
            $res = $manual->add($tb, $id, $a['table'], $a['id'], $a['label'] ?? null, $a['sort'] ?? null);
            if ($res['status'] !== 'success') {
                return ['code' => $res['code'], 'detail' => "{$a['table']}#{$a['id']}"];
            }
        }
        return null;
    }
}
