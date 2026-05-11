<?php

/**
 * @copyright 2007-2022 Julian Bogdani
 * @license AGPL-3.0; see LICENSE
 * @since			Jan 10, 2013
 */

use \Record\Read;
use Template\Template;

class record_ctrl extends Controller
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
    if (!\utils::canUser('read')) {
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
    $searchType = $this->get['search_type'] ?? $this->post['search_type'] ?? 'all';

    // Build QueryFromRequest-compatible request array
    $qRequest = ['tb' => $tb, 'type' => $searchType];

    switch ($searchType) {
      case 'fast':
        $qRequest['string'] = $this->get['search'] ?? $this->post['search'] ?? '';
        break;
      case 'advanced':
        $qRequest['adv'] = $this->post['adv'] ?? [];
        break;
      case 'sqlExpert':
        $qRequest['querytext'] = $this->post['querytext'] ?? $this->get['querytext'] ?? '';
        $qRequest['join']      = $this->post['join']      ?? $this->get['join']      ?? '';
        break;
      case 'shortSql':
        $qRequest['where'] = $this->get['where'] ?? $this->post['where'] ?? '';
        break;
      default:
        $qRequest['type'] = 'all';
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
        $label = $this->cfg->get("tables.{$tb}.fields.{$col}.label") ?: $col;
        $colMap[$col] = $label;
      }
      $colMap = array_merge(['id' => 'id'], $colMap);
      $qRequest['fields'] = $colMap;
    }

    // use_preview=true unless we are supplying a custom column list
    $usePreview = !isset($qRequest['fields']);

    try {
      $qObj = new \QueryFromRequest($this->db, $this->cfg, $qRequest, $usePreview);

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
        'total'   => $total,
        'fields'  => $fields,
        'data'    => $qObj->getResults(),
        'can_add' => \utils::canUser('add_new'),
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
   *     &qt=fast|expert|advanced|shortSql   (optional — mirrors route.query.qt)
   *     &q=VALUE                             (optional — mirrors route.query.q)
   *     &where=SHORTSQL                      (optional — mirrors route.query.where)
   *
   * For qt=advanced, q must be a base64-encoded JSON array of search rows
   * (same encoding used by DataView when persisting filter state in the URL).
   */
  public function exportRecords(): void
  {
    if (!\utils::canUser('read')) {
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

    if ($where) {
      $qRequest['type']  = 'shortSql';
      $qRequest['where'] = $where;
    } elseif ($qt === 'fast' && $q !== null) {
      $qRequest['type']   = 'fast';
      $qRequest['string'] = $q;
    } elseif ($qt === 'expert' && $q !== null) {
      $qRequest['type']      = 'sqlExpert';
      $qRequest['querytext'] = $q;
      $qRequest['join']      = '';
    } elseif ($qt === 'advanced' && $q !== null) {
      $rows = @json_decode(@base64_decode($q), true);
      if (is_array($rows) && count($rows) > 0) {
        $qRequest['type'] = 'advanced';
        $qRequest['adv']  = $rows;
      }
    }

    try {
      $qObj  = new \QueryFromRequest($this->db, $this->cfg, $qRequest, false);
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
   *   metadata: { tb_id, tb_stripped, tb_label, rec_id, id_field, can_edit, can_delete },
   *   schema:   { fields: [{name, label, type, readonly, options_source, ...}], plugins: {...} },
   *   core:     { field: { name, label, val, val_label? }, ... },
   *   plugins:  { tb: { metadata, data } },
   *   links, backlinks, manualLinks, files, geodata, rs
   * }
   */
  public function getRecord(): void
  {
    if (!\utils::canUser('read')) {
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

      $full['metadata']['rec_id']     = $recId;
      $full['metadata']['id_field']   = $this->cfg->get("tables.{$tb}.id_field");
      $full['metadata']['can_edit']   = \utils::canUser('edit');
      $full['metadata']['can_delete'] = \utils::canUser('edit');
      $full['schema'] = $schema;

      // Template loading
      $appName    = $this->cfg->get('main.name') ?? '';
      $tbStripped = str_replace($this->prefix, '', $tb);
      $tplName    = $this->get['template'] ?? null;
      if ($tplName) {
        $tpl = \Template\Loader::load($appName, $tbStripped, $tplName);
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

      // Enrich each file with a relative URL and an is_image flag so the
      // frontend can render thumbnails and download links without extra API calls.
      if (!empty($full['files']) && \is_array($full['files'])) {
        $appName   = $this->cfg->get('main.name') ?? '';
        $basePath  = 'projects/' . $appName . '/files/';
        $imageExts = ['png', 'jpeg', 'jpg', 'bmp', 'ico', 'tif', 'tiff'];
        foreach ($full['files'] as &$file) {
          $ext = \strtolower($file['ext'] ?? '');
          $file['url']      = $basePath . $file['id'] . '.' . $ext;
          $file['is_image'] = \in_array($ext, $imageExts, true);
        }
        unset($file);
      }

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
      $this->returnJson($this->resolveFieldOptions($tb, $fld));
    } catch (\Throwable $e) {
      $this->log->error($e);
      $this->returnJson(['status' => 'error', 'code' => 'db_error', 'detail' => $e->getMessage()]);
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

    $appName    = $this->cfg->get('main.name') ?? '';
    $tbStripped = str_replace($this->prefix, '', $tb);
    $templates  = \Template\Loader::listAvailable($appName, $tbStripped);

    $this->returnJson(['templates' => $templates]);
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
        'tb_id'       => $plg,
        'tb_stripped' => str_replace($this->prefix, '', $plg),
        'label'       => $this->cfg->get("tables.{$plg}.label") ?: $plg,
        'fields'      => $this->buildFieldSchema($plg),
      ];
    }

    return ['fields' => $this->buildFieldSchema($tb), 'plugins' => $plugins];
  }

  /**
   * Returns an array of field-schema objects for a single table.
   */
  private function buildFieldSchema(string $tb): array
  {
    $result = [];
    foreach ($this->cfg->get("tables.{$tb}.fields") ?: [] as $fld) {
      $check = $fld['check'] ?? [];
      if (is_string($check)) {
        $check = array_filter(explode(' ', $check));
      }

      $schema = [
        'name'          => $fld['name'],
        'label'         => $fld['label'] ?? $fld['name'],
        'type'          => $fld['type'] ?? 'text',
        'readonly'      => !empty($fld['readonly']),
        'disabled'      => !empty($fld['disabled']),
        'hide'          => !empty($fld['hide']),
        'help'          => $fld['help'] ?? null,
        'required'      => in_array('required', (array)$check, true),
        'min'           => $fld['min'] ?? null,
        'max'           => $fld['max'] ?? null,
        'max_length'    => $fld['max_length'] ?? null,
        'pattern'       => $fld['pattern'] ?? null,
        'def_value'     => $fld['def_value'] ?? null,
        'force_default' => !empty($fld['force_default']),
        'active_link'   => !empty($fld['active_link']),
        'direction'     => $fld['direction'] ?? null,
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

    $cfg = $this->cfg->get("tables.{$tb}.fields.{$fld}") ?? [];

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
        "SELECT def as v FROM {$this->prefix}vocabularies WHERE voc = ? ORDER BY sort",
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
      elseif ($def === '%current_user%') { $def = $_SESSION['user']['id'] ?? null; }

      $core[$fld['name']] = ['name' => $fld['name'], 'label' => $fld['label'], 'val' => $def, 'val_label' => null];
    }

    $plugins = [];
    foreach ($schema['plugins'] as $plg => $plgSchema) {
      $plugins[$plg] = [
        'metadata' => ['tb_id' => $plg, 'tb_stripped' => $plgSchema['tb_stripped'], 'tb_label' => $plgSchema['label'], 'tot' => 0],
        'data'     => [],
      ];
    }

    return [
      'metadata'    => ['tb_id' => $tb, 'tb_stripped' => str_replace($this->prefix, '', $tb), 'tb_label' => $this->cfg->get("tables.{$tb}.label")],
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
    if (!\utils::canUser('edit')) {
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
        $coreModel = [];
        foreach ($core as $fld => $val) {
          $coreModel[$fld] = ['name' => $fld, '_val' => $val];
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
            'rec_id'     => null,
            'tb_stripped'=> str_replace($this->prefix, '', $tb),
            'tb_label'   => $this->cfg->get("tables.{$tb}.label"),
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
    if (!\utils::canUser('edit')) {
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
      $creator  = $_SESSION['user']['id'] ?? 'anonymous';
      $prefix   = $this->prefix;
      $appName  = $this->cfg->get('main.name') ?? '';

      // Insert file record to obtain the auto-increment id
      $this->db->query(
        "INSERT INTO {$prefix}files (creator, ext, filename) VALUES (?, ?, ?)",
        [$creator, $ext, $filename]
      );
      $fileId = $this->db->lastId();

      // Move file to projects/{app}/files/{id}.{ext}
      $destDir  = PROJ_DIR . 'files/';
      $destFile = $destDir . $fileId . '.' . $ext;

      if (!is_dir($destDir)) {
        mkdir($destDir, 0755, true);
      }

      if (!move_uploaded_file($_FILES['file']['tmp_name'], $destFile)) {
        // Roll back the file record if the move fails
        $this->db->query("DELETE FROM {$prefix}files WHERE id = ?", [$fileId]);
        throw new \RuntimeException('move_uploaded_file failed');
      }

      // Create userlink: files-table → record
      $this->db->query(
        "INSERT INTO {$prefix}userlinks (tb_one, id_one, tb_two, id_two) VALUES (?, ?, ?, ?)",
        ["{$prefix}files", $fileId, $tb, (int)$id]
      );

      $imageExts = ['jpg', 'jpeg', 'png', 'gif', 'webp', 'bmp', 'tiff', 'svg'];
      $url       = 'projects/' . $appName . '/files/' . $fileId . '.' . $ext;

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
          'url'         => $url,
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
    if (!\utils::canUser('edit')) {
      $this->returnJson(['status' => 'error', 'code' => 'not_enough_privilege']);
      return;
    }

    $fileId = (int)($this->post['fileId'] ?? $this->get['fileId'] ?? 0);
    if (!$fileId) {
      $this->returnJson(['status' => 'error', 'code' => 'parameter_missing']);
      return;
    }

    $prefix  = $this->prefix;
    $appName = $this->cfg->get('main.name') ?? '';

    try {
      // Fetch file metadata (need ext to locate physical file)
      $rows = $this->db->query("SELECT ext FROM {$prefix}files WHERE id = ?", [$fileId]);
      if (empty($rows)) {
        $this->returnJson(['status' => 'error', 'code' => 'record_not_found']);
        return;
      }
      $ext = $rows[0]['ext'];

      // Remove all userlinks that reference this file on either side
      $this->db->query(
        "DELETE FROM {$prefix}userlinks WHERE tb_one = ? AND id_one = ?",
        ["{$prefix}files", $fileId]
      );
      $this->db->query(
        "DELETE FROM {$prefix}userlinks WHERE tb_two = ? AND id_two = ?",
        ["{$prefix}files", $fileId]
      );

      // Delete the file record
      $this->db->query("DELETE FROM {$prefix}files WHERE id = ?", [$fileId]);

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

  // ── Legacy v4 methods (kept for Twig-based rendering) ────────────────────

  public function save_data()
  {
    try {
      if (!$this->request['id']) {
        $this->request['id'] = [false];
      } elseif (!is_array($this->request['id'])) {
        $this->request['id'] = (array)$this->request['id'];
      }

      $ok = [];
      $error = [];
      if (is_array($this->request['id'])) {
        foreach ($this->request['id'] as $id) {
          try {
            $record = new Record($this->get['tb'], $id, $this->db, $this->cfg);

            if (is_array($this->post['core'])) {
              $record->setCore($this->post['core']);
            }

            if (is_array($this->post['plg'])) {
              foreach ($this->post['plg'] as $plg_name => $plg_data) {
                $record->setPlugin($plg_name, $plg_data);
              }
            }

            $a = $record->persist();

            if (!$id) {
              $inserted_id = $record->getId();
            }

            if ($a) {
              $ok[$id] = true;
            } else {
              $error[$id] = true;
            }
          } catch (\Throwable $e) {
            $error[$id] = true;
            $this->log->error($e);
          }
        }
      }

      if (count($ok) == count($this->request['id'])) {
        $data['status'] = 'success';
        $data['code']   = 'success_saved';
        if ($inserted_id) { $data['inserted_id'] = $inserted_id; }
      } elseif (count($error) == count($this->request['id'])) {
        $data['status'] = 'error';
        $data['code']   = 'error_saved';
      } else {
        $data['status'] = 'warning';
        $data['code']   = 'partial_success_saved';
        $data['saved']  = array_keys($ok);
        $data['failed'] = array_keys($error);
      }
    } catch (\Throwable $e) {
      $data['status'] = 'error';
      $data['code']   = 'error_saved';
      $this->log->error($e);
    }

    echo json_encode($data);
  }



  public function erase(): void
  {
    if (!\utils::canUser('edit')) {
      $this->returnJson(['status' => 'error', 'code' => 'not_enough_privilege']);
      return;
    }

    $tb  = $this->request['tb'] ?? null;
    $raw = $this->request['id'] ?? null;

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


  /** @deprecated v5 — replaced by RecordView.vue + getRecord() */
  public function show()
  {
    $tb = $this->request['tb'];
    $context = $this->request['a'];
    $id = $this->request['id'];
    $id_field = $this->request['id_field'];

    if (!$tb) {
      throw new \Exception(\tr::get('tb_missing'));
    }

    // user must have enough privileges
    if (!\utils::canUser('read')) {
      echo '<div class="text-danger">'
        . '<strong>' . \tr::get('attention') . ':</strong> ' . \tr::get('not_enough_privilege') . '</p>'
        . '</div>';
      return;
    }
    // a record id must be provided in edit & read & preview mode
    if ($context !== 'add_new' && !$id && !$id_field) {
      throw new \Exception(\tr::get('no_id_to_view'));
    }

    // no data are retrieved if context is add_new or multiple edit!
    if ($context === 'add_new' || ($context === 'edit' and count($id) > 1)) {
      $id_arr = ['new'];
      $flag_idfield = false;
    } elseif ($id_field) {
      $id_arr = $id_field;
      $flag_idfield = true;
    } else {
      $id_arr = $id;
      $flag_idfield = false;
    }


    //Can not display more than 100 records!
    $total_records = count($id_arr);
    if ($total_records > 100) {
      echo '<div class="alert">' . \tr::get('too_much_records', [$total_records, '100']) . '</div>';
      return;
    }

    $step = 10;

    foreach ($id_arr as $index => $one_id) {
      $index = $index + 1;

      if ($index > ($step)) {
        return;
      }
      if (($key = array_search($one_id, $id_arr)) !== false) {
        unset($id_arr[$key]);
      }
      if ($index === $total_records) {
        $continue_url = 'end';
      } elseif ($index === $step) {
        echo $continue_url = 'id[]=' . implode('&id[]=', $id_arr);
      }

      if ($one_id === 'new') {
        $one_id = null;
      }

      if ($flag_idfield) {
        $readRecord = new Read(null, $one_id, $tb, $this->db, $this->cfg);
      } else {
        $readRecord = new Read($one_id, null, $tb, $this->db, $this->cfg);
      }

      if (
        $context === 'edit' &&
        (!\utils::canUser('edit', $readRecord->getCore('creator', true)) || (count($id) > 1 && !\utils::canUser('multiple_edit')))
      ) {
        echo '<h2>' . \tr::get('not_enough_privilege') . '</h2>';
        continue;
      }

      if ($context === 'add_new' && !\utils::canUser('add_new')) {
        echo '<h2>' . \tr::get('not_enough_privilege') . '</h2>';
        continue;
      }

      $fieldObj = new Template($context, $readRecord, $this->db, $this->cfg);

      // get template
      $template_file = $this->getTemplate($tb, $context);

      if ($template_file) {
        $html = $this->compileTmpl(PROJ_DIR . 'templates/', $template_file, ['print' => $fieldObj]);
      } else {
        $html = $fieldObj->showall();
      }

      $this->render('record', 'show', [
        'action' => $context,
        'html' => $html,
        'multiple_id' => (count((array)$id) > 1) ? \tr::get('multiple_edit_alert', [count($id), implode('; id: ', $id)]) : false,
        'tb' => $tb,
        'id_url' => is_array($id) ? 'id[]=' . implode('&id[]=', $id) : false,
        'totalRecords' => $total_records,
        'id' => $flag_idfield ? $readRecord->getCore('id', true) : $one_id,
        'can_edit' => (\utils::canUser('edit', $readRecord->getCore('creator', true)) || (count($id) > 1 && \utils::canUser('multiple_edit'))),
        'can_erase' => \utils::canUser('edit', $readRecord->getCore('creator', true)),
        'continue_url' => $continue_url,
        'virtual_keyboard' => $this->cfg->get('main.virtual_keyboard')
      ]);
    }
  }

  private function getTemplate(string $tb, string $context)
  {
    $stripped_tb = str_replace($this->prefix, '', $tb);

    if ($context === 'add_new') {
      $context = 'edit';
    }
    $paths = [
      // preference saved template
      \pref::getTmpl($tb, $context),

      // config, context-bound, template
      $this->cfg->get("tables.{$tb}.tmpl_{$context}"),

      // default, context-bound template: {tb_name}_{context}.twig eg. siti_edit.twig
      $stripped_tb . '_' . $context . '.twig',

      // default, context indipendent template
      $stripped_tb . '.twig'
    ];

    $tmpl = false;

    foreach ($paths as $path) {
      if ($path && file_exists(PROJ_DIR . 'templates/' . $path) && !$tmpl) {
        $tmpl = $path;
      }
    }
    return $tmpl;
  }

  /** @deprecated v5 — replaced by DataView.vue + getRecords() */
  public function showResults()
  {
    if (!\utils::canUser('read')) {
      echo \utils::message(\tr::get('not_enough_privilege'), 'error', true);
      return;
    }

    if (!$this->request['tb']) {
      throw new \Exception(\tr::get('tb_missing'));
    }

    $queryObj = new QueryFromRequest($this->db, $this->cfg, $this->request, true);

    $count = $this->request['total'] ?: $queryObj->getTotal();

    if ($count === 0) {
      $noResult = true;
    }

    list($qq, $vv) = $queryObj->getQuery(true);
    $encoded_query_obj = \SQL\SafeQuery::encode($qq, $vv);

    $this->render('record', 'result', [
      // string, table name
      'tb' => $this->request['tb'],
      // string, total of records found
      'records_found' => ($noResult ? \tr::get('no_record_found') : \tr::get('x_record_found', [$count])),
      // boolean, can current user add new records?
      'can_user_add' => \utils::canUser('add_new'),
      // boolean, can current user read this record?
      'can_user_read' => \utils::canUser('read'),
      // boolean, can current user edit this records?
      'can_user_edit' => \utils::canUser('edit'),

      'encoded_query_obj' => $encoded_query_obj,
      // string, \SQL\SafeQuery encoded query & values, to be used for bookmarking, export, matrix, charts, geoface
      'encoded_where_obj' => $queryObj->getWhereAndValues(),
      // boolean, if no records are found, set to true: no table of results will be output in template
      'noResult' => $noResult,
      // boolean, if true double click on records is not allowed
      'noDblClick' => $this->request['noDblClick'],
      // boolean, if table has or not activated RS plugin
      'hasRS' => $this->cfg->get("tables.{$this->request['tb']}.rs"),
      // boolean, if true option buttons will not be shown in template
      'noOpts' => $this->request['noOpts'],
      // array, list of preview columns, to be used for datatable
      'col_names' => $queryObj->getFields(),
      // int, Total numer of records found, to be used for datatable
      'iTotalRecords' => $count,
      // string, current system language, to be used for datatable
      'lang' => \pref::getLang(),
      // boolean: if true infinite scrolling of databatables will be activated
      'infinte_scrolling' => \pref::get('infinite_scrolling'),
      // boolean: if true only one records can be selected
      'select_one' => $this->request['select_one'],
      // boolean, if true id field will be available in datatables, but hidden
      'hideId' => ($this->cfg->get("tables.{$this->request['tb']}.fields.id.hide") == 1)
    ]);
  }

  /**
   * @deprecated v5 — replaced by DataView.vue + getRecords() (ShortSQL-based, no DataTables dependency)
   *
   * http://datatables.net/usage/server-side
   * 	REQUEST
   * 		obj_encoded
   * 		sEcho: (int)
   * 		iTotalRecords: (int)
   * 		iDisplayStart
   * 		iDisplayLength
   * 		iSortCol_0
   * 		sSortDir_0
   * 		sSearch
   * 		iTotalDisplayRecords
   */
  public function sql2json()
  {
    try {
      $this->request['type'] = 'obj_encoded';

      $qObj = new QueryFromRequest($this->db, $this->cfg, $this->request, true);

      $response['sEcho'] = intval($this->request['sEcho']);
      $response['query_arrived'] = $qObj->getQuery();

      $response['iTotalRecords'] = $response['iTotalDisplayRecords'] = isset($this->request['iTotalRecords']) ? $this->request['iTotalRecords'] : $qObj->getTotal();

      if (isset($this->request['iDisplayStart']) && $this->request['iDisplayLength'] != '-1') {
        $qObj->setLimit(intval($this->request['iDisplayStart']), $this->request['iDisplayLength']);
      }

      if (isset($this->request['iSortCol_0'])) {
        $fields = array_keys($qObj->getFields());
        $qObj->setOrder($fields[$this->request['iSortCol_0']], ($this->request['sSortDir_0'] === 'asc' ? 'asc' : 'desc'));
      }

      if ($this->request['sSearch']) {
        $qObj->setSubQuery($this->request['sSearch']);
        $response['iTotalDisplayRecords'] = $qObj->getTotal();
      }

      $response['query_executed'] = $qObj->getQuery();

      $response['aaData'] = $qObj->getResults();

      foreach ($response['aaData'] as $id => &$row) {
        $response['aaData'][$id]['DT_RowId'] = $row['id'];
      }
    } catch (\Throwable $th) {
      $this->log->error($th);
      $response = [];
    }

    echo json_encode($response);
  }
}
