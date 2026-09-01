<?php

/**
 * @copyright 2007-2022 Julian Bogdani
 * @license AGPL-3.0; see LICENSE
 */

declare(strict_types=1);

namespace Config;

use \Config\ConfigException;

class Load
{

    /**
     * Loads the full config (main + tables + fields) from JSON files on disk.
     *
     * @deprecated Since v5.1 table/field config is stored in the DB
     *             (bdus_cfg_tables / bdus_cfg_fields via LoadFromDB).
     *             This method is only called when LoadFromDB::isAvailable()
     *             returns false — i.e., for apps that have not yet run the
     *             M011_ConfigToDb migration.  Once all apps are migrated
     *             this method (and the JSON file parsing below) can be removed.
     */
    public static function all(string $path2cfg): array
    {
        $cfg['main'] = self::path2array(self::resolveMainConfig($path2cfg));
        $cfg['tables'] = self::getTables($path2cfg . '/tables.json');
        foreach ($cfg['tables'] as $tb => $tb_data) {
            $cfg['tables'][$tb]['fields'] = self::getFields($path2cfg . '/' . $tb . '.json');
        }
        return $cfg;
    }

    /**
     * Loads only the app-level settings from config.json (or app_data.json
     * for apps that have not yet run the M016 migration).
     * Used by Config when table/field definitions come from the DB instead.
     */
    public static function main(string $path2cfg): array
    {
        return self::path2array(self::resolveMainConfig($path2cfg));
    }

    /**
     * Resolves the path to the main config file, handling all migration states.
     *
     * The config file has moved twice across the v4→v5 migration sequence:
     *
     *   pre-M016  : projects/{app}/cfg/app_data.json   (v4 legacy name)
     *   post-M016 : projects/{app}/cfg/config.json     (renamed by M016)
     *   post-M018 : projects/{app}/config.json         (moved to project root by M018)
     *
     * The three candidates are tried in newest-first order so that already-migrated
     * apps resolve in one stat() call while legacy apps still work transparently.
     *
     * @param string $path2cfg  Project root directory (e.g. "projects/{app}").
     *                          Do NOT pass the cfg/ subdirectory.
     *
     * @todo Once the minimum supported installation has run M016 + M018, drop
     *       the two legacy candidates and keep only "{base}/config.json".
     */
    private static function resolveMainConfig(string $path2cfg): string
    {
        $base = rtrim($path2cfg, '/');
        foreach ([
            $base . '/config.json',         // post-M018: file at project root
            $base . '/cfg/config.json',     // post-M016, pre-M018: file in cfg/
            $base . '/cfg/app_data.json',   // pre-M016: v4 legacy filename
        ] as $candidate) {
            if (file_exists($candidate)) {
                return $candidate;
            }
        }
        return $base . '/config.json'; // Let path2array throw a clear error.
    }

    private static function getFields(string $path): array
    {
        $flds_array = self::path2array($path);

        $ret = [];

        foreach ($flds_array as $fld_data) {
            $ret[$fld_data['name']] = $fld_data;
        }
        return $ret;
    }

    private static function getTables(string $path): array
    {
        $tables_array = self::path2array($path);
        $ret = [];

        foreach ($tables_array['tables'] as $tb_data) {
            $ret[$tb_data['name']] = $tb_data;
        }
        return $ret;
    }


    private static function path2array(string $path): array
    {
        if (!file_exists($path)) {
            throw new ConfigException("Configuration file `$path` not found");
        }
        $array = json_decode(file_get_contents($path), true);
        if (!$array || !\is_array($array) || empty($array)) {
            throw new ConfigException("Invalid JSON in file `$path`");
        }
        return $array;
    }
}
