<?php

/**
 * @copyright 2007-2025 Julian Bogdani
 * @license AGPL-3.0; see LICENSE
 */

use \geoPHP\geoPHP;

class utils
{
    /**
     * Returns an array with a list of files contained in $dir.
     * Ignores `.`, `..`, `.DS_Store`, `.svn`, `.git`, `undefined`.
     * Returns false if $dir does not exist or is empty.
     */
    public static function dirContent(string $dir): array|false
    {
        $ignore = ['.', '..', '.DS_Store', 'undefined', '.svn', '.git'];
        if (!is_dir($dir)) {
            return false;
        }
        $ret = array_diff(scandir($dir), $ignore);
        return empty($ret) ? false : $ret;
    }

    /**
     * Recursively empties a directory.
     * If $delete_dir is true the directory itself is also removed.
     *
     * @throws \Exception
     */
    public static function emptyDir(string $dir, bool $delete_dir = false): bool
    {
        foreach (self::dirContent($dir) ?: [] as $file) {
            if (is_dir($dir . '/' . $file)) {
                self::emptyDir($dir . '/' . $file, true);
            } elseif (!@unlink($dir . '/' . $file)) {
                throw new \Exception("Cannot delete file: {$file}");
            }
        }

        if ($delete_dir && !@rmdir($dir)) {
            throw new \Exception("Cannot delete directory: {$dir}");
        }

        return true;
    }

    /**
     * Explodes a string by delimiter, trims each part, and removes empty elements.
     */
    public static function csv_explode(string $string, string $delimiter = ','): array
    {
        return array_filter(array_map('trim', explode($delimiter, $string)), 'strlen');
    }

    /**
     * Recursively filters an array, trimming string values.
     * Without a callback, removes null/empty elements.
     * Key–value association is maintained.
     */
    public static function recursiveFilter(array $arr, ?callable $callback = null): array
    {
        foreach ($arr as &$a) {
            if (is_array($a)) {
                $a = self::recursiveFilter($a, $callback);
            } else {
                $a = trim($a);
            }
        }
        return is_callable($callback) ? array_filter($arr, $callback) : array_filter($arr);
    }

    /**
     * Converts an array of DB rows (each containing a WKT `geometry` column)
     * to a GeoJSON FeatureCollection.
     *
     * @throws \Exception if $rows is not a multidimensional array
     */
    public static function multiArray2GeoJSON(string $tb, array $rows): array
    {
        $geo = ['type' => 'FeatureCollection', 'features' => []];

        foreach ($rows as $r) {
            if (!is_array($r)) {
                throw new \Exception('Input data is not a multidimensional array');
            }

            $geom = $r['geometry'] ?? $r[$tb . '.geometry'] ?? null;

            if (!$geom) {
                error_log('No valid geometry column found in row: ' . var_export($r, true));
                continue;
            }

            try {
                $geoPHP = geoPHP::load($geom, 'wkt');
            } catch (\Throwable $th) {
                error_log("WKT geometry {$geom} could not be parsed: " . var_export($r, true));
                continue;
            }

            $feat = [
                'type'       => 'Feature',
                'geometry'   => json_decode($geoPHP->out('geojson'), true),
            ];
            unset($r['geometry']);
            if ($r) {
                $feat['properties'] = $r;
            }
            $geo['features'][] = $feat;
        }

        return $geo;
    }

    /**
     * Returns true if another user with $email already exists in bdus_users.
     * Pass $id to exclude the current user (for updates).
     */
    public static function isDuplicateEmail(\DB\DB $db, string $email = '', ?int $id = null): bool
    {
        $manager = new \DB\System\Manage($db);

        $partial = ['email = ?'];
        $values  = [$email];

        if ($id) {
            $partial[] = 'id != ?';
            $values[]  = $id;
        }

        $res = $manager->getBySQL(
            'bdus_users',
            implode(' AND ', $partial) . ' LIMIT 1 OFFSET 0',
            $values,
            ['count(*) as tot']
        );

        return ($res[0]['tot'] > 0);
    }

    public static function debug(mixed $d, bool $echo = false): void
    {
        $json = json_encode($d, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE);
        if ($echo) {
            echo "<pre>{$json}</pre>";
        } else {
            error_log('DEBUG: ' . $json);
        }
    }
}
