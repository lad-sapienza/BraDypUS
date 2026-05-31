<?php

namespace Bdus\Controllers;

/**
 * @copyright 2007-2022 Julian Bogdani
 * @license AGPL-3.0; see LICENSE
 * @since			Jan 10, 2013
 */

use \Record\Read;

class Record extends \Bdus\Controller
{
  /**
   * Returns a paginated JSON list of records for a given table.
   * Used by the Vue data module.
   *
   * GET ?obj=record_ctrl&method=getRecords&tb=TABLE&page=1&per_page=30
   *     &sort_field=FIELD&sort_dir=asc|desc&search=STRING
   *
   * Response:
   * {
   *   total:  int,
   *   fields: [ { name: string, label: string }, ... ],
   *   data:   [ { id, field1, field2, ... }, ... ]
   * }
   */
  public function getRecords(): void
  {
    if (!\Auth\Authorization::can('read')) {
      $this->returnJson(['status' => 'error', 'code' => 'not_enough_privilege']);
      return;
    }

    $tb = $this->get['tb'] ?? null;
    if (!$tb) {
      $this->returnJson(['status' => 'error', 'code' => 'parameter_missing']);
      return;
    }

    // GET params (fast search / pagination / sort)
    $page       = max(1, (int)($this->get['page']       ?? $this->post['page']       ?? 1));
    $perPage    = min(200, max(1, (int)($this->get['per_page']  ?? $this->post['per_page']  ?? 30)));
    $sortFld    = $this->get['sort_field'] ?? $this->post['sort_field'] ?? null;
    $sortDir    = (($this->get['sort_dir'] ?? $this->post['sort_dir'] ?? 'asc') === 'desc') ? 'desc' : 'asc';
    $searchType = $this->get['search_type'] ?? $this->post['search_type'] ?? null;

    // Build QueryFromRequest-compatible request array.
    // Auto-detect search type when search_type is not provided explicitly.
    $qRequest = ['tb' => $tb, 'type' => $searchType ?? 'all'];

    // ── JSON filter (Directus-style) — highest priority ───────────────────────
    // GET  ?filter[status][_eq]=active  (bracket notation — PHP parses to array)
    // GET  ?filter=BASE64_JSON          (base64 string — decoded below)
    // POST { "filter": { "status": { "_eq": "active" } } }
    $filterRaw = $this->get['filter'] ?? $this->post['filter'] ?? null;
    if (is_string($filterRaw)) {
      $filterRaw = json_decode($filterRaw, true);
    }
    if (is_array($filterRaw)) {
      $qRequest['type']   = 'filter';
      $qRequest['filter'] = $filterRaw;
    } elseif ($searchType) {
      switch ($searchType) {
        case 'fast':
          $qRequest['string'] = $this->get['search'] ?? $this->post['search'] ?? '';
          break;
        case 'sqlExpert':
          $qRequest['querytext'] = $this->post['querytext'] ?? $this->get['querytext'] ?? '';
          $qRequest['join']      = $this->post['join']      ?? $this->get['join']      ?? '';
          break;
        default:
          $qRequest['type'] = 'all';
      }
    } else {
      // Check for q_fieldname=value GET params (simple field equality filter).
      // e.g. ?q_sigla=US001 → filter[sigla][_eq]=US001
      $qFilter = [];
      foreach ($this->get as $key => $val) {
        if (str_starts_with($key, 'q_') && $val !== '' && $val !== null) {
          $qFilter[substr($key, 2)] = ['_eq' => $val];
        }
      }
      if (!empty($qFilter)) {
        $qRequest['type']   = 'filter';
        $qRequest['filter'] = $qFilter;
      }
    }

    // Optional: custom column list from the frontend column-visibility toggler.
    // If provided, override the default preview fields for this query.
    //
    // GET requests send columns as a comma-separated string (e.g. "cmclid,tm,creator")
    // to avoid URL array-encoding issues with columns[]=…
    //
    // POST requests (advanced/expert search) send columns as a JSON array.
    // Both formats are accepted here.
    $colsRaw = $this->get['columns'] ?? $this->post['columns'] ?? null;
    $customColumns = null;
    if ($colsRaw) {
      if (is_string($colsRaw)) {
        $customColumns = array_values(array_filter(explode(',', $colsRaw)));
      } elseif (is_array($colsRaw)) {
        $customColumns = $colsRaw;
      }
    }
    if ($customColumns && count($customColumns) > 0) {
      // Build associative [fieldName => label] map, always prepend id.
      $colMap = [];
      foreach ($customColumns as $col) {
        $col = preg_replace('/[^a-zA-Z0-9_]/', '', $col);  // sanitise
        if (!$col || $col === 'id') continue;
        $allFields = $this->cfg->get("tables.{$tb}.fields.*") ?? [];
        $label = $allFields[$col]['label'] ?? $col;
        $colMap[$col] = $label;
      }
      $colMap = array_merge(['id' => 'id'], $colMap);
      $qRequest['fields'] = $colMap;
    }

    // use_preview=true unless we are supplying a custom column list
    $usePreview = !isset($qRequest['fields']);

    try {
      $qObj = new \SQL\QueryFromRequest($this->db, $this->cfg, $qRequest, $usePreview);

      $total = $qObj->getTotal();

      // Build field list for column headers
      $rawFields = $qObj->getFields();
      $fields = [];
      foreach ($rawFields as $fldName => $fldLabel) {
        $fields[] = ['name' => $fldName, 'label' => $fldLabel ?: $fldName];
      }

      if ($sortFld) {
        $qObj->setOrder($sortFld, $sortDir);
      }

      $qObj->setLimit(($page - 1) * $perPage, $perPage);

      $this->returnJson([
        "status"  => "success",
        'total'   => $total,
        'fields'  => $fields,
        'data'    => $qObj->getResults(),
        'can_add' => \Auth\Authorization::can('add_new'),
      ]);

    } catch (\Throwable $e) {
      $this->log->error($e);
      // Surface the original DB engine message (e.g. "no such column: foo") so
      // the caller — especially in sqlExpert mode — gets an actionable error.
      $detail = $e->getMessage();
      if ($e->getPrevious()) {
        $detail .= ' — ' . $e->getPrevious()->getMessage();
      }
      $this->returnJson(['status' => 'error', 'code' => 'db_error', 'detail' => $detail]);
    }
  }

  /**
   * Exports all records matching the current search as a downloadable file.
   *
   * Accepts the same search parameters as getRecords() (search_type, search,
   * querytext, adv, where) but ignores pagination and streams the file directly
   * to the browser via Content-Disposition: attachment.
   *
   * The frontend builds the URL from its current route.query, so the parameters
   * mirror the URL-persistence format used by DataView:
   *
   * GET ?obj=record_ctrl&method=exportRecords
   *     &tb=TABLE
   *     &format=csv|json|xlsx
   *     &filter[field][_op]=value             (optional, Directus-style bracket notation)
   *     &filter=JSON_STRING                   (optional, URL-encoded JSON filter)
   *     &qt=fast|expert                       (optional — mirrors route.query.qt)
   *     &q=VALUE                              (optional — mirrors route.query.q)
   * (same encoding used by DataView when persisting filter state in the URL).
   */
  public function exportRecords(): void
  {
    if (!\Auth\Authorization::can('read')) {
      $this->returnJson(['status' => 'error', 'code' => 'not_enough_privilege']);
      return;
    }

    $tb     = $this->get['tb']     ?? null;
    $format = $this->get['format'] ?? 'csv';

    if (!$tb) {
      $this->returnJson(['status' => 'error', 'code' => 'parameter_missing']);
      return;
    }

    // Translate URL-persistence params (qt/q/where) into QueryFromRequest shape.
    // This mirrors the mapping that DataView::applyRouteParams does on the frontend.
    $qt    = $this->get['qt']    ?? null;
    $q     = $this->get['q']     ?? null;
    $where = $this->get['where'] ?? null;

    $qRequest = ['tb' => $tb, 'type' => 'all'];

    $filterRaw = $this->get['filter'] ?? null;
    if (is_string($filterRaw)) {
      $filterRaw = json_decode($filterRaw, true);
    }
    if (is_array($filterRaw)) {
      $qRequest['type']   = 'filter';
      $qRequest['filter'] = $filterRaw;
    } elseif ($qt === 'fast' && $q !== null) {
      $qRequest['type']   = 'fast';
      $qRequest['string'] = $q;
    } elseif ($qt === 'expert' && $q !== null) {
      $qRequest['type']      = 'sqlExpert';
      $qRequest['querytext'] = $q;
      $qRequest['join']      = '';
    }

    try {
      $qObj  = new \SQL\QueryFromRequest($this->db, $this->cfg, $qRequest, false);
      $rows  = $qObj->getResults();  // no setLimit() → full result set

      $metadata = [
        'table'  => $tb,
        'filter' => $qt ?? 'all',
        'query'  => $q ?? $where ?? null,
      ];

      $filename = preg_replace('/[^a-zA-Z0-9_\-]/', '_', $tb)
                . '_' . date('Ymd_His');

      $exp = \DB\Export\Export::fromData($rows ?: [], $metadata);
      $exp->streamToResponse($format, $filename);

    } catch (\Throwable $e) {
      $this->log->error($e);
      $this->returnJson(['status' => 'error', 'code' => 'db_error', 'detail' => $e->getMessage()]);
    }
  }

