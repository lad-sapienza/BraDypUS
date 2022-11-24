<?php

/**
 * @copyright 2007-2021 Julian Bogdani
 * @license AGPL-3.0; see LICENSE
 */

use DB\Engines\AvailableEngines;
use DB\Validate\Validate;
use DB\System\Manage;
use \DB\Alter;

class config_ctrl extends Controller
{
  public function home()
  {
    $table_list = $this->cfg->get('tables.*.label');

    $this->render('config', 'home', [
      "table_list" => $table_list
    ]);
  }

  public function app_properties()
  {
    try {
      $sys_manage = new Manage($this->db, $this->prefix);
      $users = $sys_manage->getBySQL('users', '1=1');

      foreach ($users as &$u) {
        $u['verbose_privilege'] = \utils::privilege($u['privilege'], 1);
      }
    } catch (\Throwable $e) {
      $users = [];
    }

    $this->render('config', 'app_properties', [
      'available_langs' => \tr::getAvailable(),
      'info' => $this->cfg->get('main'),
      'status' => ['on', 'frozen', 'off'],
      'users' => $users,
      'db_engines' => \DB\Engines\AvailableEngines::getList()
    ]);
  }

  public function fld_list()
  {
    $tb = $this->get['tb'];

    $this->render('config', 'fld_list', [
      'tb' => $tb,
      'tb_label' => $this->cfg->get("tables.$tb.label"),
      'all_fields' => $this->cfg->get("tables.$tb.fields.*")
    ]);
  }


  public function field_properties()
  {
    $tb = $this->get['tb'];
    $fld = $this->get['fld'] ?: false;

    $data = $fld ? $this->cfg->get("tables.$tb.fields.$fld") : [];

    $sys_manage = new Manage($this->db, $this->prefix);

    $res = $sys_manage->getBySQL('vocabularies', '1=1 GROUP BY voc', [], ["voc"]);
    $all_voc = [];
    foreach ($res as $row) {
      array_push($all_voc, $row['voc']);
    }

    $fld_structure = file_get_contents(__DIR__ . '/fld_structure.json');

    $fld_structure = str_replace(
      [
        'list-of-system-defined-vocabularies-here',
        'list-of-available-tables-here'
      ],
      [
        implode('","', $all_voc),
        implode('","', array_values($this->cfg->get('tables.*.name')))
      ],
      $fld_structure
    );
    $fld_structure = json_decode($fld_structure, TRUE);

    $this->render('config', 'field_properties', [
      'tb'    => $tb,
      'fld'   => $fld,
      'data'  => $data,
      'fld_structure' => $fld_structure
    ]);
  }


  public function table_properties()
  {
    $tb = $this->get['tb'] ?: false;

    $table_properties = $tb ? $this->cfg->get("tables.$tb") : [];

    // default values
    if (!$table_properties['name'])     $table_properties['name'] = $this->prefix;
    if (!$table_properties['preview'])  $table_properties['preview'] = array(0 => '');
    if (!$table_properties['plugin'])   $table_properties['plugin'] = array(0 => '');
    if (!$table_properties['link'])     $table_properties['link'] = array(0 => array('fld' => array(0 => [])));

    foreach ($table_properties['link'] as $index => $link) {
      foreach ($link['fld'] as $i => $l_data) {
        $table_properties['link'][$index]['fld'][$i]['other_list'] = $this->cfg->get("tables.{$link['other_tb']}.fields.*.label");
      }
    }

    $this->render('config', 'table_properties', [
      'data'  => $table_properties,
      'tb'    => $tb,
      'field_list' => $tb && $this->cfg->get("tables.$tb.fields.*.label") ? $this->cfg->get("tables.$tb.fields.*.label") : ['id' => 'id'],
      'template_list' => \utils::dirContent(PROJ_DIR . 'templates/'),
      'available_plugins' => is_array($this->cfg->get('tables.*.label', 'is_plugin', '1')) ? $this->cfg->get('tables.*.label', 'is_plugin', '1') : [],
      'available_tables' => $this->cfg->get('tables.*.label'),
    ]);
  }

