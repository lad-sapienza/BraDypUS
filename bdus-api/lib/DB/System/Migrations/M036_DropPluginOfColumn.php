<?php

/**
 * @copyright 2007-2025 Julian Bogdani
 * @license AGPL-3.0; see LICENSE
 */

namespace DB\System\Migrations;

use DB\System\Manage;

/**
 * Drops the legacy plugin_of column from bdus_cfg_tables.
 *
 * plugin_of used to be the sole way of recording which parent table a plugin
 * table belongs to. Since M035_PluginRelationsFromPluginOf it is a derived,
 * runtime-only concept (Config\LoadFromDB reads bdus_cfg_relations instead —
 * see the plugin-architecture rethink), and ToDB::upsertTable no longer
 * writes to the physical column. It is safe to drop.
 *
 * For apps created after this migration, the column never existed —
 * columnExists() guards against errors in that case (same pattern as
 * M024_DropLegacyColumns).
 */
class M036_DropPluginOfColumn
{
    public const NAME = 'M036_drop_plugin_of_column';

    public static function run(Manage $manage): void
    {
        if ($manage->tableExists('bdus_cfg_tables') && $manage->columnExists('bdus_cfg_tables', 'plugin_of')) {
            $manage->getDb()->exec('ALTER TABLE bdus_cfg_tables DROP COLUMN plugin_of');
        }
    }
}
