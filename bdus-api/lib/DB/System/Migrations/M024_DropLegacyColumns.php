<?php

/**
 * @copyright 2007-2025 Julian Bogdani
 * @license AGPL-3.0; see LICENSE
 */

namespace DB\System\Migrations;

use DB\System\Manage;

/**
 * Drops legacy v4 columns left behind by M003 and M004.
 *
 * M003 (RefactorQueriesTable) added `query` and `created_at` to bdus_queries
 * and migrated the data, but could not drop the old columns because
 * ALTER TABLE DROP COLUMN was not yet supported on all target platforms.
 * The old columns are now safe to remove: `date`, `text`, and `vals`.
 *
 * M004 (RefactorChartsTable) added `definition`, `created_at`, and `is_global`
 * to bdus_charts. The old `sqltext` and `date` columns are now safe to remove.
 *
 * For apps created after v5, the legacy columns never existed — columnExists()
 * guards against errors in that case.
 */
class M024_DropLegacyColumns
{
    public const NAME = 'M024_drop_legacy_columns';

    private const QUERIES_LEGACY = ['date', 'text', 'vals'];
    private const CHARTS_LEGACY  = ['sqltext', 'date'];

    public static function run(Manage $manage): void
    {
        $db = $manage->getDb();

        foreach (self::QUERIES_LEGACY as $col) {
            if ($manage->columnExists('bdus_queries', $col)) {
                $db->exec("ALTER TABLE bdus_queries DROP COLUMN {$col}");
            }
        }

        foreach (self::CHARTS_LEGACY as $col) {
            if ($manage->columnExists('bdus_charts', $col)) {
                $db->exec("ALTER TABLE bdus_charts DROP COLUMN {$col}");
            }
        }
    }
}
