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
		$arr['geometry']	= json_decode(\Symm\Gisconverter\Gisconverter::wktToGeojson($row['geometry']), true);
		unset($row['geometry']);
		if ($row)
		{
			$arr['properties']	= $row;
		}
		
		array_push($this->geo['features'], $arr);
	}
}