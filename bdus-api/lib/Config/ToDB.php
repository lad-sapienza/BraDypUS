<?php

/**
 * @copyright 2007-2025 Julian Bogdani
 * @license AGPL-3.0; see LICENSE
 */

declare(strict_types=1);

namespace Config;

use DB\DBInterface;

/**
 * Writes table, field, and relation configuration to the database
 * (bdus_cfg_tables, bdus_cfg_fields, bdus_cfg_relations).
 * This is the DB-backed counterpart of ToFiles.
 *
 * Each method is surgical — it only writes the row(s) that changed, unlike
 * ToFiles::all() which serialises the entire in-memory state on every call.
 */
class ToDB
{
    /**
     * Table attributes stored as explicit columns; everything else → extra JSON.
     * 'link' is listed here so it is NOT stored in extra — it is handled
     * separately via upsertRelations().
     */
    private const TABLE_COLUMNS = [
        'name', 'label', 'order', 'id_field', 'preview',
        'is_plugin', 'plugin_of', 'sort', 'link', 'backlink', 'fields',
    ];

    /** Field attributes stored as explicit columns; everything else → extra JSON. */
    private const FIELD_COLUMNS = ['name', 'label', 'type', 'db_type', 'sort'];

    // ── Table operations ─────────────────────────────────────────────────────

    /**
     * Inserts or updates a table row in bdus_cfg_tables, then replaces all
     * forward-link relations for this table in bdus_cfg_relations.
     *
     * The legacy `links` column in bdus_cfg_tables is always set to NULL here —
     * link data is authoritative in bdus_cfg_relations after M013.
     */
    public static function upsertTable(DBInterface $db, array $tbData): void
    {
        $name = $tbData['name'] ?? null;
        if (!$name) throw new ConfigException('Cannot upsert table with no name');

        // Determine sort: use existing value if already in DB, else MAX+1.
        $existing = $db->query(
            'SELECT id, sort FROM bdus_cfg_tables WHERE name = ?',
            [$name],
            'read'
        );

        $preview   = isset($tbData['preview'])
            ? json_encode($tbData['preview'],  JSON_UNESCAPED_UNICODE)
            : null;
        $backlinks = isset($tbData['backlink'])
            ? json_encode($tbData['backlink'], JSON_UNESCAPED_UNICODE)
            : null;

        // Collect all non-standard attributes into the extra JSON column.
        $extraArr = [];
        foreach ($tbData as $k => $v) {
            if (!in_array($k, self::TABLE_COLUMNS, true)) {
                $extraArr[$k] = $v;
            }
        }
        $extra = empty($extraArr) ? null : json_encode($extraArr, JSON_UNESCAPED_UNICODE);

        if ($existing) {
            $db->query(
                'UPDATE bdus_cfg_tables
                    SET label=?, order_field=?, id_field=?, preview=?,
                        is_plugin=?, plugin_of=?, links=NULL, backlinks=?, extra=?
                  WHERE name=?',
                [
                    $tbData['label']     ?? null,
                    $tbData['order']     ?? null,
                    $tbData['id_field']  ?? 'id',
                    $preview,
                    isset($tbData['is_plugin']) ? (int)$tbData['is_plugin'] : 0,
                    $tbData['plugin_of'] ?? null,
                    $backlinks,
                    $extra,
                    $name,
                ],
                'boolean'
            );
        } else {
            $maxSort = $db->query(
                'SELECT COALESCE(MAX(sort), -1) AS mx FROM bdus_cfg_tables',
                [],
                'read'
            );
            $sort = ((int)($maxSort[0]['mx'] ?? -1)) + 1;

            $db->query(
                'INSERT INTO bdus_cfg_tables
                    (name, label, order_field, id_field, preview,
                     is_plugin, plugin_of, sort, links, backlinks, extra)
                 VALUES (?,?,?,?,?,?,?,?,NULL,?,?)',
                [
                    $name,
                    $tbData['label']     ?? null,
                    $tbData['order']     ?? null,
                    $tbData['id_field']  ?? 'id',
                    $preview,
                    isset($tbData['is_plugin']) ? (int)$tbData['is_plugin'] : 0,
                    $tbData['plugin_of'] ?? null,
                    $sort,
                    $backlinks,
                    $extra,
                ],
                'boolean'
            );
        }

        // Persist forward-link relations only when 'link' is explicitly provided.
        // After the dedicated Relations panel (v5), link editing goes through
        // saveRelation/deleteRelation and the table-form payload no longer includes
        // the 'link' key — so we must not wipe existing relations on every table save.
        if (array_key_exists('link', $tbData)) {
            self::upsertRelations($db, $name, $tbData['link']);
        }
    }

