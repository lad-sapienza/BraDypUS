<?php
declare(strict_types=1);

namespace Config;

use Config\ConfigException;

class Load
{

    public static function all( string $path2cfg, string $prefix ) : array
    {
        $cfg['main'] = self::path2array($path2cfg . \DIRECTORY_SEPARATOR . 'app_data.json');
        $cfg['tables'] = self::getTables($path2cfg . \DIRECTORY_SEPARATOR . 'tables.json');
        foreach ($cfg['tables'] as $tb => $tb_data) {
            $cfg['tables'][$tb]['fields'] = self::getFields($path2cfg . \DIRECTORY_SEPARATOR . \str_replace($prefix, null, $tb) . '.json');
        }
        return $cfg;
    }

    private static function getFields( string $path ) : array
    {
        $flds_array = self::path2array($path);
        
        $ret = [];

        foreach ($flds_array as $fld_data) {
            $ret[$fld_data['name']] = $fld_data;
        }
        return $ret;
    }

    private static function getTables( string $path ) : array
    {
        $tables_array = self::path2array($path);
        $ret = [];

        foreach ($tables_array['tables'] as $tb_data) {
            $ret[$tb_data['name']] = $tb_data;
        }
        return $ret;
    }
    

    private static function path2array( string $path ) : array
    {
        if (!file_exists($path)){
            throw new ConfigException("Configuration file `$path` not found");
        }
        $array = json_decode(file_get_contents($path), true);
        if (!$array || !\is_array($array) || empty($array)){
            throw new ConfigException("Invalid JSON in file `$path`");
        }
        return $array;
    }
}