  // ── Vue v5 record view/edit API ──────────────────────────────────────────

  /**
   * Returns the full record data + schema, ready for Vue RecordView.
   *
   * GET ?obj=record_ctrl&method=getRecord&tb=TABLE&id=ID
   *     (omit id for add_new)
   *
   * Response:
   * {
   *   metadata: { tb_id, tb_label, rec_id, id_field, can_edit, can_delete },
   *   schema:   { fields: [{name, label, type, readonly, options_source, ...}], plugins: {...} },
   *   core:     { field: { name, label, val, val_label? }, ... },
   *   plugins:  { tb: { metadata, data } },
   *   links, backlinks, manualLinks, files, geodata, rs
   * }
   */
  public function getRecord(): void
  {
    if (!\Auth\Authorization::can('read')) {
      $this->returnJson(['status' => 'error', 'code' => 'not_enough_privilege']);
      return;
    }

    $tb = $this->get['tb'] ?? null;
    $id = isset($this->get['id']) ? (int)$this->get['id'] : null;

    if (!$tb) {
      $this->returnJson(['status' => 'error', 'code' => 'parameter_missing']);
      return;
    }

    try {
      $schema = $this->buildTableSchema($tb);

      if ($id) {
        $reader = new \Record\Read($id, null, $tb, $this->db, $this->cfg);
        $full   = $reader->getFull();
        // getFull() stores the full field object in metadata.rec_id — fix to int
        $recId  = $full['core']['id']['val'] ?? $id;
      } else {
        $full  = $this->buildEmptyRecord($tb, $schema);
        $recId = null;
      }

      $appName  = $this->cfg->get('main.name') ?? '';
      $tbPrefix = $appName !== '' ? $appName . '__' : '';
      $full['metadata']['tb_id']      = $tb;
      $full['metadata']['tb_stripped'] = ($tbPrefix !== '' && str_starts_with($tb, $tbPrefix))
                                            ? substr($tb, strlen($tbPrefix))
                                            : $tb;
      $full['metadata']['tb_label']   = $this->cfg->get("tables.{$tb}.label");
      $full['metadata']['rec_id']     = $recId;
      $full['metadata']['id_field']   = $this->cfg->get("tables.{$tb}.id_field");
      $full['metadata']['can_edit']   = \Auth\Authorization::can('edit');
      $full['metadata']['can_delete'] = \Auth\Authorization::can('edit');
      $full['schema'] = $schema;

      // Template loading
      $appName = $this->cfg->get('main.name') ?? '';
      $tplName = $this->get['template'] ?? null;
      if ($tplName) {
        $tpl = \Template\Loader::load($appName, $tb, $tplName);
        if ($tpl === null) {
          $full['schema']['template']        = null;
          $full['schema']['template_errors'] = ['template_not_found'];
        } else {
          $fieldNames  = array_column($this->cfg->get("tables.{$tb}.fields") ?: [], 'name');
          $pluginNames = $this->cfg->get("tables.{$tb}.plugin") ?: [];
          $errors      = \Template\Loader::validate($tpl, $fieldNames, $pluginNames);
          $full['schema']['template']        = $errors ? null : $tpl;
          $full['schema']['template_errors'] = $errors ?: null;
        }
      }

      // Mark each file with is_image so the frontend can pick the right renderer.
      // URL is reconstructed on the frontend using assetUrl() + app name from JWT.
      if (!empty($full['files']) && \is_array($full['files'])) {
        $imageExts = ['png', 'jpeg', 'jpg', 'bmp', 'ico', 'tif', 'tiff'];
        foreach ($full['files'] as &$file) {
          $file['is_image'] = \in_array(\strtolower($file['ext'] ?? ''), $imageExts, true);
        }
        unset($file);
      }
      $full["status"] = "success";

      $this->returnJson($full);

    } catch (\Throwable $e) {
      $this->log->error($e);
      $this->returnJson(['status' => 'error', 'code' => 'db_error', 'detail' => $e->getMessage()]);
    }
  }

  /**
   * Returns select options for a field (select / combo_select / multi_select).
   *
   * GET ?obj=record_ctrl&method=getFieldOptions&tb=TABLE&fld=FIELD
   *
   * Response: [{ value, label }, ...]
   */
  public function getFieldOptions(): void
  {
    $tb  = $this->get['tb'] ?? null;
    $fld = $this->get['fld'] ?? null;

    if (!$tb || !$fld) {
      $this->returnJson(['status' => 'error', 'code' => 'parameter_missing']);
      return;
    }

    try {
      $this->returnJson(["status" => "success", "options" => $this->resolveFieldOptions($tb, $fld)]);
    } catch (\Throwable $e) {
      $this->log->error($e);
      $this->returnJson(['status' => 'error', 'code' => 'db_error', 'detail' => $e->getMessage()]);
    }
  }

  /**
   * Checks whether a field value is unique in its table.
   * Used for live `no_dupl` validation in the frontend before save.
   *
   * GET /api/record/{tb}/check-unique?field=FIELD&value=VALUE[&id=RECORD_ID]
   *
   * Response: { unique: bool }
   */
  public function checkUnique(): void
  {
    if (!\Auth\Authorization::can('read')) {
      $this->returnJson(['status' => 'error', 'code' => 'not_enough_privilege']);
      return;
    }

    $tb    = $this->get['tb']    ?? null;
    $field = $this->get['field'] ?? null;
    $value = $this->get['value'] ?? null;
    $id    = isset($this->get['id']) ? (int) $this->get['id'] : null;

    if (!$tb || !$field || $value === null) {
      $this->returnJson(['status' => 'error', 'code' => 'parameter_missing']);
      return;
    }

    // Validate field belongs to this table (prevents column-injection probing).
    $knownFields = array_column($this->cfg->get("tables.{$tb}.fields") ?: [], 'name');
    if (!in_array($field, $knownFields, true)) {
      $this->returnJson(['status' => 'error', 'code' => 'field_not_found']);
      return;
    }

    try {
      $sql    = "SELECT COUNT(*) AS c FROM {$tb} WHERE {$field} = ?";
      $params = [$value];
      if ($id) {
        $sql    .= ' AND id != ?';
        $params[] = $id;
      }
      $count = (int) ($this->db->query($sql, $params, 'read')[0]['c'] ?? 0);
      $this->returnJson(['status' => 'success', 'unique' => $count === 0]);
    } catch (\Throwable $e) {
      $this->log->error($e);
      $this->returnJson(['status' => 'error', 'code' => 'db_error']);
    }
  }

  /**
   * Returns available template names for a given table.
   *
   * GET ?obj=record_ctrl&method=getTemplates&tb=TABLE
   *
   * Response: { templates: ["default", "compact", ...] }
   */
  public function getTemplates(): void
  {
    $tb = $this->get['tb'] ?? null;
    if (!$tb) {
      $this->returnJson(['status' => 'error', 'code' => 'parameter_missing']);
      return;
    }

    $appName   = $this->cfg->get('main.name') ?? '';
    $templates = \Template\Loader::listAvailable($appName, $tb);

    $this->returnJson(["status" => "success", "templates" => $templates]);
  }

  // ── Private helpers ───────────────────────────────────────────────────────

  /**
   * Builds { fields: [...], plugins: { tb: { tb_id, label, fields } } }
   * for the given table.
   */
  private function buildTableSchema(string $tb): array
  {
    $plugins = [];
    foreach ($this->cfg->get("tables.{$tb}.plugin") ?: [] as $plg) {
      $plugins[$plg] = [
        'tb_id'  => $plg,
        'label'  => $this->cfg->get("tables.{$plg}.label") ?: $plg,
        'fields'      => $this->buildFieldSchema($plg),
      ];
    }

    return [
      'fields'      => $this->buildFieldSchema($tb),
      'plugins'     => $plugins,
      'rs_field'       => $this->cfg->get("tables.{$tb}.rs")         ?? null,
      'has_geodata'    => (bool) $this->cfg->get("tables.{$tb}.geodata"),
      'has_zotero'     => (bool) $this->cfg->get("tables.{$tb}.zotero"),
      'has_fuzzy_date' => (bool) $this->cfg->get("tables.{$tb}.fuzzy_date"),
    ];
  }

