<?php

/**
 * @copyright 2007-2025 Julian Bogdani
 * @license AGPL-3.0; see LICENSE
 */

namespace DB\System\Migrations\Support;

use DB\Alter;
use DB\DBInterface;
use DB\System\Manage;

/**
 * Shared logic for splitting a shared plugin table into a physical twin
 * scoped to a single parent — one physical table = one parent, per the
 * plugin-architecture rethink (see project_plugin_architecture_rethink.md).
 *
 * Used by:
 *  - M021_FixPluginOf: v4 import, no parent configured yet — creates one twin
 *    per declared parent, then drops the original shared table entirely.
 *  - M037_SplitMultiTenantPluginData: an already-migrated app where one parent
 *    is already correctly attached — only the *other* tenants are peeled off
 *    into twins; the original table and its existing attachment are left in
 *    place. The caller removes the copied rows from the original afterward.
 */
final class PluginTableSplitter
{
    /**
     * Creates {pluginTb}_{parentTb} (or _2/_3… on name clash), copies only the
     * rows whose table_link = $parentTb (original ids preserved), copies the
     * field config verbatim, and registers the twin as its own plugin table
     * attached to $parentTb (bdus_cfg_tables row + bdus_cfg_relations row).
     * Does not touch $pluginTb itself — the caller decides what to do with the
     * now-redundant source rows.
     *
     * @param array $dataFields Field rows from bdus_cfg_fields for $pluginTb,
     *                          excluding id/table_link/id_link.
     * @param array $fieldRows  All field rows for $pluginTb, verbatim (incl.
     *                          id/table_link/id_link).
     * @param array $origCfg    The bdus_cfg_tables row for $pluginTb.
     * @return string The twin table's name.
     */
    public static function createTwin(
        Manage $manage,
        string $pluginTb,
        string $parentTb,
        array $dataFields,
        array $fieldRows,
        array $origCfg
    ): string {
        $db    = $manage->getDb();
        $alter = new Alter($db);

        $twinTb = self::availableTableName($db, $pluginTb . '_' . $parentTb);

        // 1. Physical table: standard plugin shape (id/table_link/id_link),
        //    WITHOUT the FK yet — real data can contain id_link values that no
        //    longer exist in the parent (e.g. the parent row was deleted
        //    without cascading its plugin rows); adding the FK inline here
        //    would make the INSERT below throw and abort the whole migration.
        //    The FK is added afterward, only if the copied data is orphan-free.
        $alter->createMinimalTable($twinTb, true, '');
        foreach ($dataFields as $f) {
            $alter->addFld($twinTb, $f['name'], $f['db_type'] ?: 'TEXT');
        }

        // 2. Data: only the rows that belong to this parent, ids preserved.
        $allCols = array_merge(['id', 'table_link', 'id_link'], array_column($dataFields, 'name'));
        $colList = implode(', ', array_map(fn($c) => "\"{$c}\"", $allCols));
        $db->query(
            "INSERT INTO \"{$twinTb}\" ({$colList})
             SELECT {$colList} FROM \"{$pluginTb}\" WHERE table_link = ?",
            [$parentTb],
            'boolean'
        );

        // 2b. Apply the live FK now, but only if every copied row's id_link
        //     actually resolves in the parent — same orphan-safe pattern
        //     Config::saveRelation() uses for user-triggered relations.
        if ($alter->checkOrphans($twinTb, 'id_link', $parentTb, 'id') === 0) {
            $alter->addForeignKey($twinTb, 'id_link', $parentTb, 'id', 'RESTRICT', 'CASCADE');
        }

        // 3. Config: table row, mirroring the original's label/layout/extra.
        $maxSort = $db->query('SELECT COALESCE(MAX(sort), -1) AS mx FROM bdus_cfg_tables', [], 'read');
        $sort    = ((int) ($maxSort[0]['mx'] ?? -1)) + 1;
        $db->query(
            'INSERT INTO bdus_cfg_tables
                (name, label, order_field, id_field, preview, is_plugin, sort, extra)
             VALUES (?, ?, ?, ?, ?, 1, ?, ?)',
            [
                $twinTb,
                $origCfg['label'],
                $origCfg['order_field'],
                $origCfg['id_field'],
                $origCfg['preview'],
                $sort,
                $origCfg['extra'],
            ],
            'boolean'
        );

        // 4. Config: relation to the parent — what Config\LoadFromDB reads to
        //    derive tables.{parent}.plugin[].
        $db->query(
            'INSERT INTO bdus_cfg_relations (from_tb, from_col, to_tb, to_col, on_delete, on_update)
             VALUES (?, ?, ?, ?, ?, ?)',
            [$twinTb, 'id_link', $parentTb, 'id', 'RESTRICT', 'CASCADE'],
            'boolean'
        );

        // 5. Config: field rows, copied verbatim (id/table_link/id_link included).
        foreach ($fieldRows as $f) {
            $db->query(
                'INSERT INTO bdus_cfg_fields (table_name, name, label, type, db_type, sort, extra)
                 VALUES (?, ?, ?, ?, ?, ?, ?)',
                [$twinTb, $f['name'], $f['label'], $f['type'], $f['db_type'], $f['sort'], $f['extra']],
                'boolean'
            );
        }

        return $twinTb;
    }

    /** Returns $base, or $base_2 / $base_3 / … if $base is already taken. */
    public static function availableTableName(DBInterface $db, string $base): string
    {
        $name = $base;
        $i    = 2;
        while (!empty($db->query('SELECT id FROM bdus_cfg_tables WHERE name = ?', [$name], 'read'))) {
            $name = $base . '_' . $i;
            $i++;
        }
        return $name;
    }
}
