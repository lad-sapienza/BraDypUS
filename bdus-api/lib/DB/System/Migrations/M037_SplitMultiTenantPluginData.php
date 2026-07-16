<?php

/**
 * @copyright 2007-2025 Julian Bogdani
 * @license AGPL-3.0; see LICENSE
 */

namespace DB\System\Migrations;

use DB\System\Manage;
use DB\System\Migrations\Support\PluginTableSplitter;

/**
 * Repairs plugin tables that were already migrated from v4 years ago, before
 * M021_FixPluginOf's multi-tenant split existed (or before its fix for the
 * "more than one parent" case) — real v4 data can have a physical plugin
 * table genuinely shared by several parents (discriminated per row by
 * table_link) while the app's current config only attaches it to one.
 *
 * Unlike M021 (which reads v4's extra.plugin JSON — long consumed and gone
 * for apps that already ran M011/M021), this migration has no JSON to read:
 * it runs after M036_DropPluginOfColumn, so plugin_of no longer exists
 * either. The only remaining source of truth is the physical data itself:
 * for every plugin table already correctly attached to a parent (via
 * bdus_cfg_relations, populated by M035 from the historical plugin_of value),
 * a DISTINCT table_link scan reveals whether other parents' rows are still
 * sitting in the same physical table, invisible in the UI because nothing
 * ever pointed to them.
 *
 * Unlike M021's split (no parent configured yet — replace with N twins and
 * drop the original), the already-attached parent here must stay exactly
 * where it is: only the *other* tenants are peeled off into their own twin
 * tables (see PluginTableSplitter::createTwin), and their rows are removed
 * from the original.
 *
 * Idempotent by construction: once a tenant's rows are copied to its twin and
 * deleted from the original, the next DISTINCT scan no longer finds them.
 *
 * Tables with no relation configured at all are left alone — with no
 * unambiguous "correct" parent to keep in place, guessing would risk making
 * things worse; that case is M035/manual-config territory, not this
 * migration's job.
 */
class M037_SplitMultiTenantPluginData
{
    public const NAME = 'M037_split_multi_tenant_plugin_data';

    public static function run(Manage $manage): void
    {
        $db = $manage->getDb();

        if (!$manage->tableExists('bdus_cfg_tables') || !$manage->tableExists('bdus_cfg_relations')) {
            return;
        }

        $pluginRows = $db->query(
            'SELECT name FROM bdus_cfg_tables WHERE is_plugin = 1',
            [],
            'read'
        ) ?: [];

        foreach ($pluginRows as $row) {
            self::repairTable($manage, $row['name']);
        }
    }

    private static function repairTable(Manage $manage, string $pluginTb): void
    {
        $db = $manage->getDb();

        if (!$manage->tableExists($pluginTb) || !$manage->columnExists($pluginTb, 'table_link')) {
            return; // not a real plugin-shaped table — nothing to inspect
        }

        $relation = $db->query(
            "SELECT to_tb FROM bdus_cfg_relations WHERE from_tb = ? AND from_col = 'id_link'",
            [$pluginTb],
            'read'
        );
        $configuredParent = $relation[0]['to_tb'] ?? null;
        if ($configuredParent === null) {
            return; // no unambiguous parent to keep in place — not this migration's job
        }

        $extraTenants = $db->query(
            "SELECT DISTINCT table_link FROM \"{$pluginTb}\"
              WHERE table_link IS NOT NULL AND table_link != ?",
            [$configuredParent],
            'read'
        ) ?: [];

        if (empty($extraTenants)) {
            return; // single-tenant already — nothing to split
        }

        $fieldRows = $db->query(
            'SELECT name, label, type, db_type, sort, extra FROM bdus_cfg_fields
              WHERE table_name = ? ORDER BY sort ASC, id ASC',
            [$pluginTb],
            'read'
        ) ?: [];
        $dataFields = array_values(array_filter(
            $fieldRows,
            fn($f) => !in_array($f['name'], ['id', 'table_link', 'id_link'], true)
        ));

        $origCfg = $db->query('SELECT * FROM bdus_cfg_tables WHERE name = ?', [$pluginTb], 'read')[0];

        foreach ($extraTenants as $row) {
            $extraParentTb = $row['table_link'];

            if (!$manage->tableExists($extraParentTb)) {
                continue; // stale table_link value pointing at a table that no longer exists
            }

            PluginTableSplitter::createTwin($manage, $pluginTb, $extraParentTb, $dataFields, $fieldRows, $origCfg);

            $db->query(
                "DELETE FROM \"{$pluginTb}\" WHERE table_link = ?",
                [$extraParentTb],
                'boolean'
            );
        }
    }
}