  private function check_required(array $data, array $indices): array
  {
    $missing = [];
    foreach ($indices as $index) {
      if (!$data[$index]) {
        $missing[$index] = true;
      }
    }
    return $missing;
  }

  public function save_tb_data()
  {
    $post = $this->post;

    try {

      $post = \utils::recursiveFilter($post);

      // make indexed array for links and geoface
      if ($post['link']) {

        $post['link'] = array_values($post['link']);

        $tmp = array_values($post['link']);

        foreach ($tmp as &$link) {
          $link['fld'] = array_values($link['fld']);
        }

        $post['link'] = false;
        $post['link'] = $tmp;
      }

      if ($post['is_plugin'] == 1) {
        $missing = $this->check_required($post, ['name', 'label']);
        if (!empty($missing)) {
          throw new \Exception("Required field(s):  " . implode(', ', $missing) . " are missing");
        }
      } else if (!$post['is_plugin'] || $post['is_plugin'] == 0) {

        $missing = $this->check_required($post, ['name', 'label', 'order', 'id_field', 'preview']);
        if (!empty($missing)) {
          throw new \Exception("Required field(s):  " . implode(', ', $missing) . " are missing");
        }
      }

      $this->cfg->setTable($post);

      $this->response('ok_cfg_data_updated');
    } catch (\Throwable $e) {
      $this->log->error($e);
      $this->response('error_cfg_data_updated', 'error');
    }
  }


  public function add_new_tb()
  {
    $post = $this->post;

    try {
      $post = \utils::recursiveFilter($post);

      if ($post['is_plugin'] === '1') {
        $missing = $this->check_required($post, ['name', 'label']);
        if (!empty($missing)) {
          throw new \Exception("Required field(s):  " . implode(', ', $missing) . " are missing");
        }
      } else if (!$post['is_plugin'] || $post['is_plugin'] == 0) {
        $missing = $this->check_required($post, ['name', 'label', 'order', 'id_field', 'preview']);
        if (!empty($missing)) {
          throw new \Exception("Required field(s):  " . implode(', ', $missing) . " are missing");
        }
      }

      // Write table columns file
      $new_tb_name = $post['name'];

      // Write table data file
      $this->cfg->setTable($post);


      $this->cfg->setFld($new_tb_name, 'id', [
        "name" => "id",
        "label" => "Id",
        "type" => "text",
        "readonly" => true,
        "db_type" => "INTEGER",
      ]);
      if ($post['is_plugin'] === '1') {
        $this->cfg->setFld($new_tb_name, 'table_link', [
          "name" => "table_link",
          "label" => "Linked table",
          "type" => "text",
          "db_type" => "TEXT",
          "hidden" => true,
        ]);
        $this->cfg->setFld($new_tb_name, 'id_link', [
          "name" => "id_link",
          "label" => "Linked id",
          "type" => "int",
          "db_type" => "INTEGER",
          "hidden" => true,
        ]);
      } else {
        $this->cfg->setFld($new_tb_name, 'creator', [
          "name" => "creator",
          "label" => "Creator",
          "type" => "text",
          "db_type" => "INTEGER",
          "readonly" => true,
        ]);
      }

      // Add table to database
      $alter = new Alter($this->db);
      $alter->createMinimalTable($new_tb_name, ($post['is_plugin'] === '1'));

      $this->response('ok_cfg_data_updated', 'success', null, [
        'tb' => $new_tb_name
      ]);
    } catch (\Throwable $e) {
      $this->log->error($e);
      $this->response('error_cfg_data_updated', 'error');
    }
  }


  public function save_fld_properties()
  {
    $post = $this->post;
    try {
      $post = \utils::recursiveFilter($post);

      $tb = $post['tb_name'];
      $fld = $post['fld_orig_name'];
      unset($post['tb_name']);
      unset($post['fld_orig_name']);

      if (!$post['name'] || !$post['type']) {
        throw new \Exception('Both field name and field type are required');
      }

      $this->cfg->setFld($tb, $fld, $post);

      $this->response('ok_cfg_data_updated', 'success');
    } catch (\Throwable $e) {
      $this->log->error($e);
      $this->response('error_cfg_data_updated', 'error');
    }
  }

