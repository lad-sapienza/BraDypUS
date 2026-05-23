<?php

/**
 * @copyright 2007-2025 Julian Bogdani
 * @license AGPL-3.0; see LICENSE
 *
 * Storage for the GeoFace layer configuration.
 *
 * Post-M014 the canonical store is bdus_cfg_geoface (one row, id=1).
 * Pre-M014 (or in test environments without PROJ_DIR) the file
 * geodata/index.json is used as a fallback.
 */

namespace Config;

use DB\DBInterface;

class GeofaceConfig
{
    // ── Availability check ───────────────────────────────────────────────────

    /**
     * Returns true when the DB table bdus_cfg_geoface exists and has a row.
     * Used to branch between DB and file storage.
     */
    public static function isAvailable(DBInterface $db): bool
    {
        try {
            $row = $db->query(
                'SELECT id FROM bdus_cfg_geoface WHERE id = 1',
                [],
                'read'
            );
            return !empty($row);
        } catch (\Throwable $e) {
            return false;
        }
    }

    // ── Read ─────────────────────────────────────────────────────────────────

    /**
     * Returns the decoded layers array.
     * Tries DB first; falls back to geodata/index.json if DB is unavailable.
     *
     * @param  DBInterface|null $db       DB connection.
     * @param  string|null      $projDir  Override project root (for testing).
     *                                    Defaults to PROJ_DIR constant.
     * @return array  Array of layer objects (may be empty).
     */
    public static function getLayers(?DBInterface $db, ?string $projDir = null): array
    {
        if ($db && self::isAvailable($db)) {
            $row = $db->query(
                'SELECT layers FROM bdus_cfg_geoface WHERE id = 1',
                [],
                'read'
            );
            return json_decode($row[0]['layers'] ?? '[]', true) ?: [];
        }

        // File fallback.
        $root = $projDir ?? (defined('PROJ_DIR') ? PROJ_DIR : null);
        if ($root !== null) {
            if (!str_ends_with($root, '/')) $root .= '/';
            $path = $root . 'geodata/index.json';
            if (file_exists($path)) {
                return json_decode(file_get_contents($path), true) ?: [];
            }
        }

        return [];
    }

    // ── Write ────────────────────────────────────────────────────────────────

    /**
     * Persists the layers array.
     * Writes to DB when available; writes to file otherwise.
     *
     * @param  DBInterface|null $db       DB connection.
     * @param  array            $layers   Array of layer objects to store.
     * @param  string|null      $projDir  Override project root (for testing).
     * @return bool                       True on success.
     */
    public static function saveLayers(?DBInterface $db, array $layers, ?string $projDir = null): bool
    {
        $json = json_encode($layers, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);

        if ($db && self::isAvailable($db)) {
            try {
                $db->query(
                    'UPDATE bdus_cfg_geoface SET layers = ? WHERE id = 1',
                    [$json],
                    'boolean'
                );
                return true;
            } catch (\Throwable $e) {
                return false;
            }
        }

        // File fallback.
        $root = $projDir ?? (defined('PROJ_DIR') ? PROJ_DIR : null);
        if ($root !== null) {
            if (!str_ends_with($root, '/')) $root .= '/';
            return file_put_contents($root . 'geodata/index.json', $json) !== false;
        }

        return false;
    }
}