  /**
   * Returns an array of field-schema objects for a single table.
   */
  private function buildFieldSchema(string $tb): array
  {
    $result = [];
    foreach ($this->cfg->get("tables.{$tb}.fields") ?: [] as $fld) {
      // Normalize check to a clean array of tokens.
      // Old configs use a space-separated string (e.g. "required int no_dupl");
      // new configs use an array. Both 'required' and 'not_empty' mean mandatory.
      $check = $fld['check'] ?? [];
      if (is_string($check)) {
        $check = array_values(array_filter(array_map('trim', explode(' ', $check))));
      } else {
        $check = array_values((array)$check);
      }

      $schema = [
        'name'          => $fld['name'],
        'label'         => $fld['label'] ?? $fld['name'],
        'type'          => $fld['type'] ?? 'text',
        'readonly'      => !empty($fld['readonly']),
        'disabled'      => !empty($fld['disabled']),
        'hide'          => !empty($fld['hide']),
        'help'          => $fld['help'] ?? null,
        // 'required' is normalized from either the 'required' or 'not_empty' check token
        'required'      => in_array('required', $check, true) || in_array('not_empty', $check, true),
        'check'         => $check,   // full token list for frontend validation
        'min'           => $fld['min'] ?? null,
        'max'           => $fld['max'] ?? null,
        'max_length'    => $fld['max_length'] ?? null,
        'pattern'       => $fld['pattern'] ?? null,
        'def_value'     => $fld['def_value'] ?? null,
        'force_default' => !empty($fld['force_default']),
        'active_link'   => !empty($fld['active_link']),
        'direction'     => $fld['direction'] ?? null,
        'widget'        => $fld['widget'] ?? null,
        'options_source' => null,
      ];

      if (!empty($fld['get_values_from_tb'])) {
        $schema['options_source'] = ['type' => 'db',         'ref' => $fld['get_values_from_tb']];
      } elseif (!empty($fld['id_from_tb'])) {
        $schema['options_source'] = ['type' => 'id_from_tb', 'ref' => $fld['id_from_tb']];
      } elseif (!empty($fld['vocabulary_set'])) {
        $schema['options_source'] = ['type' => 'vocabulary',  'set' => $fld['vocabulary_set']];
      } elseif (!empty($fld['dic'])) {
        $schema['options_source'] = ['type' => 'static', 'items' => (array)$fld['dic']];
      }

      $result[] = $schema;
    }

    return $result;
  }

  /**
   * Resolves select options for a field from its configured source.
   */
  private function resolveFieldOptions(string $tb, string $fld): array
  {
    // Validate $tb against known config tables to prevent config traversal.
    // $fld is used only as a config key, not directly in SQL.
    $knownTables = array_keys($this->cfg->get('tables') ?? []);
    if (!in_array($tb, $knownTables, true)) {
      return [];
    }

    $allFields = $this->cfg->get("tables.{$tb}.fields.*") ?? [];
    $cfg = $allFields[$fld] ?? [];

    if (!empty($cfg['get_values_from_tb'])) {
      [$refTb, $refFld] = array_pad(explode(':', $cfg['get_values_from_tb'], 2), 2, null);
      $rows = $this->db->query(
        "SELECT DISTINCT {$refFld} as v FROM {$refTb} WHERE {$refFld} IS NOT NULL AND {$refFld} != '' ORDER BY {$refFld}"
      ) ?: [];
      return array_map(fn($r) => ['value' => $r['v'], 'label' => $r['v']], $rows);
    }

    if (!empty($cfg['id_from_tb'])) {
      $refTb  = $cfg['id_from_tb'];
      $idFld  = $this->cfg->get("tables.{$refTb}.id_field") ?: 'id';
      $rows   = $this->db->query(
        "SELECT id, {$idFld} as lbl FROM {$refTb} ORDER BY {$idFld}"
      ) ?: [];
      return array_map(fn($r) => ['value' => $r['id'], 'label' => $r['lbl']], $rows);
    }

    if (!empty($cfg['vocabulary_set'])) {
      $rows = $this->db->query(
        "SELECT def as v FROM bdus_vocabularies WHERE voc = ? ORDER BY sort",
        [$cfg['vocabulary_set']]
      ) ?: [];
      return array_map(fn($r) => ['value' => $r['v'], 'label' => $r['v']], $rows);
    }

    if (!empty($cfg['dic'])) {
      return array_map(fn($v) => ['value' => $v, 'label' => $v], (array)$cfg['dic']);
    }

    return [];
  }

  /**
   * Builds an empty record skeleton for add_new mode, applying def_value defaults.
   */
  private function buildEmptyRecord(string $tb, array $schema): array
  {
    $core = [];
    foreach ($schema['fields'] as $fld) {
      $def = $fld['def_value'];
      if ($def === '%today%')        { $def = date('Y-m-d'); }
      elseif ($def === '%current_user%') { $def = \Auth\CurrentUser::id(); }

      $core[$fld['name']] = ['name' => $fld['name'], 'label' => $fld['label'], 'val' => $def, 'val_label' => null];
    }

    $plugins = [];
    foreach ($schema['plugins'] as $plg => $plgSchema) {
      $plugins[$plg] = [
        'metadata' => ['tb_id' => $plg, 'tb_label' => $plgSchema['label'], 'tot' => 0],
        'data'     => [],
      ];
    }

    return [
      'metadata'    => ['tb_id' => $tb, 'tb_label' => $this->cfg->get("tables.{$tb}.label")],
      'core'        => $core,
      'plugins'     => $plugins,
      'links'       => [], 'backlinks' => [], 'manualLinks' => [],
      'files'       => [], 'geodata'   => [], 'rs'          => [],
    ];
  }

  // ── v5 persistence endpoint ──────────────────────────────────────────────

  /**
   * Save (create or update) a single record.
   *
   * POST ?obj=record_ctrl&method=saveRecord
   * Body (JSON or form):
   * {
   *   tb:      string,            // table name
   *   id:      int|null,          // omit / null for new records
   *   core:    { field: value },  // only fields that should be saved
   *   plugins: {                  // optional
   *     "tbl__name": [
   *       { id: int|null, _delete: bool, _isNew: bool, fields: { fld: val } }
   *     ]
   *   }
   * }
   *
   * Response: { status, code, id? }
   */
  public function saveRecord(): void
  {
    if (!\Auth\Authorization::can('edit')) {
      $this->returnJson(['status' => 'error', 'code' => 'not_enough_privilege']);
      return;
    }

    // Controller base class already merges JSON body into $this->post
    $tb   = $this->post['tb'] ?? ($this->get['tb'] ?? null);
    $id   = isset($this->post['id']) && $this->post['id'] !== '' && $this->post['id'] !== null
              ? (int) $this->post['id']
              : null;
    $core = $this->post['core'] ?? [];
    $plgs = $this->post['plugins'] ?? [];

    if (!$tb) {
      $this->returnJson(['status' => 'error', 'code' => 'parameter_missing']);
      return;
    }

    // Server-side validation — runs before any DB write
    $validationErrors = $this->validatePayload($tb, $id, $core, $plgs);
    if (!empty($validationErrors)) {
      $this->returnJson([
        'status' => 'error',
        'code'   => 'validation_failed',
        'errors' => $validationErrors,
      ]);
      return;
    }

    try {
      if ($id) {
        // ── UPDATE path: load existing record, apply changes, persist ────
        $reader = new \Record\Read($id, null, $tb, $this->db, $this->cfg);
        $editor = new \Record\Edit($reader);

        if (!empty($core)) {
          $editor->setCore($core);
        }

        foreach ($plgs as $plgTb => $rows) {
          foreach ($rows as $row) {
            $rowId  = !empty($row['id']) ? (int)$row['id'] : null;
            $delete = !empty($row['_delete']);
            $isNew  = !empty($row['_isNew']);
            $fields = $row['fields'] ?? [];

            if ($delete && $rowId) {
              $editor->setPluginRow($plgTb, $rowId, []);
            } elseif ($isNew && !empty($fields)) {
              $editor->setPluginRow($plgTb, null, $fields);
            } elseif ($rowId && !empty($fields)) {
              $editor->setPluginRow($plgTb, $rowId, $fields);
            }
          }
        }

        $result = $editor->persist($this->db, $this->cfg);
        $newId  = $result['core']['id'] ?? $id;

      } else {
        // ── INSERT path: build model directly with _val markers ─────────
        // For new records we skip Read/Edit (nothing to compare against)
        // and hand-craft the model with _val markers for every submitted field.

        // Server-side system fields: always override client-supplied values.
        // `creator` is NOT NULL in the DB and must be the authenticated user's id.
        // `id` must never be supplied on insert (auto-assigned by DB).
        unset($core['id']);
        if (array_key_exists('creator', $core)) {
          $core['creator'] = \Auth\CurrentUser::id() ?: 0;
        }

        $coreModel = [];
        foreach ($core as $fld => $val) {
          $coreModel[$fld] = ['name' => $fld, '_val' => $val];
        }

        // Ensure creator is always written, even if frontend omitted it entirely.
        if (!isset($coreModel['creator'])) {
          $coreModel['creator'] = ['name' => 'creator', '_val' => \Auth\CurrentUser::id() ?: 0];
        }

        $pluginsModel = [];
        foreach ($plgs as $plgTb => $rows) {
          $pluginsModel[$plgTb] = ['data' => []];
          foreach ($rows as $row) {
            if (!empty($row['_delete'])) continue;       // nothing to delete on insert
            $fields = $row['fields'] ?? [];
            if (empty($fields)) continue;
            $newRow = [];
            foreach ($fields as $fld => $val) {
              $newRow[$fld] = ['name' => $fld, '_val' => $val];
            }
            $pluginsModel[$plgTb]['data'][] = $newRow;
          }
        }

        $model = [
          'metadata'    => [
            'tb_id'      => $tb,
            'rec_id'  => null,
            'tb_label' => $this->cfg->get("tables.{$tb}.label"),
          ],
          'core'        => $coreModel,
          'plugins'     => $pluginsModel,
          'manualLinks' => [],
          'files'       => [],
          'geodata'     => [],
          'rs'          => [],
        ];

        $result = \Record\Persist::all($model, $this->db, $this->cfg);
        $newId  = $result['core']['id'] ?? null;
      }

      $this->returnJson([
        'status' => 'success',
        'code'   => $id ? 'success_saved' : 'success_created',
        'id'     => $newId,
      ]);

    } catch (\Throwable $e) {
      $this->log->error($e);
      $this->returnJson(['status' => 'error', 'code' => 'error_saved', 'detail' => $e->getMessage()]);
    }
  }

