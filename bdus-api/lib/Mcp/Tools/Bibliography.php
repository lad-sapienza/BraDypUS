<?php

/**
 * @copyright 2007-2026 Julian Bogdani
 * @license AGPL-3.0; see LICENSE
 */

namespace Mcp\Tools;

use Mcp\Context;
use Mcp\ToolError;
use Zotero\Links;

/**
 * The `bibliography` section of a record in records_validate / records_write:
 * links to Zotero items to add, links to change, links to remove. It is parsed
 * and judged here and written by Zotero\Links, the class the REST controller
 * uses, inside the transaction of the record.
 */
class Bibliography
{
    /** Most links added, changed or removed for one record in one call. */
    public const MAX_ITEMS = 20;

    /** The input schema of the section. */
    public static function schema(): array
    {
        return [
            'type'        => 'object',
            'description' => 'Zotero links of the record (only tables with bibliography). Find the item with `bibliography_search`. '
                . '"add": links to create, { "lib_id", "zotero_key", "pages"?, "notes"?, "sort"? }; "edit": links to change, '
                . '{ "id": link id as returned by records_read with bibliography, "pages"?, "notes"?, "sort"? }; '
                . '"remove": link ids to delete. Written in the same transaction as the record.',
            'properties' => [
                'add'    => ['type' => 'array', 'items' => ['type' => 'object']],
                'edit'   => ['type' => 'array', 'items' => ['type' => 'object']],
                'remove' => ['type' => 'array', 'items' => ['type' => 'integer', 'minimum' => 1]],
            ],
            'additionalProperties' => false,
        ];
    }

