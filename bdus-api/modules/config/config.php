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
    if (!\Auth\Authorization::can('super_admin')) {
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

      $post = $this->filterPost($post);

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

      $this->returnJson(['status' => 'success', 'code' => 'ok_cfg_data_updated']);
    } catch (\Throwable $e) {
      $this->log->error($e);
      $this->returnJson(['status' => 'error', 'code' => 'error_cfg_data_updated']);
    }
  }


  public function add_new_tb()
  {
    if (!$this->requireSuperAdmin()) return;
    $post = $this->post;

    try {
      $post = $this->filterPost($post);

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
        $this->returnJson(['status' => 'error', 'code' => 'tb_already_available']);
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

      $this->returnJson(['status' => 'success', 'code' => 'ok_cfg_data_updated', 'tb' => $new_tb_name]);
    } catch (\Throwable $e) {
      $this->log->error($e);
      $this->returnJson(['status' => 'error', 'code' => 'error_cfg_data_updated']);
    }
  }


  public function save_fld_properties()
  {
    if (!$this->requireSuperAdmin()) return;
    $post = $this->post;
    try {
      $post = $this->filterPost($post);

      // tb and fld come from URL path params (/api/config/table/{tb}/field/{fld})
      // No POST-body fallback: the API contract requires these in the URL.
      $tb  = $this->get['tb']  ?? null;
      $fld = $this->get['fld'] ?? null;
      unset($post['tb_name'], $post['fld_orig_name']);

      if (!$post['name'] || !$post['type']) {
        throw new \Exception('Both field name and field type are required');
      }

      $this->cfg->setFld($tb, $fld, $post);

      $this->returnJson(['status' => 'success', 'code' => 'ok_cfg_data_updated']);
    } catch (\Throwable $e) {
      $this->log->error($e);
      $this->returnJson(['status' => 'error', 'code' => 'error_cfg_data_updated']);
    }
  }

  public function add_new_fld()
  {
    if (!$this->requireSuperAdmin()) return;
    $post = $this->post;
    try {
      $post = $this->filterPost($post);

      $tb  = $this->get['tb'] ?? null;
      $fld = $post['name'];
      unset($post['tb_name']);

      if (!$post['name'] || !$post['type']) {
        throw new \Exception('Both field name and field type are required');
      }
      $available_flds = array_values($this->cfg->get("tables.$tb.fields.*.name"));
      if (in_array($fld, $available_flds)) {
        $this->returnJson(['status' => 'error', 'code' => 'fld_already_available']);
        return;
      }

      $this->cfg->setFld($tb, $fld, $post);

      $alter = new Alter($this->db);
      $alter->addFld($tb, $fld, $post['db_type']);

      $this->returnJson(['status' => 'success', 'code' => 'ok_cfg_data_updated', 'fld' => $fld]);
    } catch (\Throwable $e) {
      $this->log->error($e);
      $this->returnJson(['status' => 'error', 'code' => 'error_cfg_data_updated']);
    }
  }

  public function save_app_properties()
  {
    if (!$this->requireSuperAdmin()) return;
    $data = $this->post;
    try {
      $this->cfg->setMain($data);
      $this->returnJson(['status' => 'success', 'code' => 'ok_cfg_data_updated']);
    } catch (\Throwable $e) {
      $this->returnJson(['status' => 'error', 'code' => 'error_cfg_data_updated']);
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
      $this->returnJson(['status' => 'success', 'code' => 'ok_cfg_tb_delete']);
    } catch (\Throwable $th) {
      $this->returnJson(['status' => 'error', 'code' => 'error_cfg_tb_delete']);
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

      $this->returnJson(['status' => 'success', 'code' => 'ok_cfg_column_delete']);
    } catch (\Throwable $th) {
      $this->returnJson(['status' => 'error', 'code' => 'error_cfg_clumn_delete']);
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

      $this->returnJson(['status' => 'success', 'code' => 'ok_renaming_table']);
    } catch (\Throwable $e) {
      $this->log->error($e);
      $this->returnJson(['status' => 'error', 'code' => 'error_renaming_table']);
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

      $this->returnJson(['status' => 'success', 'code' => 'ok_renaming_column']);
    } catch (\Throwable $e) {
      $this->log->error($e);
      $this->returnJson(['status' => 'error', 'code' => 'error_renaming_column']);
    }
  }


  public function fix()
  {
    if (!$this->requireSuperAdmin()) return;
    $action = $this->get['action'];
    $tb = $this->get['tb'];
    $col = $this->get['col'];

    $sys_manage = new Manage($this->db);

    // Create table: yes create, no col
    if ($action === 'create' && !$col) {
      try {
        $sys_manage->createTable($tb);
        $this->returnJson(['status' => 'success', 'code' => 'ok_creating_table']);
      } catch (\Throwable $th) {
        $this->log->error($th);
        $this->returnJson(['status' => 'error', 'code' => 'error_creating_table']);
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
        $this->returnJson(['status' => 'success', 'code' => 'ok_adding_column']);
      } else {
        $this->returnJson(['status' => 'error', 'code' => 'col_type_not_found']);
      }
      return;

      // Drop table: yes delete, no col
    } else if ($action === 'delete' && !$col) {
      $alter->dropTable($tb);
      $this->returnJson(['status' => 'success', 'code' => 'ok_deleting_table']);
      return;

      // Drop column: yes delete, yes column
    } else if ($action === 'delete' && $col) {
      $alter->dropFld($tb, $col);
      $this->returnJson(['status' => 'success', 'code' => 'ok_deleting_column']);
      return;
    }
    $this->returnJson(['status' => 'error', 'code' => 'invalid_action']);
  }

  public function getFldList()
  {
    if (!$this->requireSuperAdmin()) return;
    $tb = $this->get['tb'];
    $this->returnJson(['status' => 'success', 'code' => 'ok', 'fields' => $this->cfg->get("tables.$tb.fields.*.label")]);
  }

  public function sortTables()
  {
    if (!$this->requireSuperAdmin()) return;
    $sortArray = $this->post['sort'] ?? $this->get['sort'] ?? [];
    if ($this->cfg->sortTables($sortArray)) {
      $this->returnJson(['status' => 'success', 'code' => 'ok_sort_update']);
    } else {
      $this->returnJson(['status' => 'error', 'code' => 'error_sort_update']);
    }
  }

  public function save_geoface_properties()
  {
    if (!$this->requireSuperAdmin()) return;

    // Filter: keep only layers with a known type and a non-empty path.
    $layers = array_values(array_filter($this->post, function($el){
      return is_array($el)
        && in_array($el['type'] ?? '', ["wms", "local", "tiles", "maplibre_style"], true)
        && !empty($el['path']);
    }));

    try {
      $ok = \Config\GeofaceConfig::saveLayers($this->db, $layers);
      if ($ok) {
        $this->returnJson(['status' => 'success', 'code' => 'ok_geoface_updated']);
      } else {
        $this->returnJson(['status' => 'error', 'code' => 'error_geoface_updated']);
      }
    } catch (\Throwable $th) {
      $this->returnJson(['status' => 'error', 'code' => 'error_geoface_updated']);
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

      $this->returnJson(['status' => 'success', 'code' => 'ok_geoface_updated']);
    } catch (\Throwable $th) {
      $this->returnJson(['status' => 'error', 'code' => 'error_geoface_updated']);
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
    if (!\Auth\Authorization::can('super_admin')) {
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
    if (!\Auth\Authorization::can('super_admin')) {
      $this->returnJson(['status' => 'error', 'code' => 'not_enough_privilege']);
      return;
    }

    $names  = $this->cfg->get('tables.*.name') ?: [];
    $tables = [];
    foreach ($names as $name) {
      // Skip built-in system tables (bdus_files, bdus_geodata, …) — they live
      // in bdus_cfg_tables for validation purposes but must not appear in the
      // user-facing config UI.
      if (str_starts_with($name, 'bdus_')) continue;

      $tables[] = [
        'name'      => $name,
        'label'     => $this->cfg->get("tables.$name.label") ?? $name,
        // Always return a string so the frontend === '1' comparison is reliable
        // regardless of whether the config came from JSON ('0'/'1') or DB (bool).
        'is_plugin' => (bool)$this->cfg->get("tables.$name.is_plugin") ? '1' : '0',
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
    if (!\Auth\Authorization::can('super_admin')) {
      $this->returnJson(['status' => 'error', 'code' => 'not_enough_privilege']);
      return;
    }

    $users = [];
    try {
      $sys_manage = new Manage($this->db);
      $rows = $sys_manage->getBySQL('bdus_users', '1=1');
      foreach ($rows as $u) {
        $u['verbose_privilege'] = \Auth\Authorization::privilege($u['privilege'], 1);
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
    if (!\Auth\Authorization::can('super_admin')) {
      $this->returnJson(['status' => 'error', 'code' => 'not_enough_privilege']);
      return;
    }

    $tb    = $this->get['tb'] ?? '';

    // Refuse to expose system tables through the config UI.
    if ($tb && str_starts_with($tb, 'bdus_')) {
      $this->returnJson(['status' => 'error', 'code' => 'not_found']);
      return;
    }

    $table = $tb ? ($this->cfg->get("tables.$tb") ?: []) : [];

    // Apply same defaults as the legacy table_properties() Twig method
    if (!isset($table['name']))    $table['name']    = '';
    if (!isset($table['preview'])) $table['preview'] = [''];
    if (!isset($table['plugin']))  $table['plugin']  = [''];
    if (!isset($table['link']))    $table['link']    = [['fld' => [[]]]];

    // Normalise is_plugin to '' (no) or '1' (yes) so the frontend <Select>
    // whose options are [{value:''}, {value:'1'}] always has a matching entry,
    // regardless of whether the config came from JSON ('0'/'1') or DB (bool).
    $table['is_plugin'] = ($table['is_plugin'] ?? false) ? '1' : '';

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
    if (!\Auth\Authorization::can('super_admin')) {
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
    if (!\Auth\Authorization::can('super_admin')) {
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
    if (!\Auth\Authorization::can('super_admin')) {
      $this->returnJson(['status' => 'error', 'code' => 'not_enough_privilege']);
      return;
    }

    $layers = \Config\GeofaceConfig::getLayers($this->db);

    $localFiles = defined('PROJ_DIR')
      ? array_values(array_diff(\utils::dirContent(PROJ_DIR . 'geodata') ?: [], ['index.json']))
      : [];

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
    if (!\Auth\Authorization::can('super_admin')) {
      $this->returnJson(['status' => 'error', 'code' => 'not_enough_privilege']);
      return;
    }

    try {
      $validate = new Validate($this->db, $this->cfg);
      $this->returnJson(['status' => 'success', 'report' => $validate->all()]);
    } catch (\Throwable $e) {
      $this->log->error($e);
      $this->returnJson(['status' => 'error', 'code' => 'error_validation', 'detail' => $e->getMessage()]);
    }
  }

  // ── Relations (dedicated panel) ──────────────────────────────────────────

  /**
   * Returns all relations with table labels.
   *
   * GET /api/config/relations
   *
   * Response: { status, code, data: [ {id, from_tb, from_label, to_tb, to_label, fld[]} ] }
   */
  public function getRelations(): void
  {
    if (!$this->requireSuperAdmin()) return;

    try {
      $rows = $this->db->query(
        'SELECT r.id, r.from_tb, r.to_tb, r.fld, r.sort,
                tf.label AS from_label, tt.label AS to_label
           FROM bdus_cfg_relations r
      LEFT JOIN bdus_cfg_tables tf ON tf.name = r.from_tb
      LEFT JOIN bdus_cfg_tables tt ON tt.name = r.to_tb
          ORDER BY r.from_tb ASC, r.sort ASC, r.id ASC',
        [],
        'read'
      ) ?: [];

      $data = array_map(static function ($r) {
        return [
          'id'         => (int) $r['id'],
          'from_tb'    => $r['from_tb'],
          'from_label' => $r['from_label'] ?? $r['from_tb'],
          'to_tb'      => $r['to_tb'],
          'to_label'   => $r['to_label']   ?? $r['to_tb'],
          'fld'        => $r['fld'] ? (json_decode($r['fld'], true) ?: []) : [],
        ];
      }, $rows);

      $this->returnJson(['status' => 'success', 'code' => 'relations', 'data' => $data]);
    } catch (\Throwable $e) {
      $this->log->error($e);
      $this->returnJson(['status' => 'error', 'code' => 'db_error', 'detail' => $e->getMessage()]);
    }
  }

  /**
   * Creates or updates a single relation.
   *
   * POST /api/config/relations        → create (no id in body)
   * PUT  /api/config/relations/{id}   → update existing row
   *
   * Body: { from_tb, to_tb, fld: [{my, other}, …] }
   * Response: { status, code, id }
   */
  public function saveRelation(): void
  {
    if (!$this->requireSuperAdmin()) return;

    $id     = isset($this->get['id'])  ? (int) $this->get['id']  : null;
    $fromTb = trim($this->post['from_tb'] ?? '');
    $toTb   = trim($this->post['to_tb']   ?? '');
    $fld    = $this->post['fld'] ?? [];

    if (!$fromTb || !$toTb) {
      $this->returnJson(['status' => 'error', 'code' => 'parameter_missing']);
      return;
    }
    if ($fromTb === $toTb) {
      $this->returnJson(['status' => 'error', 'code' => 'relation_self_loop']);
      return;
    }

    // Normalise: always store the alphabetically-first table as from_tb so
    // the UNIQUE(from_tb, to_tb) index is never violated by reverse pairs.
    if ($fromTb > $toTb) {
      [$fromTb, $toTb] = [$toTb, $fromTb];
      $fld = array_map(
        static fn($p) => ['my' => $p['other'] ?? '', 'other' => $p['my'] ?? ''],
        (array) $fld
      );
    }

    $fldJson = json_encode(array_values((array) $fld), JSON_UNESCAPED_UNICODE);

    try {
      if ($id) {
        $this->db->query(
          'UPDATE bdus_cfg_relations SET from_tb=?, to_tb=?, fld=? WHERE id=?',
          [$fromTb, $toTb, $fldJson, $id],
          'boolean'
        );
        $this->returnJson(['status' => 'success', 'code' => 'relation_saved', 'id' => $id]);
      } else {
        // Reject if the canonical pair already exists.
        $existing = $this->db->query(
          'SELECT id FROM bdus_cfg_relations WHERE from_tb=? AND to_tb=?',
          [$fromTb, $toTb],
          'read'
        );
        if (!empty($existing)) {
          $this->returnJson(['status' => 'error', 'code' => 'relation_already_exists']);
          return;
        }
        $newId = $this->db->query(
          'INSERT INTO bdus_cfg_relations (from_tb, to_tb, fld, sort) VALUES (?,?,?,0)',
          [$fromTb, $toTb, $fldJson],
          'id'
        );
        $this->returnJson(['status' => 'success', 'code' => 'relation_saved', 'id' => (int) $newId]);
      }
    } catch (\Throwable $e) {
      $this->log->error($e);
      $this->returnJson(['status' => 'error', 'code' => 'db_error', 'detail' => $e->getMessage()]);
    }
  }

  /**
   * Deletes a relation by id.
   *
   * DELETE /api/config/relations/{id}
   * Response: { status, code }
   */
  public function deleteRelation(): void
  {
    if (!$this->requireSuperAdmin()) return;

    $id = (int) ($this->get['id'] ?? 0);
    if (!$id) {
      $this->returnJson(['status' => 'error', 'code' => 'parameter_missing']);
      return;
    }

    try {
      $affected = $this->db->query(
        'DELETE FROM bdus_cfg_relations WHERE id=?',
        [$id],
        'affected'
      );
      if ($affected > 0) {
        $this->returnJson(['status' => 'success', 'code' => 'relation_deleted']);
      } else {
        $this->returnJson(['status' => 'error', 'code' => 'not_found']);
      }
    } catch (\Throwable $e) {
      $this->log->error($e);
      $this->returnJson(['status' => 'error', 'code' => 'db_error', 'detail' => $e->getMessage()]);
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
      $sys_manage = new Manage($this->db);
      $rows = $sys_manage->getBySQL('bdus_vocabularies', '1=1 GROUP BY voc', [], ['voc']);
      $allVoc = array_column($rows, 'voc');
    } catch (\Throwable $e) {
      // no vocabularies table (fresh install / test env) — leave list empty
    }

    $tableNames = array_values($this->cfg->get('tables.*.name') ?: []);

    // Scan per-app widget files so the dropdown stays in sync automatically.
    $widgetNames = [];
    $widgetDir   = defined('PROJ_DIR') ? PROJ_DIR . 'widgets/' : null;
    if ($widgetDir && is_dir($widgetDir)) {
      foreach (glob($widgetDir . '*.js') as $file) {
        $name = pathinfo($file, PATHINFO_FILENAME);
        if (preg_match('/^[a-z0-9\-]+$/', $name)) {
          $widgetNames[] = $name;
        }
      }
      sort($widgetNames);
    }

    $raw = file_get_contents(__DIR__ . '/fld_structure.json');
    $raw = str_replace(
      [
        'list-of-system-defined-vocabularies-here',
        'list-of-available-tables-here',
        'list-of-available-widgets-here',
      ],
      [
        implode('","', $allVoc),
        implode('","', $tableNames),
        implode('","', $widgetNames),
      ],
      $raw
    );

    return json_decode($raw, true) ?? [];
  }

  /**
   * Recursively trims string values and removes null/empty elements from an array.
   */
  private function filterPost(array $arr, ?callable $callback = null): array
  {
    foreach ($arr as &$a) {
      $a = is_array($a) ? $this->filterPost($a, $callback) : trim($a);
    }
    return is_callable($callback) ? array_filter($arr, $callback) : array_filter($arr);
  }
}