  // ── Validation helpers ────────────────────────────────────────────────────

  /**
   * Validates core and plugin fields before saving.
   * Returns an array of error objects, empty if everything is valid.
   *
   * @param  string   $tb    Main table id
   * @param  int|null $id    Record id (null = INSERT); used for no_dupl to exclude self
   * @param  array    $core  Core field values { fieldName: value }
   * @param  array    $plgs  Plugin rows { plgTb: [ {_delete, fields: {fn: v}} ] }
   * @return array    [ { field, label, rule, ...extra }, ... ]
   */
  private function validatePayload(string $tb, ?int $id, array $core, array $plgs): array
  {
    $errors = [];

    // Build field-config map for core table
    $fieldMap = [];
    foreach ($this->cfg->get("tables.{$tb}.fields") ?: [] as $fld) {
      $fieldMap[$fld['name']] = $fld;
    }

    foreach ($core as $fldName => $value) {
      if (!isset($fieldMap[$fldName])) {
        continue;
      }
      $errors = array_merge(
        $errors,
        $this->validateFieldValue($fldName, $value, $fieldMap[$fldName], $tb, $id)
      );
    }

    // Plugin fields
    foreach ($plgs as $plgTb => $rows) {
      $plgMap = [];
      foreach ($this->cfg->get("tables.{$plgTb}.fields") ?: [] as $fld) {
        $plgMap[$fld['name']] = $fld;
      }
      foreach ($rows as $row) {
        if (!empty($row['_delete'])) {
          continue;
        }
        foreach ($row['fields'] ?? [] as $fldName => $value) {
          if (!isset($plgMap[$fldName])) {
            continue;
          }
          $errors = array_merge(
            $errors,
            $this->validateFieldValue($fldName, $value, $plgMap[$fldName], $plgTb, null)
          );
        }
      }
    }

    return $errors;
  }

  /**
   * Validates a single field value against its config rules.
   * Returns an array of error objects (empty = valid).
   */
  private function validateFieldValue(
    string $fldName,
    mixed  $value,
    array  $cfg,
    string $tb,
    ?int   $excludeId
  ): array {
    $errors = [];
    $label  = $cfg['label'] ?? $fldName;
    $type   = $cfg['type']  ?? 'text';

    // Normalize check tokens
    $check = $cfg['check'] ?? [];
    if (is_string($check)) {
      $check = array_values(array_filter(array_map('trim', explode(' ', $check))));
    } else {
      $check = array_values((array)$check);
    }

    $isEmpty = ($value === null || $value === '' || $value === false);

    // required / not_empty
    if ((in_array('required', $check, true) || in_array('not_empty', $check, true)) && $isEmpty) {
      $errors[] = ['field' => $fldName, 'label' => $label, 'rule' => 'required'];
    }

    // No further checks on empty values
    if ($isEmpty) {
      return $errors;
    }

    // int — must be a whole number
    if (in_array('int', $check, true) && !preg_match('/^-?\d+$/', (string)$value)) {
      $errors[] = ['field' => $fldName, 'label' => $label, 'rule' => 'int'];
    }

    // email
    if (in_array('email', $check, true) && !filter_var($value, FILTER_VALIDATE_EMAIL)) {
      $errors[] = ['field' => $fldName, 'label' => $label, 'rule' => 'email'];
    }

    // min / max — numeric or date (ISO string comparison for dates)
    $min = $cfg['min'] ?? null;
    $max = $cfg['max'] ?? null;

    if ($min !== null) {
      $tooSmall = ($type === 'date')
        ? ((string)$value < (string)$min)
        : (is_numeric($value) && (float)$value < (float)$min);
      if ($tooSmall) {
        $errors[] = ['field' => $fldName, 'label' => $label, 'rule' => 'min', 'min' => $min];
      }
    }
    if ($max !== null) {
      $tooBig = ($type === 'date')
        ? ((string)$value > (string)$max)
        : (is_numeric($value) && (float)$value > (float)$max);
      if ($tooBig) {
        $errors[] = ['field' => $fldName, 'label' => $label, 'rule' => 'max', 'max' => $max];
      }
    }

    // max_length
    $maxLen = $cfg['max_length'] ?? null;
    if ($maxLen && mb_strlen((string)$value) > (int)$maxLen) {
      $errors[] = ['field' => $fldName, 'label' => $label, 'rule' => 'max_length', 'max_length' => $maxLen];
    }

    // pattern / regex — use the 'pattern' config key
    $pattern = $cfg['pattern'] ?? null;
    if ($pattern) {
      $delimited = '/' . str_replace('/', '\/', $pattern) . '/u';
      if (@preg_match($delimited, (string)$value) === 0) {
        $errors[] = ['field' => $fldName, 'label' => $label, 'rule' => 'pattern'];
      }
    }

    // no_dupl — uniqueness check in DB (excludes current record on UPDATE)
    if (in_array('no_dupl', $check, true)) {
      $sql    = "SELECT COUNT(*) as c FROM {$tb} WHERE {$fldName} = ?";
      $params = [$value];
      if ($excludeId) {
        $sql    .= ' AND id != ?';
        $params[] = $excludeId;
      }
      $count = (int)($this->db->query($sql, $params)[0]['c'] ?? 0);
      if ($count > 0) {
        $errors[] = ['field' => $fldName, 'label' => $label, 'rule' => 'no_dupl'];
      }
    }

    // valid_wkt — basic prefix check (no geometry library needed)
    if (in_array('valid_wkt', $check, true)) {
      $wktPrefixes = ['POINT', 'LINESTRING', 'POLYGON', 'MULTIPOINT',
                      'MULTILINESTRING', 'MULTIPOLYGON', 'GEOMETRYCOLLECTION'];
      $upper = strtoupper(trim((string)$value));
      $valid = false;
      foreach ($wktPrefixes as $prefix) {
        if (str_starts_with($upper, $prefix)) {
          $valid = true;
          break;
        }
      }
      if (!$valid) {
        $errors[] = ['field' => $fldName, 'label' => $label, 'rule' => 'valid_wkt'];
      }
    }

    return $errors;
  }

  // ── v5 file management endpoints ─────────────────────────────────────────

  /**
   * Uploads a file and attaches it to a record via a userlink.
   *
   * POST ?obj=record_ctrl&method=uploadFile&tb=TABLE&id=RECORD_ID
   * Multipart body: file=<binary>
   *
   * Response: { status, code, file: { id, ext, filename, url, is_image, description, keywords, printable } }
   */
  public function uploadFile(): void
  {
    if (!\Auth\Authorization::can('edit')) {
      $this->returnJson(['status' => 'error', 'code' => 'not_enough_privilege']);
      return;
    }

    $tb = $this->get['tb'] ?? ($this->post['tb'] ?? null);
    $id = $this->get['id'] ?? ($this->post['id'] ?? null);

    if (!$tb || !$id) {
      $this->returnJson(['status' => 'error', 'code' => 'parameter_missing']);
      return;
    }

    if (empty($_FILES['file']) || $_FILES['file']['error'] !== UPLOAD_ERR_OK) {
      $this->returnJson(['status' => 'error', 'code' => 'error_uploading_file']);
      return;
    }

    try {
      $original = basename($_FILES['file']['name']);
      $ext      = strtolower(pathinfo($original, PATHINFO_EXTENSION));
      $filename = pathinfo($original, PATHINFO_FILENAME);
      $creator  = \Auth\CurrentUser::id() ?? 'anonymous';
      $appName  = $this->cfg->get('main.name') ?? '';

      // Insert file record to obtain the auto-increment id
      $fileId = $this->db->query(
        "INSERT INTO bdus_files (creator, ext, filename) VALUES (?, ?, ?)",
        [$creator, $ext, $filename],
        'id'
      );

      // Move file to projects/{app}/files/{id}.{ext}
      $destDir  = PROJ_DIR . 'files/';
      $destFile = $destDir . $fileId . '.' . $ext;

      if (!is_dir($destDir)) {
        mkdir($destDir, 0755, true);
      }

      if (!move_uploaded_file($_FILES['file']['tmp_name'], $destFile)) {
        // Roll back the file record if the move fails
        $this->db->query("DELETE FROM bdus_files WHERE id = ?", [$fileId]);
        throw new \RuntimeException('move_uploaded_file failed');
      }

      // Resize image in-place if maxImageSize is configured.
      $maxPx = (int) trim((string) ($this->cfg->get('main.maxImageSize') ?? 0));
      if ($maxPx > 0 && !\Image\Resizer::maybeResize($destFile, $maxPx)) {
        // maybeResize returns false for skips (non-image, already small) AND
        // for errors.  We cannot distinguish here, but errors are non-fatal:
        // the original file remains usable.  Log at debug level to avoid noise.
        $this->log->debug("Image resize skipped or failed for {$destFile}");
      }

      // Create file link in the dedicated junction table
      $this->db->query(
        "INSERT INTO bdus_file_links (file_id, table_name, record_id) VALUES (?, ?, ?)",
        [$fileId, $tb, (int)$id]
      );

      $imageExts = ['jpg', 'jpeg', 'png', 'gif', 'webp', 'bmp', 'tiff', 'svg'];

      $this->returnJson([
        'status' => 'success',
        'code'   => 'ok_file_uploaded',
        'file'   => [
          'id'          => $fileId,
          'ext'         => $ext,
          'filename'    => $filename,
          'description' => null,
          'keywords'    => null,
          'printable'   => null,
          'is_image'    => in_array($ext, $imageExts, true),
        ],
      ]);

    } catch (\Throwable $e) {
      $this->log->error($e);
      $this->returnJson(['status' => 'error', 'code' => 'error_uploading_file', 'detail' => $e->getMessage()]);
    }
  }

