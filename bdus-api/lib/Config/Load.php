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
     * Returns the path to the main config file, falling back to the legacy
     * app_data.json name for apps that have not yet run the M016 migration.
     */
    private static function resolveMainConfig(string $path2cfg): string
    {
        $new = $path2cfg . '/config.json';
        if (file_exists($new)) {
            return $new;
        }
        return $path2cfg . '/app_data.json'; // pre-M016 fallback
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
