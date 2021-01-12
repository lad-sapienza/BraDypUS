<?php
/**
 * @copyright 2007-2021 Julian Bogdani
 * @license AGPL-3.0; see LICENSE
 * @since				Apr 29, 2014
 *
 * translate
 *  can_not_delete_previous_geodata
 *  settings_missing
 *  empty_or_wrong_geojson
 *  error_on_insering_row
 */

use DB\DBInterface;

class importGeodata
{
  private $db;
  private $tb;

  /**
   * Loads and parses geojson and returns properties of first feature or throws
   *  \Exception on error.
   *
   * @param string $geojson GeoJSON string to check
   * @return type
   * @throws \Exception
   */
  public function checkGeojson($geojson)
  {
    //decode geojson to array
    $geo_array = is_string($geojson) ? json_decode($geojson, 1) : $geojson;

    // stop if geojson is empty or not valid
    if (!$geo_array || !is_array($geo_array) || !$geo_array['features'] || empty($geo_array['features'])) {
      throw new \Exception('GeoJSON string is empty, not well formatted or contains no geometries');
    }

    return $geo_array['features'][0]['properties'];
  }

  /**
   * Starts importing process and returns number of successfully processed records
   * or throws \Exception and rolls back all queries.
   * @param DBInterface $db    Instance of database object
   * @param string $tb  target table name
   * @param string $geojson geojson file content
   * @param string $idfield field name in geojson file containing  idfield value
   * @param boolean $deleteOld  Delete existing records flag. If true
   *  all existing records linked to $tb will be deleted
   * @return int  If successful the number of processed rows will be returned
   * @throws \Exception
   */
  public function runImport(DBInterface $db, string $tb, string $geojson, string $idfield, bool $deleteOld = false, string $table_id_fld): int
  {
    $this->db = $db;
    $this->tb = $tb;

    if (!$this->db) {
      throw new \Exception('Database object is missings');
    }
    if (!$this->tb) {
      throw new \Exception('Destination table is missing');
    }


    try
    {
      $this->db->beginTransaction();

      // Remove existing data
      if ($deleteOld) {
        $this->deletePreviousData();
      }

      //decode geojson to array
      $geo_array = json_decode($geojson, true);

      // stop if geojson is empty or not valid
      $this->checkGeojson($geo_array);

      $count = 0;

      // Loop in records and write to DB
      foreach ($geo_array['features'] as $row) {
        $id_value = $row['properties'][$idfield];


        if (!$id_value || empty($id_value)) {
         continue;
        }

        $res = $this->db->query(
          "SELECT id FROM " . $this->tb . " "
            . "WHERE " . $table_id_fld . " = ?",
            [ $row['properties'][$idfield] ],
          'read');

       $row_id = $res[0]['id'];

       if (!$row_id || empty($row_id)) {
         continue;
       }

       $wkt_geometry = \Symm\Gisconverter\Gisconverter::geojsonToWkt(json_encode($row['geometry']));

       $sql = " INSERT INTO " . PREFIX . "geodata "
         . "(table_link, id_link, geometry) "
         . " VALUES "
         . "(?, ?, ?) "
         ;
       $values = [ $this->tb, $row_id, $wkt_geometry ];


       if (!$this->db->query($sql, $values, 'boolean')) {
         throw new \Exception('Error in executing SQL: ' . $sql);
       }

       $count++;
     }

     $this->db->commit();

     return $count;
    
    } catch (\DB\DBException $e) {

      $this->db->rollBack();

      throw new \Exception($e);
    }

  }


  /**
   *
   * Deletes from geodata table all previous geometries relative to table.
   * Throws Exception on failure.
   * @throws Exception
   */

  private function deletePreviousData()
  {
    $sql = "DELETE FROM " . PREFIX . "geodata WHERE table_link = ?";

    if (!$this->db->query($sql, [ $this->tb ], 'boolean')) {
      throw new \Exception('Error in deleting previous inserted geodata');
    }
  }

}

?>