  /**
   * Deletes a file: removes the physical file, the file record, and all userlinks.
   *
   * POST ?obj=record_ctrl&method=deleteFile
   * Body (JSON or form): { fileId: int }
   *
   * Response: { status, code }
   */
  public function deleteFile(): void
  {
    if (!\Auth\Authorization::can('edit')) {
      $this->returnJson(['status' => 'error', 'code' => 'not_enough_privilege']);
      return;
    }

    $fileId = (int)($this->post['fileId'] ?? $this->get['fileId'] ?? 0);
    if (!$fileId) {
      $this->returnJson(['status' => 'error', 'code' => 'parameter_missing']);
      return;
    }

    $appName = $this->cfg->get('main.name') ?? '';

    try {
      // Fetch file metadata (need ext to locate physical file)
      $rows = $this->db->query("SELECT ext FROM bdus_files WHERE id = ?", [$fileId]);
      if (empty($rows)) {
        $this->returnJson(['status' => 'error', 'code' => 'record_not_found']);
        return;
      }
      $ext = $rows[0]['ext'];

      // Remove all file_links that reference this file
      $this->db->query(
        "DELETE FROM bdus_file_links WHERE file_id = ?",
        [$fileId]
      );

      // Delete the file record
      $this->db->query("DELETE FROM bdus_files WHERE id = ?", [$fileId]);

      // Delete physical file (best-effort: ignore if already missing)
      $physicalPath = PROJ_DIR . 'files/' . $fileId . '.' . $ext;
      if (file_exists($physicalPath)) {
        @unlink($physicalPath);
      }

      $this->returnJson(['status' => 'success', 'code' => 'ok_file_deleted']);

    } catch (\Throwable $e) {
      $this->log->error($e);
      $this->returnJson(['status' => 'error', 'code' => 'error_file_deleted', 'detail' => $e->getMessage()]);
    }
  }

  // ── v5 stratigraphic relations (RS) endpoints ────────────────────────────

  /**
   * The 10 Harris Matrix relation types.
   * Each pair (n, n+4) for n=1..4 are inverses of each other.
   * Relations 9 and 10 are symmetric (undirected).
   */
  private static function rsRelations(): array
  {
    return [
      1 => 'is_covered_by',
      2 => 'is_cut_by',
      3 => 'carries',
      4 => 'is_filled_by',
      5 => 'covers',
      6 => 'cuts',
      7 => 'leans_against',
      8 => 'fills',
      9 => 'is_the_same_as',
      10 => 'is_bound_to',
    ];
  }

  /**
   * Returns the inverse relation code for polarity inversion.
   * Relations 1-4 ↔ 5-8 (pairs offset by 4).
   * Relations 9, 10 are self-inverse (symmetric).
   */
  private static function rsInverse(int $rel): int
  {
    if ($rel >= 1 && $rel <= 4) return $rel + 4;
    if ($rel >= 5 && $rel <= 8) return $rel - 4;
    return $rel; // 9, 10 symmetric
  }

  /**
   * Adds a stratigraphic relation between two records.
   *
   * POST ?obj=record_ctrl&method=addRs
   * Body: { tb, first, relation, second }
   *
   * Deduplication checks both directions (A→B and B→A inverse) before INSERT.
   * Response: { status, code, id? }
   */
  public function addRs(): void
  {
    if (!\Auth\Authorization::can('edit')) {
      $this->returnJson(['status' => 'error', 'code' => 'not_enough_privilege']);
      return;
    }

    $tb       = $this->post['tb']       ?? ($this->get['tb']       ?? null);
    $first    = $this->post['first']    ?? ($this->get['first']    ?? null);
    $relation = $this->post['relation'] ?? ($this->get['relation'] ?? null);
    $second   = $this->post['second']   ?? ($this->get['second']   ?? null);

    if (!$tb || !$first || !$relation || !$second) {
      $this->returnJson(['status' => 'error', 'code' => 'parameter_missing']);
      return;
    }

    $relation = (int) $relation;
    $inverse  = self::rsInverse($relation);

    try {
      // Deduplication: check both A→B (with this relation) and B→A (with inverse)
      $count = (int) ($this->db->query(
        "SELECT COUNT(*) AS c FROM bdus_rs
         WHERE tb = ? AND (
           (first = ? AND second = ? AND relation = ?) OR
           (first = ? AND second = ? AND relation = ?)
         )",
        [$tb, $first, $second, $relation, $second, $first, $inverse]
      )[0]['c'] ?? 0);

      if ($count > 0) {
        $this->returnJson(['status' => 'error', 'code' => 'relation_already_exist']);
        return;
      }

      $newId = (int) $this->db->query(
        "INSERT INTO bdus_rs (tb, first, second, relation) VALUES (?, ?, ?, ?)",
        [$tb, $first, $second, $relation],
        'id'
      );

      $this->returnJson(['status' => 'success', 'code' => 'ok_relation_add', 'id' => $newId]);

    } catch (\Throwable $e) {
      $this->log->error($e);
      $this->returnJson(['status' => 'error', 'code' => 'db_error', 'detail' => $e->getMessage()]);
    }
  }

  /**
   * Deletes a single stratigraphic relation by its row id.
   *
   * GET/POST ?obj=record_ctrl&method=deleteRs&id=ROW_ID
   * Response: { status, code }
   */
  public function deleteRs(): void
  {
    if (!\Auth\Authorization::can('edit')) {
      $this->returnJson(['status' => 'error', 'code' => 'not_enough_privilege']);
      return;
    }

    $id = $this->post['id'] ?? ($this->get['id'] ?? null);
    if (!$id) {
      $this->returnJson(['status' => 'error', 'code' => 'parameter_missing']);
      return;
    }

    try {
      $affected = $this->db->query(
        "DELETE FROM bdus_rs WHERE id = ?",
        [(int)$id],
        'affected'
      );

      if ($affected === 0) {
        $this->returnJson(['status' => 'error', 'code' => 'not_found']);
        return;
      }

      $this->returnJson(['status' => 'success', 'code' => 'ok_relation_erased']);

    } catch (\Throwable $e) {
      $this->log->error($e);
      $this->returnJson(['status' => 'error', 'code' => 'db_error', 'detail' => $e->getMessage()]);
    }
  }

  // ── Manual links (userlinks) ──────────────────────────────────────────────

  /**
   * Searches records in a table to use as manual-link candidates.
   *
   * GET ?obj=record_ctrl&method=searchLinkCandidates&tb=TABLE&q=QUERY
   *
   * If the table's id_field is 'id': returns the record whose id matches q (exact).
   * If the table's id_field is a text column: returns records where that column LIKE %q%.
   * When q is empty, returns the 20 most-recent records.
   *
   * Response: { status, data: [ { id, label }, … ] }
   */
  public function searchLinkCandidates(): void
  {
    if (!\Auth\Authorization::can('read')) {
      $this->returnJson(['status' => 'error', 'code' => 'not_enough_privilege']);
      return;
    }

    $tb = $this->get['tb'] ?? null;
    $q  = $this->get['q']  ?? null;

    if (!$tb || !$this->cfg->get("tables.$tb")) {
      $this->returnJson(['status' => 'error', 'code' => 'parameter_missing']);
      return;
    }

    $idFld = $this->cfg->get("tables.$tb.id_field") ?? 'id';

    try {
      if ($idFld === 'id') {
        if ($q !== null && $q !== '') {
          $sql    = "SELECT id, id AS label FROM {$tb} WHERE id = ? LIMIT 20";
          $values = [(int)$q];
        } else {
          $sql    = "SELECT id, id AS label FROM {$tb} ORDER BY id DESC LIMIT 20";
          $values = [];
        }
      } else {
        if ($q !== null && $q !== '') {
          $sql    = "SELECT id, {$idFld} AS label FROM {$tb} WHERE {$idFld} LIKE ? LIMIT 20";
          $values = ["%$q%"];
        } else {
          $sql    = "SELECT id, {$idFld} AS label FROM {$tb} ORDER BY id DESC LIMIT 20";
          $values = [];
        }
      }

      $res = $this->db->query($sql, $values, 'read');
      $this->returnJson(['status' => 'success', 'data' => $res ?: []]);

    } catch (\Throwable $e) {
      $this->log->error($e);
      $this->returnJson(['status' => 'error', 'code' => 'db_error', 'detail' => $e->getMessage()]);
    }
  }

