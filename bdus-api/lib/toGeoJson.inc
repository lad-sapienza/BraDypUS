<?php
/**
 * Trasforms database rows to geojson (http://www.geojson.org/geojson-spec.html)
 *
 * @author			Julian Bogdani <jbogdani@gmail.com>
 * @copyright		BraDypUS, Julian Bogdani <jbogdani@gmail.com>
 * @license			See file LICENSE distributed with this code
 * @since			Aug 20, 2012
 */


class toGeoJson
{

	public static function fromMultiArray($rows, $returnObject = false)
	{
		$geo = [
			'type' => 'FeatureCollection',
			'features' => []
		];


		if (!is_array($rows)) {
			throw new Exception('Input data is not an array');
		}

		foreach($rows as $r) {
			if (!is_array($r)) {
				throw new Exception('Input data is not a multidimensional array');
			}

			if (!$r['geometry']) {
				throw new myException('No geoemtry column found in row: ' . var_export($r, true));
			}

			$arr['type']		= 'Feature';
			$arr['geometry']	= json_decode(\Symm\Gisconverter\Gisconverter::wktToGeojson($r['geometry']), true);

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
