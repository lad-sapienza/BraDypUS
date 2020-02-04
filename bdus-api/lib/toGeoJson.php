<?php
/**
 * Trasforms database rows to geojson (http://www.geojson.org/geojson-spec.html)
 * Throws error on error
 *
 * @author			Julian Bogdani <jbogdani@gmail.com>
 * @copyright		BraDypUS, Julian Bogdani <jbogdani@gmail.com>
 * @license			See file LICENSE distributed with this code
 * @since			Aug 20, 2012
 */


class toGeoJson
{
    /**
     * Converts a multidimensional array to GeoJSON
     * @param  array  $rows         Array of data
     * @param  boolean $returnObject If true an object will be returned, by default JSON is returned
     * @param  string  $tb           Table name to use as geometry field prefix
     * @return array|string          JSON or array of data with geojson geometry
     */
    public static function fromMultiArray($rows, $returnObject = false, $tb)
    {
        $geo = [
            'type' => 'FeatureCollection',
            'features' => []
        ];


        if (!is_array($rows)) {
            throw new Exception('Input data is not an array');
        }

        foreach ($rows as $r) {
            if (!is_array($r)) {
                throw new Exception('Input data is not a multidimensional array');
            }

            if (!$r['geometry'] && !$r[$tb . '.geometry']) {
                // single row error does not block entire process
                // throw new myException('No geometry column found in row: ' . var_export($r, true));
                error_log('No valid geometry column found in row: ' . var_export($r, true));
                continue;
            }

            $arr['type']		= 'Feature';
            $arr['geometry']	= json_decode(\Symm\Gisconverter\Gisconverter::wktToGeojson($r['geometry'] ? $r['geometry'] : $r[$tb . '.geometry']), true);

            unset($r['geometry']);
            if ($r) {
                $arr['properties']	= $r;
            }

            array_push($geo['features'], $arr);
        }

        if ($returnObject) {
            return $geo;
        } else {
            return json_encode($geo, JSON_UNESCAPED_UNICODE|JSON_PRETTY_PRINT);
        }
    }
}