    /**
     * Replaces all forward-link relations for $fromTb.
     *
     * Deletes existing rows for $fromTb then inserts the new set.
     * Pass an empty array to clear all links for a table.
     *
     * @param string $fromTb  Source table name.
     * @param array  $links   Array of link definitions:
     *                        [ ['other_tb' => 'periodi', 'fld' => [...]], … ]
     */
    public static function upsertRelations(DBInterface $db, string $fromTb, array $links): void
    {
        // Check whether bdus_cfg_relations exists (cross-engine: direct count).
        try {
            $db->query('SELECT COUNT(*) AS cnt FROM bdus_cfg_relations WHERE 1=0', [], 'read');
        } catch (\Throwable $e) {
            return; // Table not yet created — no-op.
        }

        // Delete existing relations for this table — both forward (from_tb = X)
        // and reverse (to_tb = X). After M020 each pair is stored once; when the
        // user re-saves a table's link config we must also clear any canonical row
        // that was originally stored from the OTHER side of the relationship.
        $db->query('DELETE FROM bdus_cfg_relations WHERE from_tb=?', [$fromTb], 'boolean');
        $db->query('DELETE FROM bdus_cfg_relations WHERE to_tb=?',   [$fromTb], 'boolean');

        // Insert new relations.
        $sort = 0;
        foreach ($links as $link) {
            $toTb = $link['other_tb'] ?? null;
            if (!$toTb) continue;

            $fld = isset($link['fld'])
                ? json_encode($link['fld'], JSON_UNESCAPED_UNICODE)
                : null;

            $db->query(
                'INSERT INTO bdus_cfg_relations (from_tb, to_tb, fld, sort) VALUES (?,?,?,?)',
                [$fromTb, $toTb, $fld, $sort++],
                'boolean'
            );
        }
    }

    /**
     * Renames a table row and updates all its field, template, and relation rows.
     */
    public static function renameTable(DBInterface $db, string $oldName, string $newName): void
    {
        $db->query(
            'UPDATE bdus_cfg_tables SET name=? WHERE name=?',
            [$newName, $oldName],
            'boolean'
        );
        $db->query(
            'UPDATE bdus_cfg_fields SET table_name=? WHERE table_name=?',
            [$newName, $oldName],
            'boolean'
        );
        $db->query(
            'UPDATE bdus_cfg_templates SET table_name=? WHERE table_name=?',
            [$newName, $oldName],
            'boolean'
        );
        // Update relations where this table is the source.
        $db->query(
            'UPDATE bdus_cfg_relations SET from_tb=? WHERE from_tb=?',
            [$newName, $oldName],
            'boolean'
        );
        // Update relations where this table is the target.
        $db->query(
            'UPDATE bdus_cfg_relations SET to_tb=? WHERE to_tb=?',
            [$newName, $oldName],
            'boolean'
        );
    }

    /**
     * Deletes a table row and all its associated rows (fields, templates, relations).
     */
    public static function deleteTable(DBInterface $db, string $name): void
    {
        $db->query('DELETE FROM bdus_cfg_fields    WHERE table_name=?', [$name], 'boolean');
        $db->query('DELETE FROM bdus_cfg_templates WHERE table_name=?', [$name], 'boolean');
        $db->query('DELETE FROM bdus_cfg_relations WHERE from_tb=?',    [$name], 'boolean');
        $db->query('DELETE FROM bdus_cfg_tables    WHERE name=?',       [$name], 'boolean');
    }

