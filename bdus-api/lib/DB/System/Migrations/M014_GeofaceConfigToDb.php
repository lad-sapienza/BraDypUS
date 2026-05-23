<?php

/**
 * @copyright 2007-2025 Julian Bogdani
 * @license AGPL-3.0; see LICENSE
 */

namespace DB\System\Migrations;

use DB\System\Manage;

/**
 * Migrates the GeoFace layer configuration from the filesystem file
 * projects/{app}/geodata/index.json into the database table bdus_cfg_geoface.
 *
 * Why:
 *   – Consistent with the Config-to-DB direction started by M011.
 *   – After this migration the API tier needs no write access to the
 *     geodata/ directory for configuration (only for actual geo-files).
 *   – Atomic backups: a single DB dump captures the layer config too.
 *
 * What stays on the filesystem:
 *   – geodata/*.geojson, *.kml, *.json local layer files uploaded by users
 *     (these are binary/large and are served directly by the map client).
 *   – geodata/index.json is kept as a read-only fallback for any legacy
 *     tool that reads it directly; it is no longer written by the app.
 *
 * Idempotency:
 *   Tracked in bdus_migrations like all other migrations; never runs twice.
 *   If bdus_cfg_geoface already has a row (shouldn't happen on first run),
 *   the INSERT OR IGNORE is a no-op.
 */
class M014_GeofaceConfigToDb
{
    public const NAME = 'M014_geoface_config_to_db';

    /**
     * @param Manage      $manage   System table manager.
     * @param string|null $projDir  Override project root (for testing only).
     *                              Defaults to PROJ_DIR constant when null.
     */
    public static function run(Manage $manage, ?string $projDir = null): void
    {
        // 1 — Create the table.
        $manage->createTable('bdus_cfg_geoface');

        // 2 — Import existing index.json into the DB row (id=1).
        $root = $projDir ?? (defined('PROJ_DIR') ? PROJ_DIR : null);
        if ($root === null) {
            return;
        }
        if (!str_ends_with($root, '/')) {
            $root .= '/';
        }

        $indexFile = $root . 'geodata/index.json';
        $layers    = '[]';

        if (file_exists($indexFile)) {
            $raw = file_get_contents($indexFile);
            if ($raw !== false) {
                // Validate: must decode to an array.
                $decoded = json_decode($raw, true);
                if (is_array($decoded)) {
                    // Re-encode to ensure clean JSON (no BOM, normalised unicode).
                    $layers = json_encode($decoded, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
                }
            }
        }

        $manage->getDb()->query(
            'INSERT OR IGNORE INTO bdus_cfg_geoface (id, layers) VALUES (1, ?)',
            [$layers],
            'boolean'
        );
    }
}