  /**
   * Adds a manual link between two records.
   *
   * POST ?obj=record_ctrl&method=addManualLink
   * Body: { tb_one, id_one, tb_two, id_two }
   *
   * Checks for duplicates in both directions before inserting.
   * Response: { status, code, link: { key, tb_id, tb_label, ref_id, ref_label } }
   */
  public function addManualLink(): void
  {
    if (!\Auth\Authorization::can('edit')) {
      $this->returnJson(['status' => 'error', 'code' => 'not_enough_privilege']);
      return;
    }

    $tbOne = $this->post['tb_one'] ?? null;
    $idOne = $this->post['id_one'] ?? null;
    $tbTwo = $this->post['tb_two'] ?? null;
    $idTwo = $this->post['id_two'] ?? null;

    if (!$tbOne || !$idOne || !$tbTwo || !$idTwo) {
      $this->returnJson(['status' => 'error', 'code' => 'parameter_missing']);
      return;
    }

    $idOne  = (int)$idOne;
    $idTwo  = (int)$idTwo;

    try {
      // Dedup: check both directions
      $count = (int) ($this->db->query(
        "SELECT COUNT(*) AS c FROM bdus_userlinks
         WHERE (tb_one = ? AND id_one = ? AND tb_two = ? AND id_two = ?)
            OR (tb_one = ? AND id_one = ? AND tb_two = ? AND id_two = ?)",
        [$tbOne, $idOne, $tbTwo, $idTwo, $tbTwo, $idTwo, $tbOne, $idOne],
        'read'
      )[0]['c'] ?? 0);

      if ($count > 0) {
        $this->returnJson(['status' => 'error', 'code' => 'link_already_exists']);
        return;
      }

      $newId = (int) $this->db->query(
        "INSERT INTO bdus_userlinks (tb_one, id_one, tb_two, id_two) VALUES (?, ?, ?, ?)",
        [$tbOne, $idOne, $tbTwo, $idTwo],
        'id'
      );

      // Resolve the label of the linked record
      $idFld = $this->cfg->get("tables.$tbTwo.id_field") ?? 'id';
      if ($idFld === 'id') {
        $refLabel = $idTwo;
      } else {
        $lres     = $this->db->query("SELECT {$idFld} AS label FROM {$tbTwo} WHERE id = ?", [$idTwo], 'read');
        $refLabel = $lres[0]['label'] ?? $idTwo;
      }

      $appName    = $this->cfg->get('main.name') ?? '';
      $tbPrefix   = $appName !== '' ? $appName . '__' : '';
      $tbStripped = ($tbPrefix !== '' && str_starts_with($tbTwo, $tbPrefix))
                      ? substr($tbTwo, strlen($tbPrefix))
                      : $tbTwo;

      $this->returnJson([
        'status' => 'success',
        'code'   => 'all_links_saved',
        'link'   => [
          'key'        => $newId,
          'tb_id'      => $tbTwo,
          'tb_stripped' => $tbStripped,
          'tb_label'   => $this->cfg->get("tables.$tbTwo.label"),
          'ref_id'     => $idTwo,
          'ref_label'  => $refLabel,
          'sort'       => null,
        ],
      ]);

    } catch (\Throwable $e) {
      $this->log->error($e);
      $this->returnJson(['status' => 'error', 'code' => 'db_error', 'detail' => $e->getMessage()]);
    }
  }

  /**
   * Deletes a manual link by its userlinks.id.
   *
   * POST ?obj=record_ctrl&method=deleteManualLink
   * Body: { id }
   *
   * Response: { status, code }
   */
  public function deleteManualLink(): void
  {
    if (!\Auth\Authorization::can('edit')) {
      $this->returnJson(['status' => 'error', 'code' => 'not_enough_privilege']);
      return;
    }

    $id = $this->post['id'] ?? ($this->get['id'] ?? null);
    if (!$id) {
      $this->returnJson(['status' => 'error', 'code' => 'parameter_missing']);
      return;
    }

    try {
      $affected = $this->db->query(
        "DELETE FROM bdus_userlinks WHERE id = ?",
        [(int)$id],
        'affected'
      );

      $this->returnJson($affected > 0
        ? ['status' => 'success', 'code' => 'ok_userlink_erased']
        : ['status' => 'error',   'code' => 'not_found']
      );

    } catch (\Throwable $e) {
      $this->log->error($e);
      $this->returnJson(['status' => 'error', 'code' => 'db_error', 'detail' => $e->getMessage()]);
    }
  }

  /**
   * Returns all RS data for a table (or a filtered subset) for graph visualization.
   *
   * Accepts the same search parameters as getRecords() so the caller can pass
   * the current DataView query without re-encoding IDs.
   *
   * GET ?obj=record_ctrl&method=getRsMatrix&tb=TABLE
   *     [&search_type=fast&search=TERM]
   *     [&filter[field][_op]=value]
   *     [&search_type=advanced&adv=BASE64]
   *     [&search_type=sqlExpert&querytext=SQL&join=JOIN]
   *
   * Response:
   * {
   *   rs_field: string,
   *   nodes: [ { db_id: int, identifier: string, in_filter: bool }, ... ],
   *   relations: [ { id, first, second, relation }, ... ]
   * }
   *
   * Nodes with in_filter=false appear in relations but were not part of the
   * filtered record set (shown with attenuated style in the graph).
   */
  public function getRsMatrix(): void
  {
    if (!\Auth\Authorization::can('read')) {
      $this->returnJson(['status' => 'error', 'code' => 'not_enough_privilege']);
      return;
    }

    $tb = $this->get['tb'] ?? null;
    if (!$tb) {
      $this->returnJson(['status' => 'error', 'code' => 'parameter_missing']);
      return;
    }

    $rsField = $this->cfg->get("tables.{$tb}.rs");
    if (!$rsField) {
      $this->returnJson(['status' => 'error', 'code' => 'rs_not_configured']);
      return;
    }

    try {
      // ── Step 1: resolve which records to include ───────────────────────────
      $filterRaw  = $this->get['filter'] ?? null;
      if (is_string($filterRaw)) {
        $filterRaw = json_decode($filterRaw, true);
      }
      $searchType = $this->get['search_type'] ?? 'all';
      $filtered   = is_array($filterRaw) || ($searchType !== 'all');

      if ($filtered) {
        $qRequest = ['tb' => $tb, 'type' => $searchType];

        if (is_array($filterRaw)) {
          $qRequest['type']   = 'filter';
          $qRequest['filter'] = $filterRaw;
        } else {
          switch ($searchType) {
            case 'fast':
              $qRequest['string'] = $this->get['search'] ?? '';
              break;
            case 'sqlExpert':
              $qRequest['querytext'] = $this->get['querytext'] ?? '';
              $qRequest['join']      = $this->get['join']      ?? '';
              break;
          }
        }

        $qObj = new \SQL\QueryFromRequest($this->db, $this->cfg, $qRequest, true);
        // Fetch all matching IDs (no pagination — full result set for the matrix)
        list($subSql, $subVals) = $qObj->getQuery(true);
        $idRows = $this->db->query(
          "SELECT id FROM ({$subSql}) AS _rs_sub",
          $subVals
        ) ?: [];
        $dbIds = array_column($idRows, 'id');
      }

      // ── Step 2: fetch rs_field values for the filter set ──────────────────
      $filterNodes = []; // identifier => db_id

      if ($filtered && empty($dbIds)) {
        // Empty result set → no nodes, no relations
        $this->returnJson(["status" => "success", "rs_field" => $rsField, "nodes" => [], "relations" => []]);
        return;
      }

      if ($filtered) {
        $placeholders = implode(',', array_fill(0, count($dbIds), '?'));
        $rows = $this->db->query(
          "SELECT id, {$rsField} AS identifier FROM {$tb} WHERE id IN ({$placeholders})",
          $dbIds
        ) ?: [];
      } else {
        $rows = $this->db->query(
          "SELECT id, {$rsField} AS identifier FROM {$tb}"
        ) ?: [];
      }

      foreach ($rows as $r) {
        if ($r['identifier'] !== null && $r['identifier'] !== '') {
          $filterNodes[(string)$r['identifier']] = (int)$r['id'];
        }
      }

      $filterIdentifiers = array_keys($filterNodes);

      // ── Step 3: fetch RS relations ─────────────────────────────────────────
      if ($filtered && !empty($filterIdentifiers)) {
        $ph = implode(',', array_fill(0, count($filterIdentifiers), '?'));
        $relations = $this->db->query(
          "SELECT id, first, second, relation FROM bdus_rs
           WHERE tb = ? AND (first IN ({$ph}) OR second IN ({$ph}))",
          array_merge([$tb], $filterIdentifiers, $filterIdentifiers)
        ) ?: [];
      } else {
        $relations = $this->db->query(
          "SELECT id, first, second, relation FROM bdus_rs WHERE tb = ?",
          [$tb]
        ) ?: [];
      }

      // ── Step 4: collect all unique identifiers (including dangling) ────────
      $allIdentifiers = $filterNodes; // identifier => db_id (in_filter=true)
      $danglingIds = [];

      foreach ($relations as $rel) {
        foreach (['first', 'second'] as $side) {
          $ident = (string)$rel[$side];
          if (!isset($allIdentifiers[$ident])) {
            $danglingIds[] = $ident;
          }
        }
      }

      // Resolve dangling identifiers → db_id (may not exist if records were deleted)
      if (!empty($danglingIds)) {
        $ph = implode(',', array_fill(0, count($danglingIds), '?'));
        $dRows = $this->db->query(
          "SELECT id, {$rsField} AS identifier FROM {$tb}
           WHERE {$rsField} IN ({$ph})",
          $danglingIds
        ) ?: [];
        foreach ($dRows as $r) {
          $allIdentifiers[(string)$r['identifier']] = (int)$r['id'];
        }
      }

      // ── Step 5: build response ─────────────────────────────────────────────
      $hasFuzzyDate = (bool)$this->cfg->get("tables.{$tb}.fuzzy_date");

      // When fuzzy_date plugin is active, attach chrono data to every node.
      // Wrapped in try/catch: if columns don't exist (schema drift / flag without
      // activation), fall back gracefully rather than crashing.
      $chronoByDbId = [];
      if ($hasFuzzyDate && !empty($allIdentifiers)) {
        try {
          $dbIds = array_values($allIdentifiers);
          $ph    = implode(',', array_fill(0, count($dbIds), '?'));
          $cRows = $this->db->query(
            "SELECT id, chrono_from, chrono_to, chrono_label FROM {$tb} WHERE id IN ({$ph})",
            $dbIds
          ) ?: [];
          foreach ($cRows as $cr) {
            $chronoByDbId[(int)$cr['id']] = [
              'chrono_from'  => $cr['chrono_from'] !== null ? (int)$cr['chrono_from'] : null,
              'chrono_to'    => $cr['chrono_to']   !== null ? (int)$cr['chrono_to']   : null,
              'chrono_label' => $cr['chrono_label'] ?? null,
            ];
          }
        } catch (\Throwable $e) {
          // Columns missing — serve nodes without chrono data
          $hasFuzzyDate = false;
        }
      }

      $nodes = [];
      foreach ($allIdentifiers as $ident => $dbId) {
        $node = [
          'db_id'      => $dbId,
          'identifier' => (string)$ident,
          'in_filter'  => isset($filterNodes[$ident]),
        ];
        if ($hasFuzzyDate && isset($chronoByDbId[$dbId])) {
          $node = array_merge($node, $chronoByDbId[$dbId]);
        }
        $nodes[] = $node;
      }

      $this->returnJson([
        "status"         => "success",
        'rs_field'       => $rsField,
        'has_fuzzy_date' => $hasFuzzyDate,
        'nodes'          => $nodes,
        'relations'      => array_values($relations),
      ]);

    } catch (\Throwable $e) {
      $this->log->error($e);
      $this->returnJson(['status' => 'error', 'code' => 'db_error', 'detail' => $e->getMessage()]);
    }
  }

