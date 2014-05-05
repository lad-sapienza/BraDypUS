<?php
/**
 * @author			Julian Bogdani <jbogdani@gmail.com>
 * @copyright		BraDyUS. Communicating Cultural Heritage, http://bradypus.net 2007-2014 
 * @license			See file LICENSE distributed with this code
 * @since				May 3, 2014
 */

class import_geodata_ctrl extends Controller
{
  public function start()
  {
    $this->render('import_geodata', 'start', array(
      'tables' => cfg::getNonPlg()
    ));
  }
  
  public function load_file()
  {
    $tb = $this->request['param'][0];
    
    $this->render('import_geodata', 'load_file', array(
      'tb' => $tb
    ));
  }
  
  public function setFields()
  {
    $tb = $this->request['param'][0];
    $file = $this->request['param'][1];
    
    try
    {
      $import = new importGeodata();

      $resp = $import->checkGeojson(file_get_contents($file));
      
      $this->render('import_geodata', 'setFields', array(
        'tb' => $tb,
        'file' => $file,
        'id_label' => cfg::fldEl($tb, cfg::tbEl($tb, 'id_field'), 'label'),
        'resp' => $resp
      ));
      
    }
    catch (myException $e)
    {
      $e->log();
      utils::alert_div('empty_or_wrong_geojson', true);
    }
  }
  
  public function confirm()
  {
    $tb = $this->request['param'][0];
    $file = $this->request['param'][1];
    $id_field = $this->request['param'][2];
    
    $this->render('import_geodata', 'confirm', array(
      'tb' => $tb,
      'tb_label' => cfg::tbEl($tb, 'label'),
      'file' => $file,
      'id_label' => cfg::fldEl($tb, cfg::tbEl($tb, 'id_field'), 'label'),
      'id_field' => $id_field
      ));
  }
  
  public function process()
  {
    $tb = $this->request['param'][0];
    $file = $this->request['param'][1];
    $id_field = $this->request['param'][2];
    $delete = $this->request['param'][3] === 'yes' ? true :  false;
    
    
    try
    {
      $import = new importGeodata();
      
      $import->settings(new DB(), $tb, file_get_contents($file), $id_field, $delete);
      
      $totalImports = $import->runImport();
      
      echo '<div class="text-success lead"><i class="glyphicon glyphicon-ok"></i> ' 
        . tr::sget('geodata_ok_uploaded', array($totalImports)) . '</div>';
      
    }
    catch (myException $e)
    {
      $e->log();
      utils::alert_div('geodata_ok_uploaded', true);
    }
  }
}
?>