  public function add_new_fld()
  {
    $post = $this->post;
    try {
      $post = \utils::recursiveFilter($post);

      $tb = $post['tb_name'];
      $fld = $post['name'];
      unset($post['tb_name']);

      if (!$post['name'] || !$post['type']) {
        throw new \Exception('Both field name and field type are required');
      }
      $available_flds = array_values($this->cfg->get("tables.$tb.fields.*.name"));
      if (in_array($fld, $available_flds)) {
        $this->response('fld_already_available', 'error', [$fld]);
        return;
      }

      $this->cfg->setFld($tb, $fld, $post);

      $alter = new Alter($this->db);
      $alter->addFld($tb, $fld, $post['db_type']);

      $this->response('ok_cfg_data_updated', 'success', null, ["fld" => $fld]);
    } catch (\Throwable $e) {
      $this->log->error($e);
      $this->response('error_cfg_data_updated', 'error');
    }
  }

  public function save_app_properties()
  {
    $data = $this->post;
    try {
      $this->cfg->setMain($data);
      $this->response('ok_cfg_data_updated', 'success');
    } catch (\Throwable $e) {
      $this->response('error_cfg_data_updated', 'error');
    }
  }


  public function delete_tb()
  {
    $tb = $this->get['tb'];
    try {
      $this->cfg->deleteTb($tb);
      // Drop table from database
      $alter = new Alter($this->db);
      $alter->dropTable($tb);
      $this->response('ok_cfg_tb_delete', 'success');
    } catch (\Throwable $th) {
      $this->response('error_cfg_tb_delete', 'error');
    }
  }


  public function delete_column()
  {
    $tb = $this->get['tb'];
    $fld = $this->get['fld'];

    try {
      $this->cfg->deleteFld($tb, $fld);

      $alter = new Alter($this->db);
      $alter->dropFld($tb, $fld);

      $this->response('ok_cfg_column_delete', 'success');
    } catch (\Throwable $th) {
      $this->response('error_cfg_clumn_delete', 'error');
    }
  }

  public function rename_tb()
  {
    $old_name = $this->get['old_name'];
    $new_name = $this->get['new_name'];
    try {
      $available_tbs = array_values($this->cfg->get('tables.*.name'));
      if (in_array($new_name, $available_tbs)) {
        throw new \Exception("Table name $new_name has already been used");
      }

      $this->cfg->renameTb($old_name, $new_name);

      $alter = new Alter($this->db);
      $alter->renameTable($old_name, $new_name);

      $this->response('ok_renaming_table', 'success');
    } catch (\Throwable $e) {
      $this->log->error($e);
      $this->response('error_renaming_table', 'error');
    }
  }

  public function rename_column()
  {
    $tb = $this->get['tb'];
    $old_name = $this->get['old_name'];
    $new_name = $this->get['new_name'];

    try {
      $available_flds = array_values($this->cfg->get("tables.$tb.fields.*.name"));
      if (in_array($new_name, $available_flds)) {
        throw new \Exception("Field name $new_name has already been used");
      }

      $this->cfg->renameFld($tb, $old_name, $new_name);

      $alter = new Alter($this->db);
      $type = $this->cfg->get("tables.$tb.fields.$old_name.db_type") ?: 'TEXT';
      $alter->renameFld($tb, $old_name, $new_name, $type);

      $this->response('ok_renaming_column', 'success');
    } catch (\Throwable $e) {
      $this->log->error($e);
      $this->response('error_renaming_column', 'error');
    }
  }


  public function validate_app()
  {
    $validate = new Validate($this->db, $this->prefix, $this->cfg);
    $report = $validate->all();

    $html = '<button type="button" class="btn btn-info pull-right" onclick="$(this).parent().find(\'.alert-info, .alert-success\').toggle();">' . \tr::get('show_only_errors') . '</button>';

    foreach ($report as $item) {
      if ($item['status'] === 'head') {
        $html .= '<h3>' . $item['text'] . '</h3>';
      } else {
        $html .= '<div class="alert alert-' . $item['status'] . '"> '
          . $item['text']
          . ($item['fix'] ? '<br><button class="btn btn-danger" onclick="config.fix(this, \'' . implode("', '", $item['fix']) . '\')">' . $item['suggest'] . '</button>' : '')
          . '</div>';
      }
    }

    echo $html;
  }

