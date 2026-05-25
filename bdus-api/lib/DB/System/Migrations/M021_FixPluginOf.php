<?php

/**
 * @copyright 2007-2025 Julian Bogdani
 * @license AGPL-3.0; see LICENSE
 */

namespace DB\System\Migrations;

use DB\System\Manage;

/**
 * Back-fills the plugin_of column for plugin tables that were migrated from
 * v4 JSON config files by M011_ConfigToDb.
 *
 * Root cause:
 *   In v4, the table plugin relationship was expressed only on the *parent*
 *   table:
 *       tables.json: { "name": "us",       "plugin": ["attivita"] }
 *       tables.json: { "name": "attivita", "is_plugin": "1" }     ← no plugin_of
 *
 *   M011 stored plugin_of from each row's own key (which was absent for plugin
 *   tables), so all plugin tables were stored with plugin_of = NULL.
 *   LoadFromDB derives the `plugin` array by scanning for plugin_of, so every
 *   parent table ends up with an empty plugin list.
 *
 *   M011 did, however, store non-standard table attributes in the `extra` JSON
 *   column, so the v4 `plugin` array is preserved there, e.g.:
 *       extra = '{"plugin":["attivita","materiali"]}'
 *
 * Fix:
 *   For every row in bdus_cfg_tables whose `extra` JSON contains a `plugin`
 *   array, set plugin_of = parent_table_name on each referenced plugin table.
 *   The `plugin` key is then removed from `extra` to avoid stale data.
 */
class M021_FixPluginOf
{
    public const NAME = 'M021_fix_plugin_of';

    public static function run(Manage $manage): void
    {
        $db = $manage->getDb();

        // Guard: bdus_cfg_tables must exist.
        $tables = $db->query(
            "SELECT name FROM sqlite_master WHERE type='table' AND name='bdus_cfg_tables'",
            [],
            'read'
        ) ?: [];
        if (empty($tables)) {
            return;
        }

        // Load all rows that have a non-null extra column.
        $rows = $db->query(
            'SELECT name, extra FROM bdus_cfg_tables WHERE extra IS NOT NULL',
            [],
            'read'
        ) ?: [];

        foreach ($rows as $row) {
            $extra = json_decode($row['extra'] ?? '', true);
            if (!is_array($extra) || empty($extra['plugin'])) {
                continue;
            }

            $parentName = $row['name'];
            $pluginList = (array) $extra['plugin'];

            foreach ($pluginList as $pluginName) {
                if (!$pluginName || !is_string($pluginName)) {
                    continue;
                }

                // Only update rows that still have plugin_of = NULL (or empty),
                // to avoid overwriting a value that was set correctly.
                $db->query(
                    'UPDATE bdus_cfg_tables
                        SET plugin_of = ?, is_plugin = 1
                      WHERE name = ?
                        AND (plugin_of IS NULL OR plugin_of = ?)',
                    [$parentName, $pluginName, ''],
                    'boolean'
                );
            }

            // Remove the now-redundant `plugin` key from extra to keep the
            // column clean. Preserve any other extra keys unchanged.
            unset($extra['plugin']);
            $newExtra = empty($extra) ? null : json_encode($extra, JSON_UNESCAPED_UNICODE);

            $db->query(
                'UPDATE bdus_cfg_tables SET extra = ? WHERE name = ?',
                [$newExtra, $parentName],
                'boolean'
            );
        }
    }
}
