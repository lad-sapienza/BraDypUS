<?php

/**
 * @copyright 2007-2022 Julian Bogdani
 * @license AGPL-3.0; see LICENSE
 */

use DB\Engines\AvailableEngines;
use DB\Validate\Validate;
use DB\System\Manage;
use \DB\Alter;

class config_ctrl extends Controller
{
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

  /**
   * Guards the method against non-super_admin callers.
   * Returns false and emits a JSON error if access is denied.
   */
  private function requireSuperAdmin(): bool
  {
    if (!\utils::canUser('super_admin')) {
      $this->returnJson(['status' => 'error', 'code' => 'not_enough_privilege']);
      return false;
    }
    return true;
  }

  public function save_tb_data()
  {
    if (!$this->requireSuperAdmin()) return;
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
    if (!$this->requireSuperAdmin()) return;
    $post = $this->post;

    try {
      $post = \utils::recursiveFilter($post);

      if ($post['is_plugin'] === '1') {
        $missing = $this->check_required($post, ['name', 'label']);
        if (!empty($missing)) {
          throw new \Exception("Required field(s):  " . implode(', ', $missing) . " are missing");
        }
      } else if (!$post['is_plugin'] || $post['is_plugin'] == 0) {
        $missing = $this->check_required($post, ['name', 'label']);
        if (!empty($missing)) {
          throw new \Exception("Required field(s):  " . implode(', ', $missing) . " are missing");
        }
        // Default layout fields to 'id' — the only field guaranteed to exist
        // on a brand-new table. The user can update them once real fields are added.
        if (empty($post['order']))    { $post['order']    = 'id'; }
        if (empty($post['id_field'])) { $post['id_field'] = 'id'; }
        if (empty($post['preview']))  { $post['preview']  = ['id']; }
      }

      $new_tb_name = $post['name'];

      // Reject duplicate table names before touching the config or the DB.
      $existing = array_column($this->cfg->get('tables') ?? [], 'name');
      if (in_array($new_tb_name, $existing, true)) {
        $this->response('tb_already_available', 'error', [$new_tb_name]);
        return;
      }

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
          "type" => "text",
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
    if (!$this->requireSuperAdmin()) return;
    $post = $this->post;
    try {
      $post = \utils::recursiveFilter($post);

      // tb and fld come from URL path params (/api/config/table/{tb}/field/{fld})
      // No POST-body fallback: the API contract requires these in the URL.
      $tb  = $this->get['tb']  ?? null;
      $fld = $this->get['fld'] ?? null;
      unset($post['tb_name'], $post['fld_orig_name']);

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
    if (!$this->requireSuperAdmin()) return;
    $post = $this->post;
    try {
      $post = \utils::recursiveFilter($post);

      $tb  = $this->get['tb'] ?? null;
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
    if (!$this->requireSuperAdmin()) return;
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
    if (!$this->requireSuperAdmin()) return;
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
    if (!$this->requireSuperAdmin()) return;
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
    if (!$this->requireSuperAdmin()) return;
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
    if (!$this->requireSuperAdmin()) return;
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


  public function fix()
  {
    if (!$this->requireSuperAdmin()) return;
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
      $str = $sys_manage->getStructure($tb);
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
    if (!$this->requireSuperAdmin()) return;
    $tb = $this->get['tb'];
    $this->response('ok', 'success', null, ["fields" => $this->cfg->get("tables.$tb.fields.*.label")]);
  }

  public function sortTables()
  {
    if (!$this->requireSuperAdmin()) return;
    $sortArray = $this->post['sort'] ?? $this->get['sort'] ?? [];
    if ($this->cfg->sortTables($sortArray)) {
      $this->response('ok_sort_update', 'success');
    } else {
      $this->response('error_sort_update', 'error');
    }
  }

  public function save_geoface_properties()
  {
    if (!$this->requireSuperAdmin()) return;
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
    if (!$this->requireSuperAdmin()) return;
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

  /**
   * Upload a GeoJSON / KML / any geo-file to the geodata directory.
   * The file is stored under its original name (sanitised).
   *
   * POST multipart/form-data ?obj=config_ctrl&method=uploadGeoFile
   * Field name: "file"
   *
   * Response: { status, code, filename? }
   */
  public function uploadGeoFile(): void
  {
    if (!\utils::canUser('super_admin')) {
      $this->returnJson(['status' => 'error', 'code' => 'not_enough_privilege']);
      return;
    }

    if (empty($_FILES['file']) || $_FILES['file']['error'] !== UPLOAD_ERR_OK) {
      $this->returnJson(['status' => 'error', 'code' => 'error_geoface_updated']);
      return;
    }

    $original = basename($_FILES['file']['name']);
    // Sanitise: keep only alphanumerics, dashes, underscores, dots
    $safe     = preg_replace('/[^a-zA-Z0-9._\-]/', '_', $original);
    $dest     = PROJ_DIR . 'geodata/' . $safe;

    try {
      if (!move_uploaded_file($_FILES['file']['tmp_name'], $dest)) {
        throw new \RuntimeException("move_uploaded_file failed");
      }
      $this->returnJson(['status' => 'success', 'code' => 'ok_geoface_updated', 'filename' => $safe]);
    } catch (\Throwable $e) {
      $this->log->error($e);
      $this->returnJson(['status' => 'error', 'code' => 'error_geoface_updated']);
    }
  }

  // ══════════════════════════════════════════════════════════════════════════
  // v5 JSON endpoints — additive only; all existing methods left untouched
  // ══════════════════════════════════════════════════════════════════════════

  /**
   * Returns the sorted list of configured tables.
   *
   * GET ?obj=config_ctrl&method=getTableList
   *
   * Response: { status, tables: [{name, label, is_plugin}] }
   */
  public function getTableList(): void
  {
    if (!\utils::canUser('super_admin')) {
      $this->returnJson(['status' => 'error', 'code' => 'not_enough_privilege']);
      return;
    }

    $names  = $this->cfg->get('tables.*.name') ?: [];
    $tables = [];
    foreach ($names as $name) {
      $tables[] = [
        'name'      => $name,
        'label'     => $this->cfg->get("tables.$name.label") ?? $name,
        'is_plugin' => $this->cfg->get("tables.$name.is_plugin") ?: '0',
      ];
    }

    $this->returnJson(['status' => 'success', 'tables' => $tables]);
  }

  /**
   * Returns app-level settings, user list, DB engines and available languages.
   *
   * GET ?obj=config_ctrl&method=getAppProperties
   *
   * Response: { status, main, users, db_engines, langs, status_options }
   */
  public function getAppProperties(): void
  {
    if (!\utils::canUser('super_admin')) {
      $this->returnJson(['status' => 'error', 'code' => 'not_enough_privilege']);
      return;
    }

    $users = [];
    try {
      $sys_manage = new Manage($this->db, $this->prefix);
      $rows = $sys_manage->getBySQL('users', '1=1');
      foreach ($rows as $u) {
        $u['verbose_privilege'] = \utils::privilege($u['privilege'], 1);
        $users[] = $u;
      }
    } catch (\Throwable $e) {
      // no users table in test / fresh install — return empty list
    }

    $this->returnJson([
      'status'         => 'success',
      'main'           => $this->cfg->get('main'),
      'users'          => $users,
      // array_values() re-indexes to 0-based so PHP encodes these as JSON arrays, not objects
      'db_engines'     => array_values(AvailableEngines::getList()),
      'status_options' => ['on', 'frozen', 'off'],
    ]);
  }

  /**
   * Returns full config for one table (or empty defaults for "add new").
   *
   * GET ?obj=config_ctrl&method=getTableConfig[&tb=TABLE_NAME]
   *
   * Response: { status, table, field_labels, available_plugins, available_tables }
   */
  public function getTableConfig(): void
  {
    if (!\utils::canUser('super_admin')) {
      $this->returnJson(['status' => 'error', 'code' => 'not_enough_privilege']);
      return;
    }

    $tb    = $this->get['tb'] ?? '';
    $table = $tb ? ($this->cfg->get("tables.$tb") ?: []) : [];

    // Apply same defaults as the legacy table_properties() Twig method
    if (!isset($table['name']))    $table['name']    = '';
    if (!isset($table['preview'])) $table['preview'] = [''];
    if (!isset($table['plugin']))  $table['plugin']  = [''];
    if (!isset($table['link']))    $table['link']    = [['fld' => [[]]]];

    // Enrich each link entry with the field labels of the linked table
    foreach ($table['link'] as &$link) {
      if (!empty($link['other_tb'])) {
        $link['other_fields'] = $this->cfg->get("tables.{$link['other_tb']}.fields.*.label") ?: [];
      }
    }
    unset($link);

    // Build field_labels as {fieldName: label} so JS gets a predictable associative object.
    // cfg->get("tables.$tb.fields.*.label") returns a numerically-keyed array which
    // JSON-encodes as an array — useless for dropdowns that need field names as values.
    $fieldLabels = ['id' => 'id'];
    if ($tb) {
      foreach ($this->cfg->get("tables.$tb.fields") ?: [] as $fld) {
        if (!empty($fld['name'])) {
          $fieldLabels[$fld['name']] = $fld['label'] ?? $fld['name'];
        }
      }
    }

    // available_plugins and available_tables: cfg->get returns {tableName: label} keyed
    // by table name — that's already the right format for option dropdowns in the frontend.
    $this->returnJson([
      'status'            => 'success',
      'table'             => $table,
      'field_labels'      => $fieldLabels,
      'available_plugins' => $this->cfg->get('tables.*.label', 'is_plugin', '1') ?: [],
      'available_tables'  => $this->cfg->get('tables.*.label') ?: [],
    ]);
  }

  /**
   * Returns the field meta-schema (fld_structure.json) with vocabulary names
   * and table names injected in place of the placeholder strings.
   *
   * GET ?obj=config_ctrl&method=getFldStructure
   *
   * Response: { status, structure: { ... } }
   */
  public function getFldStructure(): void
  {
    if (!\utils::canUser('super_admin')) {
      $this->returnJson(['status' => 'error', 'code' => 'not_enough_privilege']);
      return;
    }

    $this->returnJson(['status' => 'success', 'structure' => $this->buildFldStructure()]);
  }

  /**
   * Returns the config data for one field together with the field meta-schema.
   *
   * GET ?obj=config_ctrl&method=getFldConfig&tb=TABLE&fld=FIELD
   * (fld may be omitted for "add new" — returns empty field data)
   *
   * Response: { status, field, structure }
   */
  public function getFldConfig(): void
  {
    if (!\utils::canUser('super_admin')) {
      $this->returnJson(['status' => 'error', 'code' => 'not_enough_privilege']);
      return;
    }

    $tb  = $this->get['tb']  ?? '';
    $fld = $this->get['fld'] ?? '';

    $field = ($tb && $fld) ? ($this->cfg->get("tables.$tb.fields.$fld") ?: []) : [];

    $this->returnJson([
      'status'    => 'success',
      'field'     => $field,
      'structure' => $this->buildFldStructure(),
    ]);
  }

  /**
   * Returns the geoface layer list and the list of locally stored geo-files.
   *
   * GET ?obj=config_ctrl&method=getGeoFaceConfig
   *
   * Response: { status, layers: [...], local_files: [...] }
   */
  public function getGeoFaceConfig(): void
  {
    if (!\utils::canUser('super_admin')) {
      $this->returnJson(['status' => 'error', 'code' => 'not_enough_privilege']);
      return;
    }

    $layers    = [];
    $indexFile = PROJ_DIR . 'geodata/index.json';
    if (file_exists($indexFile)) {
      $layers = json_decode(file_get_contents($indexFile), true) ?: [];
    }

    $localFiles = array_values(array_diff(
      \utils::dirContent(PROJ_DIR . 'geodata') ?: [],
      ['index.json']
    ));

    $this->returnJson([
      'status'      => 'success',
      'layers'      => $layers,
      'local_files' => $localFiles,
    ]);
  }

  /**
   * Runs the full DB↔config validation and returns a structured report.
   * This is the v5 equivalent of validate_app() which returns raw HTML.
   *
   * GET ?obj=config_ctrl&method=getValidationReport
   *
   * Response: { status, report: [{status, text, suggest?, fix?}] }
   */
  public function getValidationReport(): void
  {
    if (!\utils::canUser('super_admin')) {
      $this->returnJson(['status' => 'error', 'code' => 'not_enough_privilege']);
      return;
    }

    try {
      $validate = new Validate($this->db, $this->prefix, $this->cfg);
      $this->returnJson(['status' => 'success', 'report' => $validate->all()]);
    } catch (\Throwable $e) {
      $this->log->error($e);
      $this->returnJson(['status' => 'error', 'code' => 'error_validation', 'detail' => $e->getMessage()]);
    }
  }

  // ── Private helpers ───────────────────────────────────────────────────────

  /**
   * Builds the field meta-schema array from fld_structure.json,
   * injecting live vocabulary names and configured table names.
   */
  private function buildFldStructure(): array
  {
    $allVoc = [];
    try {
      $sys_manage = new Manage($this->db, $this->prefix);
      $rows = $sys_manage->getBySQL('vocabularies', '1=1 GROUP BY voc', [], ['voc']);
      $allVoc = array_column($rows, 'voc');
    } catch (\Throwable $e) {
      // no vocabularies table (fresh install / test env) — leave list empty
    }

    $tableNames = array_values($this->cfg->get('tables.*.name') ?: []);

    $raw = file_get_contents(__DIR__ . '/fld_structure.json');
    $raw = str_replace(
      ['list-of-system-defined-vocabularies-here', 'list-of-available-tables-here'],
      [implode('","', $allVoc), implode('","', $tableNames)],
      $raw
    );

    return json_decode($raw, true) ?? [];
  }
}