    /**
     * Sets the sort order of all tables at once.
     * $sortedNames is an ordered array of table names (first = sort 0).
     */
    public static function sortTables(DBInterface $db, array $sortedNames): void
    {
        foreach ($sortedNames as $i => $name) {
            $db->query(
                'UPDATE bdus_cfg_tables SET sort=? WHERE name=?',
                [$i, $name],
                'boolean'
            );
        }
    }

    // ── Field operations ─────────────────────────────────────────────────────

    /**
     * Inserts or updates a single field row.
     */
    public static function upsertField(DBInterface $db, string $tableName, array $fldData): void
    {
        $name = $fldData['name'] ?? null;
        if (!$name) throw new ConfigException('Cannot upsert field with no name');

        [$cols, $extra] = self::splitField($fldData);

        $existing = $db->query(
            'SELECT id FROM bdus_cfg_fields WHERE table_name=? AND name=?',
            [$tableName, $name],
            'read'
        );

        if ($existing) {
            $db->query(
                'UPDATE bdus_cfg_fields
                    SET label=?, type=?, db_type=?, sort=?, extra=?
                  WHERE table_name=? AND name=?',
                [
                    $cols['label'],
                    $cols['type'],
                    $cols['db_type'],
                    $cols['sort'],
                    $extra,
                    $tableName,
                    $name,
                ],
                'boolean'
            );
        } else {
            // Assign sort = MAX + 1 for this table.
            $maxSort = $db->query(
                'SELECT COALESCE(MAX(sort), -1) AS mx FROM bdus_cfg_fields WHERE table_name=?',
                [$tableName],
                'read'
            );
            $sort = isset($fldData['sort'])
                ? (int)$fldData['sort']
                : ((int)($maxSort[0]['mx'] ?? -1)) + 1;

            $db->query(
                'INSERT INTO bdus_cfg_fields
                    (table_name, name, label, type, db_type, sort, extra)
                 VALUES (?,?,?,?,?,?,?)',
                [
                    $tableName,
                    $name,
                    $cols['label'],
                    $cols['type'],
                    $cols['db_type'],
                    $sort,
                    $extra,
                ],
                'boolean'
            );
        }
    }

    /**
     * Renames a field (updates the name column and the name key in extra if present).
     */
    public static function renameField(DBInterface $db, string $tableName, string $oldName, string $newName): void
    {
        $db->query(
            'UPDATE bdus_cfg_fields SET name=? WHERE table_name=? AND name=?',
            [$newName, $tableName, $oldName],
            'boolean'
        );
    }

    /**
     * Deletes a field row.
     */
    public static function deleteField(DBInterface $db, string $tableName, string $fieldName): void
    {
        $db->query(
            'DELETE FROM bdus_cfg_fields WHERE table_name=? AND name=?',
            [$tableName, $fieldName],
            'boolean'
        );
    }

    // ── Private helpers ───────────────────────────────────────────────────────

    /**
     * Splits a field array into explicit column values + extra JSON string.
     * Returns [columns_array, extra_json_or_null].
     */
    private static function splitField(array $fldData): array
    {
        $cols  = [
            'label'   => $fldData['label']   ?? null,
            'type'    => $fldData['type']     ?? 'text',
            'db_type' => $fldData['db_type']  ?? null,
            'sort'    => isset($fldData['sort']) ? (int)$fldData['sort'] : null,
        ];

        $extra = [];
        foreach ($fldData as $k => $v) {
            if (!in_array($k, self::FIELD_COLUMNS, true)) {
                $extra[$k] = $v;
            }
        }

        return [$cols, empty($extra) ? null : json_encode($extra, JSON_UNESCAPED_UNICODE)];
    }
}
