<?php
/**
 * @author			Julian Bogdani <jbogdani@gmail.com>
 * @copyright		BraDypUS, Julian Bogdani <jbogdani@gmail.com>
 * @license			See file LICENSE distributed with this code
 * @since				May 3, 2014
 */

class import_geodata_ctrl extends Controller
{
  public function start()
  {
    $this->render('import_geodata', 'start', [
      'tables' => cfg::getNonPlg()
    ]);
  }
  
  public function load_file()
  {
    $tb = $this->request['tb'];
    
    $this->render('import_geodata', 'load_file', [
      'tb' => $tb
    ]);
  }
  
  public function setFields()
  {
    $tb = $this->get['tb'];
    $file = $this->get['file'];
    
    try {

      $import = new importGeodata();

      $resp = $import->checkGeojson(file_get_contents($file));
      
      $this->render('import_geodata', 'setFields', [
        'tb' => $tb,
        'file' => $file,
        'id_label' => cfg::fldEl($tb, cfg::tbEl($tb, 'id_field'), 'label'),
        'resp' => $resp
      ]);
      
    } catch (\Exception $e) {
      $this->log->error($e);
      utils::alert_div('empty_or_wrong_geojson', true);
    }
  }
  
  public function confirm()
  {
    $tb = $this->get['tb'];
    $file = $this->get['file'];
    $id_field = $this->get['id_field'];
    
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
    $tb = $this->get['tb'];
    $file = $this->get['file'];
    $id_field = $this->get['id_field'];
    $delete = $this->get['delete'] === 'yes' ? true :  false;
    
    
    try {
      $import = new importGeodata();
      
      $import->settings($this->db, $tb, file_get_contents($file), $id_field, $delete);
      
      $totalImports = $import->runImport();
      
      echo '<div class="text-success lead"><i class="glyphicon glyphicon-ok"></i> ' 
        . tr::get('geodata_ok_uploaded', [$totalImports] ) . '</div>';
      
    } catch (\Exception $e) {
      $this->log->error($e);
      utils::alert_div('geodata_ok_uploaded', true);
    }
  }
}
?>
