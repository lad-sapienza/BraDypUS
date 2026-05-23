<?php

/**
 * @copyright 2007-2025 Julian Bogdani
 * @license AGPL-3.0; see LICENSE
 */

namespace DB\System\Migrations;

use DB\System\Manage;

/**
 * Removes the JSON config files that were superseded by M011/M013/M014.
 *
 * Files deleted:
 *   – cfg/tables.json             (table/field list → bdus_cfg_tables/bdus_cfg_fields)
 *   – cfg/{table}.json            (per-table field list → bdus_cfg_fields)
 *   – template/*.json             (record templates → bdus_cfg_templates)
 *
 * Files intentionally preserved:
 *   – cfg/app_data.json           (DB credentials — still needed at bootstrap)
 *   – geodata/index.json          (legacy fallback for pre-M014 tools; already superseded
 *                                  by bdus_cfg_geoface but kept for reference)
 *   – geodata/*.geojson|*.kml     (user-uploaded layer files, never migrated to DB)
 *
 * Idempotency:
 *   Missing files are silently skipped; no error is thrown.
 *   Table names are read from bdus_cfg_tables so we never rely on the
 *   (potentially already-deleted) tables.json.
 *
 * Safety pre-condition:
 *   M011 must have run before this migration (guaranteed by ordering in
 *   Migrate::ALL_MIGRATIONS). If bdus_cfg_tables is empty we abort without
 *   deleting anything — this protects fresh installations that never had
 *   JSON files in the first place.
 */
class M015_DeleteCfgJsonFiles
{
    public const NAME = 'M015_delete_cfg_json_files';

    /**
     * @param Manage      $manage  System table manager (provides DB access).
     * @param string|null $projDir Override project root (for testing).
     *                             Defaults to PROJ_DIR constant when null.
     */
    public static function run(Manage $manage, ?string $projDir = null): void
    {
        $db   = $manage->getDb();
        $root = $projDir ?? (defined('PROJ_DIR') ? PROJ_DIR : null);

        if ($root === null) {
            return;
        }
        if (!str_ends_with($root, '/')) {
            $root .= '/';
        }

        // Safety check: M011 must have populated bdus_cfg_tables first.
        $count = $db->query('SELECT COUNT(*) AS cnt FROM bdus_cfg_tables', [], 'read');
        if (($count[0]['cnt'] ?? 0) === 0) {
            return;
        }

        // 1 — cfg/tables.json
        self::rm($root . 'cfg/tables.json');

        // 2 — cfg/{table}.json  (one per table registered in bdus_cfg_tables)
        $tables = $db->query('SELECT name FROM bdus_cfg_tables', [], 'read') ?: [];
        foreach ($tables as $row) {
            $name = $row['name'] ?? null;
            if ($name) {
                self::rm($root . 'cfg/' . $name . '.json');
            }
        }

        // 3 — template/*.json
        $tmplDir = $root . 'template/';
        if (is_dir($tmplDir)) {
            foreach (scandir($tmplDir) ?: [] as $entry) {
                if (str_ends_with($entry, '.json')) {
                    self::rm($tmplDir . $entry);
                }
            }
        }
    }

    /**
     * Deletes a file if it exists; silently ignores missing files.
     */
    private static function rm(string $path): void
    {
        if (file_exists($path)) {
            @unlink($path);
        }
    }
}
