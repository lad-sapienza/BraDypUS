<?php

/**
 * @copyright 2007-2025 Julian Bogdani
 * @license AGPL-3.0; see LICENSE
 */

namespace DB\System\Migrations;

use DB\System\Manage;

/**
 * Drops the legacy table_link column from every generic plugin table.
 *
 * table_link used to be the discriminator that let a physical plugin table
 * be shared by several parents (one row per tenant, distinguished by the
 * value stored there). Since M037_SplitMultiTenantPluginData every plugin
 * table has exactly one physical parent (real multi-tenant cases were split
 * into twin tables), so the column is a constant, redundant value on every
 * row — the actual attachment already lives in bdus_cfg_relations. All CRUD
 * and search code paths (Record\Read/Persist/Edit, JsonFilter,
 * Alter::createMinimalTable) were updated to rely on id_link alone; this
 * migration removes the now-dead physical column and its config-level field
 * row (bdus_cfg_fields).
 *
 * bdus_geodata is the single explicit exception: it remains a genuinely
 * multi-tenant system table (shared by every geo-enabled table in the app)
 * and is untouched here — it is not part of the is_plugin/plugin_of
 * mechanism this migration cleans up.
 *
 * For plugin tables created after this migration, the column never existed —
 * columnExists()/tableExists() guard against errors in that case.
 */
class M038_DropTableLinkFromPlugins
{
    public const NAME = 'M038_drop_table_link_from_plugins';

    public static function run(Manage $manage): void
    {
        if (!$manage->tableExists('bdus_cfg_tables')) {
            return;
        }

        $db = $manage->getDb();

        $pluginRows = $db->query(
            "SELECT name FROM bdus_cfg_tables WHERE is_plugin = 1 AND name != 'bdus_geodata'",
            [],
            'read'
        ) ?: [];

        foreach ($pluginRows as $row) {
            $tb = $row['name'];

            if ($manage->tableExists($tb) && $manage->columnExists($tb, 'table_link')) {
                $db->exec("ALTER TABLE \"{$tb}\" DROP COLUMN table_link");
            }

            if ($manage->tableExists('bdus_cfg_fields')) {
                $db->query(
                    "DELETE FROM bdus_cfg_fields WHERE table_name = ? AND name = 'table_link'",
                    [$tb],
                    'boolean'
                );
            }
        }
    }
}
