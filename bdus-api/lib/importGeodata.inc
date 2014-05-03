<?php

/**
 * @author			Julian Bogdani <jbogdani@gmail.com>
 * @copyright		BraDyUS. Communicating Cultural Heritage, http://bradypus.net 2007-2014 
 * @license			All rights reserved
 * @since				Apr 29, 2014
 * 
 * translate
 *  can_not_delete_previous_geodata
 *  settings_missing
 *  empty_or_wrong_geojson
 *  error_on_insering_row
 */

class importGeodata
{
  private $db;
  private $tb;
  private $geojson;
  private $idfield;
  private $deleteOld;
  
  /**
   * 
   * @param DB $db    Instance of database object
   * @param string $tb  target table name
   * @param string $geojson geojson file content
   * @param string $idfield field name in geojson file containing  idfield value
   * @param boolean $deleteOld  Delete existing records flag. If true 
   *  all existing records linked to $tb will be deleted
   */
  public function settings(DB $db, $tb, $geojson, $idfield, $deleteOld = false)
  {
    $this->db = $db;
    $this->tb = $tb;
    $this->geojson = $geojson;
    $this->idfield = $idfield;
    $this->deleteOld = $deleteOld;
    
    return $this;
  }
  
  /**
   * Loads and parses geojson and returns properties of first feature or throws
   *  myException on error.
   * 
   * @return type
   * @throws myException
   */
  public function checkGeojson()
  {
    //decode geojson to array
    $geo_array = json_decode($this->geojson, 1);
    
    // stop if geojson is empty or not valid
    if (!$geo_array || !is_array($geo_array) || !$geo_array['features'] || empty($geo_array['features']))
    {
      throw new myException('empty_or_wrong_geojson');
    }
    
    return $geo_array['features'][0]['properties'];
  }
  
  /**
   * Starts importing process and returns number of successfully processed records
   * or throws myException and rolls back all queries.
   * @return int  If successful the number of processed rows will be returned
   * @throws myException
   * @throws Exception
   */
  public function runImport()
  {
    if (!$this->db || !$this->tb || !$this->geojson || !$this->idfield)
    {
      throw new myException('settings_missing');
    }
    
    try
    {
      $this->db->beginTransaction();
      
      // Remove existing data
      if ($this->deleteOld)
      {
        $this->deletePreviousData();
      }
      
      //decode geojson to array
      $geo_array = json_decode($this->geojson, 1);
      
      // stop if geojson is empty or not valid
      if (!$geo_array || !is_array($geo_array) || !$geo_array['features'] || empty($geo_array['features']))
      {
        throw new Exception('empty_or_wrong_geojson');
      }
      
      $count = 0;
      
      // Loop in records and write to DB
      foreach ($geo_array['features'] as $row)
      {
        $id_value = $row['properties'][$this->idfield];
        
        
        if (!$id_value || empty($id_value))
        {
         continue;
        }
        
        
        $res = $this->db->query(
          "SELECT `id` FROM `" . $this->tb . "` "
            . "WHERE `" . cfg::tbEl($this->tb, 'id_field') . "` = :id_field",
          array(':id_field' => $row['properties'][$this->idfield]),
          'read');
        
       $row_id = $res[0]['id'];
       
       if (!$row_id || empty($row_id))
       {
         continue;
       }

       $wkt_geometry = \Symm\Gisconverter\Gisconverter::geojsonToWkt(json_encode($row['geometry']));
       
       $sql = " INSERT INTO `" . PREFIX . '__geodata` '
         . "(`table_link`, `id_link`, `geometry`) "
         . " VALUES "
         . "(:table_link, :id_link, :geometry) "
         ;
       $values = array(
         ':table_link' => $this->tb,
         ':id_link' => $row_id,
         ':geometry' => $wkt_geometry
       );
       
       
       
       if (!$this->db->query($sql, $values, 'boolean'))
       {
         throw new Exception('error_on_insering_row');
       }
       
       $count++;
     }
     
     $this->db->commit();
     
     return $count;
    }
    catch (Exception $e)
    {
      $this->db->rollBack();
      
      throw new myException($e);
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
    $sql = "DELETE FROM " . PREFIX . "__geodata WHERE `table_link` = '" . $this->tb . "'";
    
    if (!$this->db->query($sql, false, 'boolean'))
    {
      throw new Exception('can_not_delete_previous_geodata');
    }
  }
  
}

?>