  public function erase(): void
  {
    if (!\Auth\Authorization::can('edit')) {
      $this->returnJson(['status' => 'error', 'code' => 'not_enough_privilege']);
      return;
    }

    $tb  = $this->get['tb'] ?? null;
    $raw = $this->get['id'] ?? null;

    if (!$tb || !$raw) {
      $this->returnJson(['status' => 'error', 'code' => 'no_id_provided']);
      return;
    }

    // Accept both a single id and an array of ids
    $ids = is_array($raw) ? array_map('intval', $raw) : [(int)$raw];

    $ok    = [];
    $error = [];

    foreach ($ids as $id) {
      if (!$id) continue;
      try {
        $reader = new \Record\Read($id, null, $tb, $this->db, $this->cfg);
        $editor = new \Record\Edit($reader);
        $editor->delete();
        $editor->persist($this->db, $this->cfg);
        $ok[] = $id;
      } catch (\Throwable $e) {
        $this->log->error($e);
        $error[] = $id;
      }
    }

    if (empty($ok) && !empty($error)) {
      $data = ['status' => 'error',   'code' => 'no_record_deleted'];
    } elseif (empty($error)) {
      $data = ['status' => 'success', 'code' => 'all_record_deleted', 'deleted' => count($ok)];
    } else {
      $data = ['status' => 'warning', 'code' => 'partially_deleted_with_count',
               'deleted' => count($ok), 'failed' => count($error)];
    }

    $this->returnJson($data);
  }

  // ── Deleted records ────────────────────────────────────────────────────────

  /**
   * Returns all records that were deleted but could be restored.
   *
   * GET /api/record/{tb}/deleted
   *
   * A record is "currently deleted" when:
   *   - a snapshot with operation='delete' exists for it, AND
   *   - its rowid is no longer present in the main table.
   *
   * Only the most-recent delete snapshot per rowid is returned (handles
   * the case where a record was deleted, restored, then deleted again).
   *
   * Response: { deleted: [{ version_id, rowid, userid, time, content:{core,plugins} }, …] }
   * Ordered by deletion time, newest first.
   */
  public function getDeletedRecords(): void
  {
    if (!\Auth\Authorization::can('read')) {
      $this->returnJson(['status' => 'error', 'code' => 'not_enough_privilege']);
      return;
    }

    $tb = $this->get['tb'] ?? null;

    if (!$tb) {
      $this->returnJson(['status' => 'error', 'code' => 'parameter_missing']);
      return;
    }

    if (!$this->cfg->get("tables.{$tb}")) {
      $this->returnJson(['status' => 'error', 'code' => 'unknown_table']);
      return;
    }

    try {
      // Most-recent delete snapshot per rowid, only for rows absent from the table.
      $rows = $this->db->query(
        "SELECT v.id, v.rowid, v.userid, v.time, v.content
         FROM bdus_versions v
         WHERE v.tb = ?
           AND v.operation = 'delete'
           AND v.id = (
               SELECT MAX(v2.id) FROM bdus_versions v2
               WHERE v2.tb = v.tb AND v2.rowid = v.rowid AND v2.operation = 'delete'
           )
           AND v.rowid NOT IN (SELECT id FROM {$tb})
         ORDER BY v.time DESC",
        [$tb]
      ) ?: [];

      $deleted = array_map(function (array $row): array {
        $d = new \DateTime();
        $d->setTimestamp((int)$row['time']);
        return [
          'version_id' => (int)$row['id'],
          'rowid'      => (int)$row['rowid'],
          'userid'     => $row['userid'],
          'time'       => $d->format('Y-m-d H:i:s'),
          'content'    => json_decode($row['content'], true) ?? ['core' => [], 'plugins' => []],
        ];
      }, $rows);

      $this->returnJson(['deleted' => $deleted]);

    } catch (\Throwable $e) {
      $this->log->error($e);
      $this->returnJson(['status' => 'error', 'code' => 'db_error', 'detail' => $e->getMessage()]);
    }
  }

  // ── Version history ────────────────────────────────────────────────────────

  /**
   * Returns the list of recorded versions for a single record.
   *
   * GET /api/record/{tb}/{id}/versions
   *
   * Response: { versions: [{ id, userid, time, operation }, …] }
   * Ordered newest-first.
   */
  public function getVersions(): void
  {
    if (!\Auth\Authorization::can('read')) {
      $this->returnJson(['status' => 'error', 'code' => 'not_enough_privilege']);
      return;
    }

    $tb  = $this->get['tb']  ?? null;
    $id  = (int)($this->get['id'] ?? 0);

    if (!$tb || !$id) {
      $this->returnJson(['status' => 'error', 'code' => 'parameter_missing']);
      return;
    }

    // Validate table against config to prevent probing arbitrary tables.
    $knownTables = array_keys($this->cfg->get('tables') ?? []);
    if (!in_array($tb, $knownTables, true)) {
      $this->returnJson(['status' => 'error', 'code' => 'unknown_table']);
      return;
    }

    try {
      $rows = $this->db->query(
        "SELECT id, userid, time, operation
           FROM bdus_versions
          WHERE tb = ? AND rowid = ?
          ORDER BY id DESC",
        [$tb, $id]
      ) ?: [];

      foreach ($rows as &$row) {
        if ($row['time']) {
          $d = new \DateTime();
          $d->setTimestamp((int)$row['time']);
          $row['time'] = $d->format('Y-m-d H:i:s');
        }
        $row['id'] = (int)$row['id'];
      }
      unset($row); // break reference

      $this->returnJson(['versions' => $rows]);

    } catch (\Throwable $e) {
      $this->log->error($e);
      $this->returnJson(['status' => 'error', 'code' => 'db_error', 'detail' => $e->getMessage()]);
    }
  }

