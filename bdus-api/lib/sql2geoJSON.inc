<?php
/**
 * Trasforms database rows to geojson (http://www.geojson.org/geojson-spec.html)
 * 
 * @author			Julian Bogdani <jbogdani@gmail.com>
 * @copyright		BraDypUS 2007-2012
 * @license			All rights reserved
 * @since			Aug 20, 2012
 */


class sql2geoJSON
{
	private $geo;
	
	/**
	 * Starts object with 'header'
	 */
	public function __construct()
	{
		$this->geo['type'] = 'FeatureCollection';
		$this->geo['features'] = array();
	}
	
	/**
	 * Returns formatted result as object
	 * @return array
	 */
	public function getObject()
	{
		return $this->geo;
	}
	
	/**
	 * Returns formatted result as JSON
	 * @param boolean $pretty
	 * @return string
	 */
	public function getGeoJSON($pretty = false)
	{
		return $pretty ? json_encode($this->geo, JSON_UNESCAPED_UNICODE|JSON_PRETTY_PRINT) : json_encode($this->geo);
	}
	
	/**
	 * Adds DB row to main object, accordig to type. Throws myException if no geometry column is found
	 * @param array $row
	 * @throws myException
	 */
	public function addFeature($row)
	{
		if (!$row['geometry'])
		{
			throw new myException('No geoemtry column found in row: ' . var_export($row, true));
		}
		
		$arr['type']		= 'Feature';
		$arr['geometry']	= $this->getGeometry($row['geometry']);
		unset($row['geometry']);
		if ($row)
		{
			$arr['properties']	= $row;
		}
		
		array_push($this->geo['features'], $arr);
	}
	
	/**
	 * Formats geometry field. Throws myException on invalid syntax or missing type
	 * @param string $wkt
	 * @throws myException
	 */
	private function getGeometry($wkt)
	{
		$wkt = preg_replace('!\s+!', ' ', $wkt);
		
		$wkt = str_replace(
				array(', ', ' (', ') '),
				array(',',  '(',  ')'),
				$wkt);

		// POINTS
		if (preg_match('/POINT\s?\(([0-9\.\-]+)\s([0-9\.\-]+)\)/i', $wkt, $matches))
		{
			return array('type'=>'Point', 'coordinates'=>array( (float)$matches[1], (float)$matches[2]));
		}
		
		
		//LINESTRING
		if (preg_match('/LINESTRING\s?\([0-9\.\-]+\s[0-9\.\-]+(,\s?[0-9\.\-]+\s[0-9\.\-]+)+\)/i', $wkt, $matches))
		{
			$match = preg_replace('/LINESTRING\((.+)\)/i', '$1', $wkt);
			$coords = explode(',', $match);
			
			foreach ($coords as $k=>$coord)
			{
				$s = explode(' ', $coord);
				$coordinates[$k] = array_map(
						create_function('$value', 'return (float)$value;'),
						$s);
			}
			return array('type'=>'LineString', 'coordinates' => $coordinates);
		}
		
		// POLYGON
		if (preg_match('/POLYGON\s?\\(\([0-9\.\-]+\s[0-9\.\-]+(,\s?[0-9\.\-]+\s[0-9\.\-]+)+\)\)/i', $wkt, $matches))
		{
			$match = preg_replace('/POLYGON\(\((.+)\)\)/i', '$1', $wkt);
			$coords = explode(',', $match);
			foreach ($coords as $k=>$coord)
			{
				$s = explode(' ', $coord);
				$coordinates[$k] = array_map(
						create_function('$value', 'return (float)$value;'),
						$s);
			}
			
			if ($coordinates[0] != $coordinates[(count($coordinates)-1)])
			{
				throw new myException('Polygon WKT: >>' . $wkt . '<< not valid. First point does not match with last point');
			}
			return array('type'=>'Polygon', 'coordinates' => array($coordinates));
		}
		
		/*
		 * TODO: ADD OTHER TYPES:
		 * 	POLYGON ((35 10, 10 20, 15 40, 45 45, 35 10), (20 30, 35 35, 30 20, 20 30))
		 * 	MULTIPOINT ((10 40), (40 30), (20 20), (30 10))
		 * 	MULTIPOINT (10 40, 40 30, 20 20, 30 10)
		 * 	MULTILINESTRING ((10 10, 20 20, 10 40), (40 40, 30 30, 40 20, 30 10))
		 * 	MULTIPOLYGON (((30 20, 10 40, 45 40, 30 20)), ((15 5, 40 10, 10 20, 5 10, 15 5)))
		 * 	MULTIPOLYGON (((40 40, 20 45, 45 30, 40 40)), ((20 35, 45 20, 30 5, 10 10, 10 30, 20 35), (30 20, 20 25, 20 15, 30 20)))
		 * 
		 */
		
		throw new myException('No valid geometry handler found for WKT: >>' . $wkt . '<< !');
	}
}