    /**
     * The section of record $index, normalised: { add: [...], edit: [...], remove: int[] }.
     * Empty when absent. Shape errors are refused here, before anything is judged.
     */
    public static function parse(mixed $raw, int $index): array
    {
        $out = ['add' => [], 'edit' => [], 'remove' => []];
        if ($raw === null || $raw === [] || $raw === '') {
            return $out;
        }
        $at = "records[{$index}].bibliography";
        if (!is_array($raw) || array_is_list($raw) || array_diff(array_keys($raw), array_keys($out))) {
            throw new ToolError('invalid_parameters', $at);
        }
        $text = fn($v) => $v === null ? null : (is_scalar($v) && !is_bool($v) ? (string) $v : throw new ToolError('invalid_parameters', $at));
        $posInt = fn($v, string $where) => (is_int($v) || (is_string($v) && ctype_digit($v))) && (int) $v >= 1
            ? (int) $v : throw new ToolError('invalid_parameters', "{$at}.{$where}");

        foreach (['add', 'edit', 'remove'] as $kind) {
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
                $allowed = $kind === 'add' ? ['lib_id', 'zotero_key', 'pages', 'notes', 'sort'] : ['id', 'pages', 'notes', 'sort'];
                if (!is_array($item) || array_diff(array_keys($item), $allowed)) {
                    throw new ToolError('invalid_parameters', "{$at}.{$w}");
                }
                $row = [];
                if ($kind === 'add') {
                    $key = trim((string) $text($item['zotero_key'] ?? null));
                    if ($key === '' || !isset($item['lib_id'])) {
                        throw new ToolError('parameter_missing', "{$at}.{$w}");
                    }
                    $row = ['lib_id' => $posInt($item['lib_id'], "{$w}.lib_id"), 'zotero_key' => $key];
                } else {
                    $row = ['id' => $posInt($item['id'] ?? null, "{$w}.id")];
                }
                foreach (['pages', 'notes'] as $f) {
                    if (array_key_exists($f, $item)) {
                        $row[$f] = $text($item[$f]);
                    }
                }
                if (array_key_exists('sort', $item)) {
                    $row['sort'] = $item['sort'] === null ? null : (is_int($item['sort']) || (is_string($item['sort']) && preg_match('/^-?\d+$/', $item['sort']))
                        ? (int) $item['sort'] : throw new ToolError('invalid_parameters', "{$at}.{$w}.sort"));
                }
                if ($kind === 'edit' && count($row) === 1) {
                    throw new ToolError('parameter_missing', "{$at}.{$w}");
                }
                $out[$kind][] = $row;
            }
        }
        if (count($out['add']) + count($out['edit']) + count($out['remove']) > self::MAX_ITEMS) {
            throw new ToolError('invalid_parameters', "{$at} (at most " . self::MAX_ITEMS . ' items)');
        }
        return $out;
    }

    public static function isEmpty(array $bib): bool
    {
        return !$bib['add'] && !$bib['edit'] && !$bib['remove'];
    }

    public static function count(array $bib): int
    {
        return count($bib['add']) + count($bib['edit']) + count($bib['remove']);
    }

    /**
     * Would the section be accepted for record $id (null: a record being created)?
     * Judges what Zotero\Links will do, without writing and without asking Zotero.
     *
     * @return array|null null when fine, else { code, detail? } (codes as the REST API: zotero_not_configured, lib_not_found, link_not_found, …)
     */
    public static function check(string $tb, ?int $id, array $bib, Context $ctx): ?array
    {
        if (self::isEmpty($bib)) {
            return null;
        }
        if (!$ctx->cfg->get("tables.{$tb}.zotero")) {
            return ['code' => 'zotero_not_configured', 'detail' => $tb];
        }
        // Same right as the REST routes: changing the record (a new record: its creator may).
        if ($id === null) {
            if (!\Auth\Authorization::can('edit', \Auth\CurrentUser::id() ?: null, $tb)) {
                return ['code' => 'not_enough_privilege'];
            }
        }

        $links    = new Links($ctx->db, $ctx->log);
        $existing = $id === null ? [] : $links->forRecord($tb, $id);
        $byId     = array_column($existing, null, 'id');

        foreach (array_merge(array_column($bib['edit'], 'id'), $bib['remove']) as $linkId) {
            if (!isset($byId[$linkId])) {
                return ['code' => 'link_not_found', 'detail' => $linkId];
            }
        }
        if (array_intersect(array_column($bib['edit'], 'id'), $bib['remove'])) {
            return ['code' => 'invalid_parameters', 'detail' => 'a link is both edited and removed'];
        }

        $taken = [];
        foreach ($existing as $l) {
            if (!in_array((int) $l['id'], $bib['remove'], true)) {
                $taken[$l['lib_id'] . ':' . $l['zotero_key']] = true;
            }
        }
        foreach ($bib['add'] as $a) {
            if (!$links->getLib($a['lib_id'])) {
                return ['code' => 'lib_not_found', 'detail' => $a['lib_id']];
            }
            $k = $a['lib_id'] . ':' . $a['zotero_key'];
            if (isset($taken[$k])) {
                return ['code' => 'link_already_exists', 'detail' => $a['zotero_key']];
            }
            $taken[$k] = true;
        }
        return null;
    }

    /**
     * Citation caches of the links to add, fetched from Zotero. Done before the
     * transaction opens, so that the network is not waited for while it is.
     *
     * @return array<int, array>  index in $bib['add'] => cache fields
     */
    public static function prefetch(array $bib, Context $ctx): array
    {
        $links = new Links($ctx->db, $ctx->log);
        $out   = [];
        foreach ($bib['add'] as $i => $a) {
            $lib = $links->getLib($a['lib_id']);
            if ($lib) {
                $out[$i] = $links->fetchCacheData($lib, $a['zotero_key']);
            }
        }
        return $out;
    }

    /**
     * Writes the section for record $id. Call it inside the record's transaction,
     * after check() and after the record itself is saved.
     *
     * @param array<int, array> $caches from prefetch()
     * @return array|null null when written, else { code, detail? } (the caller rolls back)
     */
    public static function apply(string $tb, int $id, array $bib, array $caches, Context $ctx): ?array
    {
        if (self::isEmpty($bib)) {
            return null;
        }
        // The record exists now: the one gate every write to a record goes through.
        if (!(new \Auth\WriteGate($ctx->db, $ctx->cfg, $ctx->access))->mayChange('update', $tb, $id)) {
            return ['code' => 'not_enough_privilege'];
        }
        if ($refused = self::check($tb, $id, $bib, $ctx)) {
            return $refused;
        }
        $links = new Links($ctx->db, $ctx->log);
        foreach ($bib['remove'] as $linkId) {
            $links->delete($linkId);
        }
        foreach ($bib['edit'] as $e) {
            $links->edit($e['id'], array_diff_key($e, ['id' => 1]));
        }
        foreach ($bib['add'] as $i => $a) {
            $new = $links->add($tb, $id, $a['lib_id'], $a['zotero_key'], $a['pages'] ?? null, $a['notes'] ?? null, $a['sort'] ?? null, $caches[$i] ?? null);
            if ($new === null) {
                return ['code' => 'lib_not_found', 'detail' => $a['lib_id']];
            }
        }
        return null;
    }
}
