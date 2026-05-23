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
     * Resolves the path to the main config file.
     *
     * Search order (handles all migration states):
     *   1. {projDir}/config.json          — post-M018 (project root)
     *   2. {projDir}/cfg/config.json      — post-M016, pre-M018
     *   3. {projDir}/cfg/app_data.json    — pre-M016 (legacy name)
     *
     * $path2cfg is the project root (e.g. projects/{app}/).
     */
    private static function resolveMainConfig(string $path2cfg): string
    {
        $base = rtrim($path2cfg, '/');
        foreach ([
            $base . '/config.json',
            $base . '/cfg/config.json',
            $base . '/cfg/app_data.json',
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