  /**
   * Returns the full snapshot for one version entry plus the current state of
   * the same record, so the frontend can compute and display the diff.
   *
   * GET /api/version/{id}
   *
   * Response:
   * {
   *   version: { id, userid, time, operation, content: { core, plugins } },
   *   current: { core, plugins }
   * }
   *
   * `current.core` is null when the record has been deleted.
   */
  public function getVersionDiff(): void
  {
    if (!\Auth\Authorization::can('read')) {
      $this->returnJson(['status' => 'error', 'code' => 'not_enough_privilege']);
      return;
    }

    $versionId = (int)($this->get['id'] ?? 0);

    if (!$versionId) {
      $this->returnJson(['status' => 'error', 'code' => 'parameter_missing']);
      return;
    }

    try {
      $rows = $this->db->query(
        "SELECT * FROM bdus_versions WHERE id = ?",
        [$versionId]
      );

      if (empty($rows)) {
        $this->returnJson(['status' => 'error', 'code' => 'version_not_found']);
        return;
      }

      $ver     = $rows[0];
      $tb      = $ver['tb'];
      $rid     = (int)$ver['rowid'];
      $content = json_decode($ver['content'], true) ?? ['core' => [], 'plugins' => []];

      if ($ver['time']) {
        $d = new \DateTime();
        $d->setTimestamp((int)$ver['time']);
        $ver['time'] = $d->format('Y-m-d H:i:s');
      }

      // Current state of the core row (null if the record was deleted)
      $currentRows = $this->db->query("SELECT * FROM {$tb} WHERE id = ?", [$rid]) ?: [];
      $currentCore = $currentRows[0] ?? null;

      // Current state of plugins (flat DB rows for direct comparison)
      $pluginNames    = $this->cfg->get("tables.{$tb}.plugin") ?: [];
      $currentPlugins = [];
      foreach ($pluginNames as $pluginName) {
        $currentPlugins[$pluginName] = $this->db->query(
          "SELECT * FROM {$pluginName} WHERE table_link = ? AND id_link = ?",
          [$tb, $rid]
        ) ?: [];
      }

      $this->returnJson([
        'version' => [
          'id'        => (int)$ver['id'],
          'userid'    => $ver['userid'],
          'time'      => $ver['time'],
          'operation' => $ver['operation'],
          'content'   => $content,
        ],
        'current' => [
          'core'    => $currentCore,
          'plugins' => $currentPlugins,
        ],
      ]);

    } catch (\Throwable $e) {
      $this->log->error($e);
      $this->returnJson(['status' => 'error', 'code' => 'db_error', 'detail' => $e->getMessage()]);
    }
  }

  /**
   * Restores a record to a previously snapshotted state.
   *
   * POST /api/version/{id}/restore
   * Body: { fields: ['name', 'status', …], restore_plugins: ['tags', …] }
   *
   * - `fields` (optional): core fields to restore.
   *   Empty array or missing → restore all core fields.
   *   `id` and `creator` are always excluded from updates.
   * - `restore_plugins` (optional): plugin sections to restore (all-or-nothing
   *   per section: delete current rows, re-insert snapshot rows).
   *   Empty array or missing → no plugins restored.
   *
   * Deleted-record recovery: when the target record no longer exists in the
   * main table, a full INSERT is performed (all core fields, all plugins),
   * restoring the original id to preserve referential integrity.
   *
   * The current state is snapshotted with operation='restore' before any
   * write so the action is fully auditable and itself reversible.
   *
   * Response: { code: 'success_restored', tb, id, created: bool }
   */
  public function restoreVersion(): void
  {
    if (!\Auth\Authorization::can('admin')) {
      $this->returnJson(['status' => 'error', 'code' => 'not_enough_privilege']);
      return;
    }

    $versionId      = (int)($this->post['version_id']     ?? $this->get['id']          ?? 0);
    $fields         =       $this->post['fields']          ?? [];
    $restorePlugins =       $this->post['restore_plugins'] ?? [];

    if (!$versionId) {
      $this->returnJson(['status' => 'error', 'code' => 'parameter_missing']);
      return;
    }

    try {
      $rows = $this->db->query(
        "SELECT * FROM bdus_versions WHERE id = ?",
        [$versionId]
      );

      if (empty($rows)) {
        $this->returnJson(['status' => 'error', 'code' => 'version_not_found']);
        return;
      }

      $ver     = $rows[0];
      $tb      = $ver['tb'];
      $rid     = (int)$ver['rowid'];
      $content = json_decode($ver['content'], true) ?? ['core' => [], 'plugins' => []];

      // Validate table against config.
      $knownTables = array_keys($this->cfg->get('tables') ?? []);
      if (!in_array($tb, $knownTables, true)) {
        $this->returnJson(['status' => 'error', 'code' => 'unknown_table']);
        return;
      }

      $snapshotCore    = $content['core']    ?? [];
      $snapshotPlugins = $content['plugins'] ?? [];

      // Fetch current live state to determine UPDATE vs INSERT.
      $currentRows = $this->db->query("SELECT * FROM {$tb} WHERE id = ?", [$rid]) ?: [];
      $currentCore = $currentRows[0] ?? null;
      $recordExists = ($currentCore !== null);

      $pluginNames    = $this->cfg->get("tables.{$tb}.plugin") ?: [];
      $currentPlugins = [];
      foreach ($pluginNames as $pluginName) {
        $currentPlugins[$pluginName] = $this->db->query(
          "SELECT * FROM {$pluginName} WHERE table_link = ? AND id_link = ?",
          [$tb, $rid]
        ) ?: [];
      }

      // ── Pre-restore snapshot (only when the record still exists) ──────────
      // Records the state being overwritten so the restore itself is auditable
      // and reversible.
      if ($recordExists) {
        $this->db->saveSnapshot($tb, $rid, [
          'core'    => $currentCore,
          'plugins' => $currentPlugins,
        ], 'restore');
      }

      // ── Write ─────────────────────────────────────────────────────────────
      $this->db->beginTransaction();
      try {
        if (!$recordExists) {
          // ── Deleted record recovery: full INSERT with original id ──────
          // All fields are restored (UI filtering is irrelevant when bringing
          // back a record that no longer exists).
          $insertData = $snapshotCore;   // snapshot already contains 'id'
          $flds  = array_keys($insertData);
          $ph    = implode(', ', array_fill(0, count($flds), '?'));
          $this->db->query(
            "INSERT INTO {$tb} (" . implode(', ', $flds) . ") VALUES ({$ph})",
            array_values($insertData)
          );

          // Restore all plugin sections from the snapshot.
          foreach ($snapshotPlugins as $pluginName => $snapshotRows) {
            $this->db->query(
              "DELETE FROM {$pluginName} WHERE table_link = ? AND id_link = ?",
              [$tb, $rid], 'boolean'
            );
            foreach ($snapshotRows as $r) {
              unset($r['id']);
              $r['table_link'] = $tb;
              $r['id_link']    = $rid;
              $flds = array_keys($r);
              $ph   = implode(', ', array_fill(0, count($flds), '?'));
              $this->db->query(
                "INSERT INTO {$pluginName} (" . implode(', ', $flds) . ") VALUES ({$ph})",
                array_values($r)
              );
            }
          }

        } else {
          // ── Normal restore: UPDATE selected core fields ────────────────
          $coreToRestore = empty($fields)
            ? array_keys($snapshotCore)
            : array_intersect((array)$fields, array_keys($snapshotCore));

          // Never overwrite system/immutable fields.
          $coreToRestore = array_diff($coreToRestore, ['id', 'creator']);

          if (!empty($coreToRestore)) {
            $toWrite = array_intersect_key($snapshotCore, array_flip($coreToRestore));
            $setParts = array_map(fn($k) => "{$k} = ?", array_keys($toWrite));
            $sql      = "UPDATE {$tb} SET " . implode(', ', $setParts) . " WHERE id = ?";
            $vals     = array_values($toWrite);
            $vals[]   = $rid;
            $this->db->query($sql, $vals, 'boolean');
          }

          // ── Restore selected plugin sections (all-or-nothing) ──────────
          foreach ((array)$restorePlugins as $pluginName) {
            if (!isset($snapshotPlugins[$pluginName])) {
              continue;
            }
            $this->db->query(
              "DELETE FROM {$pluginName} WHERE table_link = ? AND id_link = ?",
              [$tb, $rid], 'boolean'
            );
            foreach ($snapshotPlugins[$pluginName] as $r) {
              unset($r['id']);
              $r['table_link'] = $tb;
              $r['id_link']    = $rid;
              $flds = array_keys($r);
              $ph   = implode(', ', array_fill(0, count($flds), '?'));
              $this->db->query(
                "INSERT INTO {$pluginName} (" . implode(', ', $flds) . ") VALUES ({$ph})",
                array_values($r)
              );
            }
          }
        }

        $this->db->commit();
      } catch (\Throwable $e) {
        $this->db->rollBack();
        throw $e;
      }

      $this->returnJson([
        'code'    => 'success_restored',
        'tb'      => $tb,
        'id'      => $rid,
        'created' => !$recordExists,
      ]);

    } catch (\Throwable $e) {
      $this->log->error($e);
      $this->returnJson(['status' => 'error', 'code' => 'db_error', 'detail' => $e->getMessage()]);
    }
  }

}