  public function fix()
  {
    $action = $this->get['action'];
    $tb = $this->get['tb'];
    $col = $this->get['col'];

    $sys_manage = new Manage($this->db, $this->prefix);

    // Create table: yes create, no col
    if ($action === 'create' && !$col) {
      try {
        $sys_manage->createTable($tb);
        $this->response('ok_creating_table', 'success');
      } catch (\Throwable $th) {
        $this->log->error($th);
        $this->response('error_creating_table', 'error');
      }
      return;
    }
    $alter = new Alter($this->db);

    // Add column: yes create, yes col
    if ($action === 'create' && $col) {
      $str = $sys_manage->getStructure(str_replace($this->prefix, '', $tb));
      $type = false;
      foreach ($str as $el) {
        if ($el['name'] === $col) {
          $type = $el['type'];
        }
      }
      if ($type) {
        $alter->addFld($tb, $col, $type);
        $this->response('ok_adding_column', 'success');
      } else {
        $this->response('col_type_not_found', 'error', [$tb, $col]);
      }
      return;

      // Drop table: yes delete, no col
    } else if ($action === 'delete' && !$col) {
      $alter->dropTable($tb);
      $this->response('ok_deleting_table', 'success');
      return;

      // Drop column: yes delete, yes column
    } else if ($action === 'delete' && $col) {
      $alter->dropFld($tb, $col);
      $this->response('ok_deleting_column', 'success');
      return;
    }
    $this->response('invalid_action', 'error', [$action]);
  }

  public function getFldList()
  {
    $tb = $this->get['tb'];
    $this->response('ok', 'success', null, ["fields" => $this->cfg->get("tables.$tb.fields.*.label")]);
  }

  public function sortTables()
  {
    $error = false;
    $sortArray = $this->get['sort'];
    $this->cfg->sortTables($sortArray);

    if ($this->cfg->sortTables($sortArray)) {
      $this->response('ok_sort_update', 'success');
    } else {
      $this->response('error_sort_update', 'error');
    }
  }

  public function geoface_properties()
  {
    $geodata_list = [];
    $datatypes = [
      "wms",
      "tiles",
      "local"
    ];
    
    $local_files = array_diff(\utils::dirContent(PROJ_DIR . 'geodata'), ["index.json"]);


    if (!file_exists(__DIR__ . 'index.json')){
      $geodata_list = json_decode( file_get_contents(PROJ_DIR . 'geodata/index.json'), TRUE);
    }
    array_push($geodata_list, [
      "label" => "",
      "type" => "",
      "path" => "",
      "layertype" => ""
    ]);

    $this->render('config', 'geoface_properties', [
      "geodata_list" => $geodata_list,
      "datatypes" => $datatypes,
      "local_files" => $local_files,
      "upload_dir" => PROJ_DIR . 'geodata'
    ]);
  }

  public function save_geoface_properties()
  {
    $data = $this->post;

    $json = json_encode(array_filter($data, function($el){
      return in_array($el['type'], ["wms", "local", "tiles"]) && !empty($el['path']);
    }), JSON_PRETTY_PRINT);

    try {
      file_put_contents(PROJ_DIR . 'geodata/index.json', $json);

      $this->response('ok_geoface_updated', 'success');
    } catch (\Throwable $th) {
      $this->response('error_geoface_updated', 'error');
    }
  }

  public function delete_local_geofile()
  {
    $file = PROJ_DIR . 'geodata/' . $this->get['file'];

    try {
      @unlink($file);

      if (file_exists($file)){
        throw new Exception("File $file not deleted");
      }

      $this->response('ok_geoface_updated', 'success');
    } catch (\Throwable $th) {
      $this->response('error_geoface_updated', 'error');
    }
  }

}
