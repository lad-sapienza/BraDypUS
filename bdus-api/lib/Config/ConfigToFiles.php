<?php
declare(strict_types=1);

namespace Config;


class ConfigToFiles
{

    public static function all( array $cfg, string $path2cfg, string $prefix ) : void
    {
        self::write_in_file($path2cfg . \DIRECTORY_SEPARATOR . 'app_data.json', $cfg['main']);
        self::writeTables($path2cfg . \DIRECTORY_SEPARATOR . 'tables.json', $cfg['tables']);
        foreach ($cfg['tables'] as $tb => $tb_data) {
            self::writeFields($path2cfg . \DIRECTORY_SEPARATOR . \str_replace($prefix, null, $tb) . '.json', $cfg['tables'][$tb]['fields']);
        }
    }

    private static function writeFields( string $path, array $data ) : void
    {
        $ret = [];

        foreach ($data as $fld => $fld_data) {
            $ret[] = $fld_data;
        }
        self::write_in_file($path, $ret);
    }

    private static function writeTables( string $path , array $data) : void
    {
        $ret = [];

        foreach ($data as $tb => $tb_data) {
            unset($tb_data['fields']);
            $ret[] = $tb_data;
        }
        self::write_in_file($path, ["tables" => $ret]);
    }
    

    private static function write_in_file( string $path, array $data ) : void
    {
        $ret = \file_put_contents(\json_encode($data, \JSON_PRETTY_PRINT|\JSON_UNESCAPED_UNICODE));
        if (!$ret){
            throw new ConfigException("Cannot write configuration file `$path`");
        }
    }
}