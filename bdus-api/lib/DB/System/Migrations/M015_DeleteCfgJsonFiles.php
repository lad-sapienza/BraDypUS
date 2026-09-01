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
 *   – cfg/*.json  (ALL JSON files in cfg/ except app_data.json)
 *     Includes tables.json, per-table {name}.json, and system-table JSON
 *     files such as files.json that were not imported by M011 because they
 *     describe system tables but are no longer read by any code.
 *   – template/*.json  (record templates → bdus_cfg_templates)
 *
 * Files intentionally preserved:
 *   – cfg/app_data.json           (DB credentials — renamed to config.json by M016)
 *   – cfg/.jwt_secret             (JWT signing key — moved to project root by M016)
 *   – geodata/index.json          (legacy fallback; superseded by bdus_cfg_geoface)
 *   – geodata/*.geojson|*.kml     (user-uploaded layer files, never migrated to DB)
 *
 * Idempotency:
 *   Missing files are silently skipped; no error is thrown.
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

        // 1 — All cfg/*.json except app_data.json.
        //     Glob covers tables.json, per-user-table {name}.json, AND
        //     system-table JSON files (e.g. files.json) that M011 excluded
        //     from bdus_cfg_tables but that no code reads any more.
        $cfgDir = $root . 'cfg/';
        if (is_dir($cfgDir)) {
            foreach (scandir($cfgDir) ?: [] as $entry) {
                if ($entry !== 'app_data.json' && str_ends_with($entry, '.json')) {
                    self::rm($cfgDir . $entry);
                }
            }
        }

        // 2 — template/*.json
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
