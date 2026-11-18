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
  /** Max ids returned by getRecords(ids_only=1). */
  private const IDS_ONLY_CAP = 5000;

  /** Default cap of getRecords(limit=…), `limit=-1` included; env BRADYPUS_API_MAX_ROWS overrides it. */
  private const DEFAULT_MAX_ROWS = 5000;

  /** Rows a single getRecords(limit=…) may return: BRADYPUS_API_MAX_ROWS, else 5000. */
  private function maxRows(): int
  {
    $env = getenv('BRADYPUS_API_MAX_ROWS');
    return ($env !== false && ctype_digit($env) && (int) $env > 0) ? (int) $env : self::DEFAULT_MAX_ROWS;
  }

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
   *   data:   [ { id, field1, field2, ..., "@field1"?: string, ... }, ... ]
   * }
   *
   * A lookup field (`id_from_tb`) carries its resolved target label
   * alongside the raw id, under an "@field" key (same convention as
   * \Record\Read::getTbRecord) — the frontend prefers it when rendering.
   *
   * With `ids_only=1` the response is instead
   * { total: int, ids: int[], truncated: bool } — the ordered ids of the whole
   * result set (capped at IDS_ONLY_CAP), for Previous/Next navigation.
   */
  public function getRecords(): void
  {
    $tb = $this->get['tb'] ?? null;

    if (!\Auth\Authorization::can('read', table: $tb)) {
      $this->returnJson(['status' => 'error', 'code' => 'not_enough_privilege']);
      return;
    }

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

    // Directus-style paging: `limit` (-1 = everything, up to the cap) stands in for
    // `per_page` when that is absent; `offset` replaces `page`. Past the cap the page
    // is cut and the response says `truncated`.
    $capped   = false;
    $limitRaw = $this->get['limit'] ?? $this->post['limit'] ?? null;
    if (!isset($this->get['per_page']) && !isset($this->post['per_page'])
        && $limitRaw !== null && is_numeric($limitRaw)) {
      $limit = (int) $limitRaw;
      if ($limit === -1 || $limit > $this->maxRows()) {
        $perPage = $this->maxRows();
        $capped  = true;
      } elseif ($limit >= 1) {
        $perPage = $limit;
      }
    }
    $offsetRaw = $this->get['offset'] ?? $this->post['offset'] ?? null;
    $offset    = ($offsetRaw !== null && is_numeric($offsetRaw))
      ? max(0, (int) $offsetRaw)
      : ($page - 1) * $perPage;

    // Directus-style `sort=a,-b` (or an array of tokens); `sort_field`/`sort_dir` win when given.
    $sortList = [];
    if ($sortFld) {
      $sortList[] = [$sortFld, $sortDir];
    } else {
      $sortRaw = $this->get['sort'] ?? $this->post['sort'] ?? null;
      foreach (is_array($sortRaw) ? $sortRaw : explode(',', (string) $sortRaw) as $tok) {
        $tok = trim((string) $tok);
        $dir = str_starts_with($tok, '-') ? 'desc' : 'asc';
        $tok = preg_replace('/[^a-zA-Z0-9_]/', '', $tok);
        if ($tok !== '') {
          $sortList[] = [$tok, $dir];
        }
      }
    }

    // `search` alone means a fast search.
    if ($searchType === null && trim((string) ($this->get['search'] ?? $this->post['search'] ?? '')) !== '') {
      $searchType = 'fast';
    }

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

    // Directus-style `fields`: columns plus related rows nested in each row
    // (see \Record\Expand). When present it takes the place of `columns`.
    $expand    = null;
    $fieldsRaw = $this->get['fields'] ?? $this->post['fields'] ?? null;
    if (is_string($fieldsRaw) ? trim($fieldsRaw) !== '' : (is_array($fieldsRaw) && $fieldsRaw)) {
      try {
        $expand  = \Record\Expand::parse($fieldsRaw, $this->db, $this->cfg, $tb);
        $colsRaw = $expand->mainColumns();
      } catch (\InvalidArgumentException $e) {
        $this->returnJson(['status' => 'error', 'code' => 'invalid_parameters', 'detail' => $e->getMessage()]);
        return;
      }
    }
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
      // Unknown names are dropped here (not just character-sanitised): a stale
      // client-side column preference — e.g. one carried over in localStorage
      // from another database whose same-named table has different columns —
      // must not turn into `SELECT tb.<missing_column>` and 500 the list.
      $allFields = $this->cfg->get("tables.{$tb}.fields.*") ?? [];
      $colMap = [];
      foreach ($customColumns as $col) {
        $col = preg_replace('/[^a-zA-Z0-9_]/', '', $col);  // sanitise
        if (!$col || $col === 'id' || !isset($allFields[$col])) continue;
        $colMap[$col] = $allFields[$col]['label'] ?? $col;
      }
      // Only override the preview default when at least one real column remains.
      if ($colMap) {
        $qRequest['fields'] = array_merge(['id' => 'id'], $colMap);
      }
    }

    // `fields` naming only relations: just the id (plus what they need), not the preview columns.
    if ($expand && !isset($qRequest['fields'])) {
      $qRequest['fields'] = ['id' => 'id'];
    }

    // use_preview=true unless we are supplying a custom column list
    $usePreview = !isset($qRequest['fields']);

    try {
      $qObj = new \SQL\QueryFromRequest($this->db, $this->cfg, $qRequest, $usePreview, true);

      $total = $qObj->getTotal();

      // Build field list for column headers
      $rawFields = $qObj->getFields();
      $fields = [];
      foreach ($rawFields as $fldName => $fldLabel) {
        $fields[] = ['name' => $fldName, 'label' => $fldLabel ?: $fldName];
      }

      // Ignore unknown sort columns (e.g. a stale client-side preference from
      // another database) — QueryFromRequest guards too, this keeps the intent
      // explicit and logged.
      $knownFields = $this->cfg->get("tables.{$tb}.fields.*.name") ?: [];
      $sortPairs   = [];
      foreach ($sortList as [$f, $d]) {
        if ($f === 'id' || isset($knownFields[$f])) {
          $sortPairs[] = [$f, $d];
        } else {
          $this->log->debug("getRecords: ignoring unknown sort field '{$f}' for table '{$tb}'");
        }
      }
      if (count($sortPairs) === 1) {
        $qObj->setOrder($sortPairs[0][0], $sortPairs[0][1]);
      } elseif ($sortPairs) {
        $qObj->setOrderBy($sortPairs);
      }

      // ids_only: the ordered id list of the WHOLE result set (not one page),
      // used by the record view's Previous/Next navigation. Same query, filter,
      // columns and ORDER BY as the paginated list — columns matter because a
      // FK column is sorted on its resolved label — so the order is identical to
      // what the user saw. Capped; `truncated` tells the client it is partial.
      if (!empty($this->get['ids_only'] ?? $this->post['ids_only'] ?? null)) {
        $qObj->setLimit(0, self::IDS_ONLY_CAP);
        $ids = [];
        foreach ($qObj->getResults() as $row) {
          $id = $row['id'] ?? null;
          $ids[] = (int) (is_array($id) ? ($id['val'] ?? 0) : $id);
        }
        $ids = array_slice($ids, 0, self::IDS_ONLY_CAP);
        $this->returnJson([
          'status'    => 'success',
          'total'     => $total,
          'ids'       => $ids,
          'truncated' => $total > count($ids),
        ]);
        return;
      }

      $qObj->setLimit($offset, $perPage);

      $data = $qObj->getResults();
      if ($expand) {
        try {
          $data = $expand->apply($data);
        } catch (\LengthException $e) {
          $this->returnJson(['status' => 'error', 'code' => 'too_many_nested_rows', 'detail' => $e->getMessage()]);
          return;
        }
      }

      $response = [
        "status"  => "success",
        'total'   => $total,
        'fields'  => $fields,
        'data'    => $data,
        'can_add' => \Auth\Authorization::can('add_new', table: $tb),
        'can'     => \Auth\Authorization::tableAbilities($tb),
      ];
      if ($capped) {
        $response['truncated'] = $total > $offset + $perPage;
      }

      // Directus-style `meta=total_count,filter_count`: counts of the whole table and of the filter
      // (both limited to the rows the principal may read). Only added when asked for.
      $metaRaw = $this->get['meta'] ?? $this->post['meta'] ?? null;
      if ($metaRaw !== null) {
        $want = array_map('trim', is_array($metaRaw) ? $metaRaw : explode(',', (string) $metaRaw));
        $all  = in_array('*', $want, true);
        $meta = [];
        if ($all || in_array('total_count', $want, true)) {
          $meta['total_count'] = (new \SQL\QueryFromRequest($this->db, $this->cfg, ['tb' => $tb, 'type' => 'all']))->getTotal();
        }
        if ($all || in_array('filter_count', $want, true)) {
          $meta['filter_count'] = $total;
        }
        if ($meta) {
          $response['meta'] = $meta;
        }
      }

      $this->returnJson($response);

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
   *     &qt=fast|expert|filter|advanced       (optional — mirrors route.query.qt)
   *     &q=VALUE                              (optional — mirrors route.query.q)
   * (same encoding used by DataView when persisting filter state in the URL).
   */
  public function exportRecords(): void
  {
    $tb     = $this->get['tb']     ?? null;
    $format = $this->get['format'] ?? 'csv';

    if (!\Auth\Authorization::can('read', table: $tb)) {
      $this->returnJson(['status' => 'error', 'code' => 'not_enough_privilege']);
      return;
    }

    if (!$tb) {
      $this->returnJson(['status' => 'error', 'code' => 'parameter_missing']);
      return;
    }

    // An unknown format is an error, never a silent fallback to another one.
    if (!in_array(strtolower((string) $format), \DB\Export\Export::FORMATS, true)) {
      $this->returnJson([
        'status' => 'error',
        'code'   => 'invalid_parameters',
        'detail' => "Unknown export format '{$format}' (expected: " . implode(', ', \DB\Export\Export::FORMATS) . ')',
      ]);
      return;
    }

    // Translate URL-persistence params (qt/q/where) into QueryFromRequest shape.
    // This mirrors the mapping that DataView::applyRouteParams does on the frontend.
    $qt    = $this->get['qt']    ?? null;
    $q     = $this->get['q']     ?? null;
    $where = $this->get['where'] ?? null;

    // Build the field map (name → label) for all configured columns.
    // exportRecords always exports every field; there is no preview-subset mode.
    $exportFields = ['id' => 'id'];
    foreach ($this->cfg->get("tables.{$tb}.fields") ?: [] as $fld) {
      $exportFields[$fld['name']] = $fld['label'] ?? $fld['name'];
    }

    $qRequest = ['tb' => $tb, 'type' => 'all', 'fields' => $exportFields];

    // What narrows the export: the same shapes the list's URL carries, and
    // nothing else — a parameter we cannot read is an error, not "the whole
    // table" (#75). See SQL\ExportSearch.
    try {
      $qRequest = array_merge($qRequest, \SQL\ExportSearch::fromParams($this->get['filter'] ?? null, $qt, $q));
    } catch (\InvalidArgumentException $e) {
      $this->returnJson(['status' => 'error', 'code' => 'invalid_parameters', 'detail' => $e->getMessage()]);
      return;
    }

    try {
      $qObj  = new \SQL\QueryFromRequest($this->db, $this->cfg, $qRequest, false);

      $metadata = [
        'table'  => $tb,
        'filter' => $qt ?? 'all',
        'query'  => $q ?? $where ?? null,
      ];

      $filename = preg_replace('/[^a-zA-Z0-9_\-]/', '_', $tb)
                . '_' . date('Ymd_His');

      // Rows are read and written one at a time: no full result set in memory.
      $exp = \DB\Export\Export::fromIterable($qObj->iterateResults(), $metadata);
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
    $tb = $this->get['tb'] ?? null;
    $id = isset($this->get['id']) ? (int)$this->get['id'] : null;

    if (!\Auth\Authorization::can('read', table: $tb)) {
      $this->returnJson(['status' => 'error', 'code' => 'not_enough_privilege']);
      return;
    }

    if (!$tb) {
      $this->returnJson(['status' => 'error', 'code' => 'parameter_missing']);
      return;
    }

    // A record outside the principal's row scope looks exactly like a forbidden one.
    if ($id && !$this->access()->unrestricted('read', $tb) && !$this->access()->rowAllowed('read', $tb, $id)) {
      $this->returnJson(['status' => 'error', 'code' => 'not_enough_privilege']);
      return;
    }

    try {
      $schema = (new \Config\TableSchema($this->cfg))->describe($tb);

      if ($id) {
        $isPlugin = (bool) $this->cfg->get("tables.{$tb}.is_plugin");
        $reader  = new \Record\Read($id, null, $tb, $this->db, $this->cfg);
        $full    = $reader->getFull();
        // getFull() stores the full field object in metadata.rec_id — fix to int
        $recId   = $full['core']['id']['val'] ?? $id;
        // Needed so self_writer's can_edit/can_delete reflect actual ownership —
        // saveRecord()/erase() enforce the same creator-aware check server-side.
        // Plugin tables have no `creator` of their own (issue #64) — reading it
        // straight from $full['core'] as before always resolved to null for
        // them, hiding Edit/Delete for a self_writer who legitimately owns the
        // host record even though the backend now allows the actual edit/erase.
        // getCore() is cached, so this reuses the query getFull() already ran.
        $creator = $this->resolveOwnerCreator($tb, $reader, $isPlugin);
      } else {
        $full    = $this->buildEmptyRecord($tb, $schema);
        $recId   = null;
        $creator = null;
      }

      $full['metadata']['tb_id']    = $tb;
      $full['metadata']['tb_label'] = $this->cfg->get("tables.{$tb}.label");
      $full['metadata']['rec_id']     = $recId;
      $full['metadata']['id_field']   = $this->cfg->get("tables.{$tb}.id_field");
      // Exactly what saveRecord() and erase() will enforce: tier or group rule,
      // row scope, ownership for a self writer, and locks. `locked` says the
      // record is frozen (admins can still change it).
      if ($id) {
        $full['metadata']['can_edit']   = $this->mayChange('update', $tb, $id, $creator);
        $full['metadata']['can_delete'] = $this->mayChange('delete', $tb, $id, $creator);
        [$gateTb, $gateId] = $this->writeTarget($tb, $id);
        $full['metadata']['locked']     = $gateTb !== null && $this->access()->isLocked($gateTb, $gateId, true);
      } else {
        $full['metadata']['can_edit']   = \Auth\Authorization::can('edit', $creator, $tb);
        $full['metadata']['can_delete'] = \Auth\Authorization::can('edit', $creator, $tb);
        $full['metadata']['locked']     = false;
      }
      $full['metadata']['can_add']    = \Auth\Authorization::can('add_new', table: $tb);
      $full['schema'] = $schema;

      // Template loading
      $appName = $this->cfg->get('main.name') ?? '';
      $tplName = $this->get['template'] ?? null;
      // A group that assigns layouts decides: a template outside them is ignored
      // and, without one, the first assigned layout is used.
      $assigned = $this->access()->layouts($tb, $appName);
      if ($assigned !== null && !in_array($tplName, $assigned, true)) {
        $tplName = $assigned[0];
      }
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
        $imageExts = ['png', 'jpeg', 'jpg', 'gif', 'webp', 'avif', 'bmp', 'ico', 'tif', 'tiff', 'svg'];
        foreach ($full['files'] as &$file) {
          $file['is_image'] = \in_array(\strtolower($file['ext'] ?? ''), $imageExts, true);
        }
        unset($file);
      }
      $this->hideUnreadableRelations($full, $tb);

      $full['links']       = (object)($full['links']       ?? []);
      $full['backlinks']   = (object)($full['backlinks']   ?? []);
      $full['manualLinks'] = (object)($full['manualLinks'] ?? []);
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
   * GET /api/record/{tb}/field-options?fld=FIELD[&q=TEXT][&limit=N][&include=ID;ID]
   *
   * Response: { status, options: [{ value, label }, ...], truncated: bool }
   * The values of a column and the records of a lookup table are limited to
   * `limit` (default 100, max 500); `truncated` flags a partial list, `q`
   * searches all of it, `include` adds selected lookup records outside the page.
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
      // Lists that grow with the data come in pages: `limit` (default 100, max 500),
      // `q` searches the whole list on the server, `include` keeps the selected
      // lookup records in. `truncated` tells the client the list is partial.
      $limit = isset($this->get['limit']) && ctype_digit((string) $this->get['limit'])
        ? (int) $this->get['limit'] : \Record\FieldOptions::DEFAULT_LIMIT;
      $limit = max(1, min($limit, \Record\FieldOptions::MAX_LIMIT));
      $q       = isset($this->get['q']) && is_string($this->get['q']) ? $this->get['q'] : null;
      $include = isset($this->get['include']) && is_string($this->get['include']) ? $this->get['include'] : null;

      $page = (new \Record\FieldOptions($this->db, $this->cfg, $this->access()))->resolvePage($tb, $fld, $q, $limit, $include);
      $this->returnJson(["status" => "success", "options" => $page['options'], "truncated" => $page['truncated']]);
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
    $tb    = $this->get['tb']    ?? null;

    // Uniqueness is checked against every row, whatever the caller may read:
    // the answer is a bare boolean and must reflect the real constraint.
    if (!\Auth\Authorization::can('read', table: $tb)) {
      $this->returnJson(['status' => 'error', 'code' => 'not_enough_privilege']);
      return;
    }

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

    if (!\Auth\Authorization::can('read', table: $tb)) {
      $this->returnJson(['status' => 'error', 'code' => 'not_enough_privilege']);
      return;
    }

    if (!$tb) {
      $this->returnJson(['status' => 'error', 'code' => 'parameter_missing']);
      return;
    }

    $appName   = $this->cfg->get('main.name') ?? '';
    $templates = \Template\Loader::listAvailable($appName, $tb);

    // Layouts assigned to the principal's groups (interface convenience, not a
    // permission): only those are offered, and a single one is not a choice.
    $assigned = $this->access()->layouts($tb, $appName);
    if ($assigned !== null) {
      $this->returnJson([
        "status"    => "success",
        "templates" => $assigned,
        "assigned"  => true,
        "locked"    => count($assigned) === 1,
      ]);
      return;
    }

    $this->returnJson(["status" => "success", "templates" => $templates, "assigned" => false, "locked" => false]);
  }

  // ── Private helpers ───────────────────────────────────────────────────────

  /**
   * May the principal see the history of record $id of $tb? Needs read access
   * to the table; when the table's rows are filtered, the record must still
   * exist and be readable (a deleted row cannot be matched against the filter).
   */
  private function mayReadHistory(?string $tb, int $id): bool
  {
    if (!\Auth\Authorization::can('read', table: $tb) || !$tb) {
      return false;
    }
    return $this->access()->unrestricted('read', $tb)
        || ($id > 0 && $this->access()->rowAllowed('read', $tb, $id));
  }

  /**
   * Removes from a record model what points at records the principal may not
   * read: links, backlinks and manual links to hidden records, RS relations
   * to hidden neighbours, and the label of a lookup value whose table is out
   * of reach. Applied to the response only, never to the model used for saving.
   */
  private function hideUnreadableRelations(array &$full, string $tb): void
  {
    $access = $this->access();

    // Lookup labels come from the referenced table.
    foreach ($full['core'] ?? [] as $name => $field) {
      $ref = $this->cfg->get("tables.{$tb}.fields.{$name}.id_from_tb");
      if ($ref && is_array($field) && isset($field['val_label']) && !$access->can('read', $ref)) {
        $full['core'][$name]['val_label'] = null;
      }
    }

    // Summary of a link: a count and a filter that DataView turns into a (row-filtered) list.
    foreach ($full['links'] ?? [] as $refTb => $link) {
      if (!$access->can('read', $refTb)) {
        unset($full['links'][$refTb]);
      } elseif (!$access->unrestricted('read', $refTb)) {
        $total = (new \SQL\QueryFromRequest(
          $this->db, $this->cfg, ['tb' => $refTb, 'type' => 'filter', 'filter' => $link['filter'] ?? []]
        ))->getTotal();
        if ($total > 0) {
          $full['links'][$refTb]['tot'] = $total;
        } else {
          unset($full['links'][$refTb]);
        }
      }
    }

    foreach ($full['backlinks'] ?? [] as $refTb => $back) {
      if (!$access->can('read', $refTb)) {
        unset($full['backlinks'][$refTb]);
        continue;
      }
      $ids   = $access->readableIds($refTb, array_column($back['data'] ?? [], 'id'));
      $data  = array_values(array_filter($back['data'] ?? [], fn($r) => in_array((int) $r['id'], $ids, true)));
      if (empty($data)) {
        unset($full['backlinks'][$refTb]);
        continue;
      }
      $full['backlinks'][$refTb]['data'] = $data;
      $full['backlinks'][$refTb]['tot']  = count($data);
      if (isset($back['filter']['id']['_in'])) {
        $full['backlinks'][$refTb]['filter'] = ['id' => ['_in' => $ids]];
      }
    }

    foreach ($full['manualLinks'] ?? [] as $key => $link) {
      $refTb = $link['tb_id'] ?? null;
      if (!$refTb || !$access->can('read', $refTb)
          || !$access->readableIds($refTb, [(int) ($link['ref_id'] ?? 0)])) {
        unset($full['manualLinks'][$key]);
      }
    }

    if (!empty($full['rs']) && !$access->unrestricted('read', $tb)) {
      $ids = [];
      foreach ($full['rs'] as $rel) {
        $ids[] = (int) $rel['first'];
        $ids[] = (int) $rel['second'];
      }
      $ok = $access->readableIds($tb, $ids);
      foreach ($full['rs'] as $key => $rel) {
        if (!in_array((int) $rel['first'], $ok, true) || !in_array((int) $rel['second'], $ok, true)) {
          unset($full['rs'][$key]);
        }
      }
    }
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

  // ── Duplicate ─────────────────────────────────────────────────────────────

  /**
   * Duplicates an existing record, creating a new one with the same core fields.
   * `id` is omitted (auto-assigned); `creator` is overwritten with the current user.
   *
   * POST /api/record/{tb}/{id}/duplicate
   *
   * Response: { status, code, id: newId }
   */
  public function duplicateRecord(): void
  {
    $tb = $this->get['tb'] ?? null;
    $id = (int)($this->get['id'] ?? 0);

    // A copy is a new record: it needs the create grant, and the source must be readable.
    // (A plugin row is a change to its host record and is judged as one.)
    $isPluginTb = $tb && $this->cfg->get("tables.{$tb}.is_plugin");
    $allowed    = $isPluginTb
      ? \Auth\Authorization::can('add_new') && $id && $this->mayChange('update', $tb, $id)
      : \Auth\Authorization::can('add_new', table: $tb);
    if ($allowed && $tb && $id && !$this->access()->unrestricted('read', $tb)) {
      $allowed = $this->access()->rowAllowed('read', $tb, $id);
    }
    if (!$allowed) {
      $this->returnJson(['status' => 'error', 'code' => 'not_enough_privilege']);
      return;
    }

    if (!$tb || !$id) {
      $this->returnJson(['status' => 'error', 'code' => 'parameter_missing']);
      return;
    }

    $knownTables = array_keys($this->cfg->get('tables') ?? []);
    if (!in_array($tb, $knownTables, true)) {
      $this->returnJson(['status' => 'error', 'code' => 'unknown_table']);
      return;
    }

    try {
      $rows = $this->db->query("SELECT * FROM {$tb} WHERE id = ?", [$id], 'read');
      if (empty($rows)) {
        $this->returnJson(['status' => 'error', 'code' => 'record_not_found']);
        return;
      }

      $source = $rows[0];
      unset($source['id']);

      // Plugin tables (is_plugin=1) have no `creator` column by design —
      // see resolveOwnerCreator() / issue #64. `SELECT *` above wouldn't
      // have included one either, but the unconditional set below used to
      // add it anyway, breaking the INSERT with "no such column".
      if ((bool) $this->cfg->get("tables.{$tb}.is_plugin")) {
        unset($source['creator']);
      } else {
        // `creator` is a nullable FK to bdus_users(id) (ON DELETE SET NULL) —
        // 0 is never a valid user id, so a falsy CurrentUser::id() (API key
        // auth, which has no human identity by design — see issue #56) must
        // map to null, not 0, or the INSERT violates the FK constraint.
        $source['creator'] = \Auth\CurrentUser::id() ?: null;

        // Group rules may pin the value of some fields (#51): the copy is a new record.
        $source = $this->access()->enforceValues('create', $tb, $source, null, true);
        if ($source === null) {
          $this->returnJson(['status' => 'error', 'code' => 'value_not_allowed']);
          return;
        }
      }

      $fields       = array_keys($source);
      $placeholders = implode(', ', array_fill(0, count($fields), '?'));
      $quotedFields = implode(', ', $fields);

      $newId = (int) $this->db->query(
        "INSERT INTO {$tb} ({$quotedFields}) VALUES ({$placeholders})",
        array_values($source),
        'id'
      );

      $this->returnJson(['status' => 'success', 'code' => 'success_duplicated', 'id' => $newId]);

    } catch (\Throwable $e) {
      $this->log->error($e);
      $this->returnJson(['status' => 'error', 'code' => 'db_error', 'detail' => $e->getMessage()]);
    }
  }

  // ── v5 persistence endpoint ──────────────────────────────────────────────

  /** Creator governing the self_writer check; see \Auth\WriteGate::ownerCreator(). */
  private function resolveOwnerCreator(string $tb, \Record\Read $reader, bool $isPlugin): ?int
  {
    return $this->gate()->ownerCreator($tb, $reader, $isPlugin);
  }

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
    // Controller base class already merges JSON body into $this->post
    $tb   = $this->post['tb'] ?? ($this->get['tb'] ?? null);
    $id   = isset($this->post['id']) && $this->post['id'] !== '' && $this->post['id'] !== null
              ? (int) $this->post['id']
              : null;
    $core = $this->post['core'] ?? [];
    $plgs = $this->post['plugins'] ?? [];

    if ($this->invalidJsonBody) {
      $this->returnJson(['status' => 'error', 'code' => 'invalid_json']);
      return;
    }

    // Gate, payload shape, enforced values, validation and the write itself:
    // \Record\Save, shared with every other entry point.
    $this->returnJson(
      (new \Record\Save($this->db, $this->cfg, $this->access(), $this->log))->save($tb, $id, $core, $plgs)
    );
  }

  // ── v5 file management endpoints ─────────────────────────────────────────

  /**
   * Uploads a file and attaches it to a record via a userlink.
   *
   * POST ?obj=record_ctrl&method=uploadFile&tb=TABLE&id=RECORD_ID
   * Multipart body: file=<binary>
   *
   * Response: { status, code, file: { id, ext, filename, url, is_image, description, keywords, attribution, printable } }
   */
  public function uploadFile(): void
  {
    if (!\Auth\Authorization::canOnSomeTable('edit')) {
      $this->returnJson(['status' => 'error', 'code' => 'not_enough_privilege']);
      return;
    }

    $tb = $this->get['tb'] ?? ($this->post['tb'] ?? null);
    $id = $this->get['id'] ?? ($this->post['id'] ?? null);

    if (!$tb || !$id) {
      $this->returnJson(['status' => 'error', 'code' => 'parameter_missing']);
      return;
    }

    if (!$this->mayChange('update', $tb, (int) $id)) {
      $this->returnJson(['status' => 'error', 'code' => 'not_enough_privilege']);
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

      // A reused id (e.g. SQLite after deleting the last row) must not inherit stale derived versions
      \Image\Derivative::purgeFile(PROJ_DIR, (int) $fileId);

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

      // Resize / convert format in-place if configured (main.maxImageSize,
      // main.imageConvert & friends — see Config::__construct()). When
      // conversion changes the extension, bdus_files.ext must follow it.
      $processed = \Image\Resizer::process($destFile, [
        'maxPx'   => (int) ($this->cfg->get('main.maxImageSize') ?? 0),
        'convert' => (bool) ($this->cfg->get('main.imageConvert') ?? false),
        'format'  => (string) ($this->cfg->get('main.imageFormat') ?? 'webp'),
        'quality' => (int) ($this->cfg->get('main.imageQuality') ?? 85),
        'dpi'     => (int) ($this->cfg->get('main.imageDpi') ?? 72),
      ]);
      if ($processed['ext'] !== $ext) {
        $ext      = $processed['ext'];
        $destFile = $processed['path'];
        $this->db->query("UPDATE bdus_files SET ext = ? WHERE id = ?", [$ext, $fileId], 'boolean');
      }

      // Create file link in the dedicated junction table
      $this->db->query(
        "INSERT INTO bdus_file_links (file_id, table_name, record_id) VALUES (?, ?, ?)",
        [$fileId, $tb, (int)$id]
      );

      $imageExts = ['jpg', 'jpeg', 'png', 'gif', 'webp', 'avif', 'bmp', 'ico', 'tif', 'tiff', 'svg'];

      $this->returnJson([
        'status' => 'success',
        'code'   => 'ok_file_uploaded',
        'file'   => [
          'id'          => (int)$fileId,
          'ext'         => $ext,
          'filename'    => $filename,
          'description' => null,
          'keywords'    => null,
          'attribution' => null,
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
    if (!\Auth\Authorization::canOnSomeTable('edit')) {
      $this->returnJson(['status' => 'error', 'code' => 'not_enough_privilege']);
      return;
    }

    $fileId = (int)($this->post['fileId'] ?? $this->get['fileId'] ?? 0);
    if (!$fileId) {
      $this->returnJson(['status' => 'error', 'code' => 'parameter_missing']);
      return;
    }

    // Deleting a file removes it from every record it is attached to: all of them must be changeable.
    foreach ($this->db->query(
      "SELECT table_name, record_id FROM bdus_file_links WHERE file_id = ?", [$fileId], 'read'
    ) ?: [] as $link) {
      if (!$this->mayChange('update', (string) $link['table_name'], (int) $link['record_id'])) {
        $this->returnJson(['status' => 'error', 'code' => 'not_enough_privilege']);
        return;
      }
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

      // Derived versions die with their parent
      \Image\Derivative::purgeFile(PROJ_DIR, (int) $fileId);

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

  /**
   * Links an existing file to a record (creates a bdus_file_links row without
   * uploading a new binary).
   *
   * POST /api/record/{tb}/{id}/link-file
   * Body (JSON): { fileId: int }
   *
   * Response: { status, code, file: { id, link_id, ext, filename, description,
   *             keywords, attribution, printable, is_image } }
   */
  public function linkFile(): void
  {
    if (!\Auth\Authorization::canOnSomeTable('edit')) {
      $this->returnJson(['status' => 'error', 'code' => 'not_enough_privilege']);
      return;
    }

    $tb     = $this->get['tb'] ?? null;
    $id     = (int)($this->get['id']      ?? 0);
    $fileId = (int)($this->post['fileId'] ?? 0);

    if (!$tb || !$id || !$fileId) {
      $this->returnJson(['status' => 'error', 'code' => 'parameter_missing']);
      return;
    }

    if (!$this->mayChange('update', $tb, $id)) {
      $this->returnJson(['status' => 'error', 'code' => 'not_enough_privilege']);
      return;
    }

    try {
      $rows = $this->db->query("SELECT * FROM bdus_files WHERE id = ?", [$fileId]);
      if (empty($rows)) {
        $this->returnJson(['status' => 'error', 'code' => 'record_not_found']);
        return;
      }
      $f   = $rows[0];
      $ext = $f['ext'] ?? '';

      $linkId = $this->db->query(
        "INSERT INTO bdus_file_links (file_id, table_name, record_id) VALUES (?, ?, ?)",
        [$fileId, $tb, $id],
        'id'
      );

      $imageExts = ['jpg', 'jpeg', 'png', 'gif', 'webp', 'avif', 'bmp', 'ico', 'tif', 'tiff', 'svg'];

      $this->returnJson([
        'status' => 'success',
        'code'   => 'ok_file_linked',
        'file'   => [
          'id'          => (int)$fileId,
          'link_id'     => (int)$linkId,
          'ext'         => $ext,
          'filename'    => $f['filename'] ?? '',
          'description' => $f['description'] ?? null,
          'keywords'    => $f['keywords']    ?? null,
          'attribution' => $f['attribution'] ?? null,
          'printable'   => $f['printable']   ?? null,
          'is_image'    => in_array($ext, $imageExts, true),
        ],
      ]);
    } catch (\Throwable $e) {
      $this->log->error($e);
      $this->returnJson(['status' => 'error', 'code' => 'error_file_linked', 'detail' => $e->getMessage()]);
    }
  }

  /**
   * Removes the link between a file and a record (deletes the bdus_file_links
   * row only — the file binary and bdus_files record are preserved).
   *
   * DELETE /api/file-link/{linkId}
   *
   * Response: { status, code }
   */
  public function unlinkFile(): void
  {
    if (!\Auth\Authorization::canOnSomeTable('edit')) {
      $this->returnJson(['status' => 'error', 'code' => 'not_enough_privilege']);
      return;
    }

    $linkId = (int)($this->get['linkId'] ?? 0);
    if (!$linkId) {
      $this->returnJson(['status' => 'error', 'code' => 'parameter_missing']);
      return;
    }

    try {
      $rows = $this->db->query("SELECT id, table_name, record_id FROM bdus_file_links WHERE id = ?", [$linkId]);
      if (empty($rows)) {
        $this->returnJson(['status' => 'error', 'code' => 'record_not_found']);
        return;
      }
      if (!$this->mayChange('update', (string) $rows[0]['table_name'], (int) $rows[0]['record_id'])) {
        $this->returnJson(['status' => 'error', 'code' => 'not_enough_privilege']);
        return;
      }

      $this->db->query("DELETE FROM bdus_file_links WHERE id = ?", [$linkId]);
      $this->returnJson(['status' => 'success', 'code' => 'ok_file_unlinked']);

    } catch (\Throwable $e) {
      $this->log->error($e);
      $this->returnJson(['status' => 'error', 'code' => 'error_file_unlinked', 'detail' => $e->getMessage()]);
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
    if (!\Auth\Authorization::canOnSomeTable('edit')) {
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

    try {
      // Table, relation code, the two records, the rights over both, duplicates: \Record\Rs.
      $res = (new \Record\Rs($this->db, $this->cfg, $this->access()))
        ->add((string) $tb, (int) $first, (int) $relation, (int) $second);

      $this->returnJson($res['status'] === 'success'
        ? ['status' => 'success', 'code' => 'ok_relation_add', 'id' => $res['id']]
        : $res);

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
    if (!\Auth\Authorization::canOnSomeTable('edit')) {
      $this->returnJson(['status' => 'error', 'code' => 'not_enough_privilege']);
      return;
    }

    $id = $this->post['id'] ?? ($this->get['id'] ?? null);
    if (!$id) {
      $this->returnJson(['status' => 'error', 'code' => 'parameter_missing']);
      return;
    }

    try {
      $res = (new \Record\Rs($this->db, $this->cfg, $this->access()))->remove((int) $id);

      $this->returnJson($res['status'] === 'success'
        ? ['status' => 'success', 'code' => 'ok_relation_erased']
        : $res);

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
    $tb = $this->get['tb'] ?? null;
    $q  = $this->get['q']  ?? null;

    if (!\Auth\Authorization::can('read', table: $tb)) {
      $this->returnJson(['status' => 'error', 'code' => 'not_enough_privilege']);
      return;
    }

    if (!$tb || !$this->cfg->get("tables.$tb")) {
      $this->returnJson(['status' => 'error', 'code' => 'parameter_missing']);
      return;
    }

    $idFld = $this->cfg->get("tables.$tb.id_field") ?? 'id';
    [$andReadable, $readableValues] = $this->andReadable($tb);

    try {
      if ($idFld === 'id') {
        if ($q !== null && $q !== '') {
          $sql    = "SELECT id, id AS label FROM {$tb} WHERE id = ?{$andReadable} LIMIT 20";
          $values = array_merge([(int)$q], $readableValues);
        } else {
          $sql    = "SELECT id, id AS label FROM {$tb} WHERE 1=1{$andReadable} ORDER BY id DESC LIMIT 20";
          $values = $readableValues;
        }
      } else {
        if ($q !== null && $q !== '') {
          $like   = \SQL\Like::operator($this->db->getEngine());
          $sql    = "SELECT id, {$idFld} AS label FROM {$tb} WHERE {$idFld} {$like} ?{$andReadable} LIMIT 20";
          $values = array_merge(["%$q%"], $readableValues);
        } else {
          $sql    = "SELECT id, {$idFld} AS label FROM {$tb} WHERE 1=1{$andReadable} ORDER BY id DESC LIMIT 20";
          $values = $readableValues;
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
    if (!\Auth\Authorization::canOnSomeTable('edit')) {
      $this->returnJson(['status' => 'error', 'code' => 'not_enough_privilege']);
      return;
    }

    $tbOne = $this->post['tb_one'] ?? null;
    $idOne = $this->post['id_one'] ?? null;
    $tbTwo = $this->post['tb_two'] ?? null;
    $idTwo = $this->post['id_two'] ?? null;
    $label = isset($this->post['label']) && $this->post['label'] !== ''
               ? (string)$this->post['label']
               : null;

    if (!$tbOne || !$idOne || !$tbTwo || !$idTwo) {
      $this->returnJson(['status' => 'error', 'code' => 'parameter_missing']);
      return;
    }

    $idOne  = (int)$idOne;
    $idTwo  = (int)$idTwo;

    // A link shows on both records: both must be changeable.
    if (!$this->mayChange('update', (string) $tbOne, $idOne) || !$this->mayChange('update', (string) $tbTwo, $idTwo)) {
      $this->returnJson(['status' => 'error', 'code' => 'not_enough_privilege']);
      return;
    }

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
        "INSERT INTO bdus_userlinks (tb_one, id_one, tb_two, id_two, label) VALUES (?, ?, ?, ?, ?)",
        [$tbOne, $idOne, $tbTwo, $idTwo, $label],
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

      $this->returnJson([
        'status' => 'success',
        'code'   => 'all_links_saved',
        'link'   => [
          'key'      => $newId,
          'tb_id'    => $tbTwo,
          'tb_label' => $this->cfg->get("tables.$tbTwo.label"),
          'ref_id'     => $idTwo,
          'ref_label'  => $refLabel,
          'sort'       => null,
          'label'      => $label,
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
    if (!\Auth\Authorization::canOnSomeTable('edit')) {
      $this->returnJson(['status' => 'error', 'code' => 'not_enough_privilege']);
      return;
    }

    $id = $this->post['id'] ?? ($this->get['id'] ?? null);
    if (!$id) {
      $this->returnJson(['status' => 'error', 'code' => 'parameter_missing']);
      return;
    }

    $link = $this->db->query("SELECT tb_one, id_one, tb_two, id_two FROM bdus_userlinks WHERE id = ?", [(int) $id], 'read')[0] ?? null;
    if ($link && (!$this->mayChange('update', (string) $link['tb_one'], (int) $link['id_one'])
               || !$this->mayChange('update', (string) $link['tb_two'], (int) $link['id_two']))) {
      $this->returnJson(['status' => 'error', 'code' => 'not_enough_privilege']);
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
    $tb = $this->get['tb'] ?? null;

    if (!\Auth\Authorization::can('read', table: $tb)) {
      $this->returnJson(['status' => 'error', 'code' => 'not_enough_privilege']);
      return;
    }

    if (!$tb) {
      $this->returnJson(['status' => 'error', 'code' => 'parameter_missing']);
      return;
    }

    if (!$this->cfg->get("tables.{$tb}.rs")) {
      $this->returnJson(['status' => 'error', 'code' => 'rs_not_configured']);
      return;
    }

    $idField = $this->cfg->get("tables.{$tb}.id_field") ?? 'id';
    [$andReadable, $readableValues] = $this->andReadable($tb);

    try {
      // ── Step 1: resolve which records to include ───────────────────────────
      $filterRaw  = $this->get['filter'] ?? null;
      if (is_string($filterRaw)) {
        $filterRaw = json_decode($filterRaw, true);
      }
      $searchType = $this->get['search_type'] ?? 'all';
      $filtered   = is_array($filterRaw) || ($searchType !== 'all');

      $dbIds = [];
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
        list($subSql, $subVals) = $qObj->getQuery(true);
        $idRows = $this->db->query(
          "SELECT id FROM ({$subSql}) AS _rs_sub",
          $subVals
        ) ?: [];
        $dbIds = array_map('intval', array_column($idRows, 'id'));
      }

      // ── Step 2: fetch id_field labels for the filter set ──────────────────
      // filterNodes: db_id (int) → identifier string (for display)
      $filterNodes = [];

      if ($filtered && empty($dbIds)) {
        $this->returnJson(["status" => "success", "has_fuzzy_date" => false, "nodes" => [], "relations" => []]);
        return;
      }

      if ($filtered) {
        $ph   = implode(',', array_fill(0, count($dbIds), '?'));
        $rows = $this->db->query(
          "SELECT id, {$idField} AS identifier FROM {$tb} WHERE id IN ({$ph})",
          $dbIds
        ) ?: [];
      } else {
        $rows = $this->db->query(
          "SELECT id, {$idField} AS identifier FROM {$tb} WHERE 1=1{$andReadable}",
          $readableValues
        ) ?: [];
      }

      foreach ($rows as $r) {
        $filterNodes[(int)$r['id']] = (string)($r['identifier'] ?? $r['id']);
      }

      $filterDbIds = array_keys($filterNodes);

      // ── Step 3: fetch RS relations (first/second are now integer db_ids) ──
      if ($filtered && !empty($filterDbIds)) {
        $ph = implode(',', array_fill(0, count($filterDbIds), '?'));
        $relations = $this->db->query(
          "SELECT id, first, second, relation FROM bdus_rs
           WHERE tb = ? AND (first IN ({$ph}) OR second IN ({$ph}))",
          array_merge([$tb], $filterDbIds, $filterDbIds)
        ) ?: [];
      } else {
        $relations = $this->db->query(
          "SELECT id, first, second, relation FROM bdus_rs WHERE tb = ?",
          [$tb]
        ) ?: [];
      }

      // ── Step 4: collect all unique db_ids (including dangling from relations)
      $allNodes    = $filterNodes; // db_id => identifier
      $danglingIds = [];

      foreach ($relations as $rel) {
        foreach ([(int)$rel['first'], (int)$rel['second']] as $rId) {
          if (!isset($allNodes[$rId])) {
            $danglingIds[] = $rId;
          }
        }
      }

      if (!empty($danglingIds)) {
        // array_unique() keeps the original (non-sequential) keys of the first
        // occurrence of each value; PDO's positional binding requires a plain
        // sequential array, so re-index before using it as query params.
        $danglingIds = array_values(array_unique($danglingIds));
        $ph          = implode(',', array_fill(0, count($danglingIds), '?'));
        // Neighbours outside the principal's row scope are not shown; the
        // relations that point at them are dropped below with the dangling ones.
        $dRows       = $this->db->query(
          "SELECT id, {$idField} AS identifier FROM {$tb} WHERE id IN ({$ph}){$andReadable}",
          array_merge($danglingIds, $readableValues)
        ) ?: [];
        foreach ($dRows as $r) {
          $allNodes[(int)$r['id']] = (string)($r['identifier'] ?? $r['id']);
        }
      }

      // ── Step 5: build response ─────────────────────────────────────────────
      $hasFuzzyDate = (bool)$this->cfg->get("tables.{$tb}.fuzzy_date");

      $chronoByDbId = [];
      if ($hasFuzzyDate && !empty($allNodes)) {
        try {
          $allDbIds = array_keys($allNodes);
          $ph       = implode(',', array_fill(0, count($allDbIds), '?'));
          $cRows    = $this->db->query(
            "SELECT id, chrono_from, chrono_to, chrono_label FROM {$tb} WHERE id IN ({$ph})",
            $allDbIds
          ) ?: [];
          foreach ($cRows as $cr) {
            $chronoByDbId[(int)$cr['id']] = [
              'chrono_from'  => $cr['chrono_from'] !== null ? (int)$cr['chrono_from'] : null,
              'chrono_to'    => $cr['chrono_to']   !== null ? (int)$cr['chrono_to']   : null,
              'chrono_label' => $cr['chrono_label'] ?? null,
            ];
          }
        } catch (\Throwable $e) {
          $hasFuzzyDate = false;
        }
      }

      $nodes = [];
      foreach ($allNodes as $dbId => $ident) {
        $node = [
          'db_id'      => $dbId,
          'identifier' => $ident,
          'in_filter'  => isset($filterNodes[$dbId]),
        ];
        if ($hasFuzzyDate && isset($chronoByDbId[$dbId])) {
          $node = array_merge($node, $chronoByDbId[$dbId]);
        }
        $nodes[] = $node;
      }

      // Skip relations pointing at a record id that doesn't resolve to any
      // existing row (e.g. a stratigraphic unit renumbered/deleted long ago,
      // still referenced by a stale bdus_rs row) — a dangling endpoint here
      // would otherwise reach the frontend graph as an edge with no node.
      $relationsOut = [];
      foreach ($relations as $rel) {
        $first  = (int)$rel['first'];
        $second = (int)$rel['second'];
        if (!isset($allNodes[$first]) || !isset($allNodes[$second])) {
          continue;
        }
        $relationsOut[] = [
          'id'       => (int)$rel['id'],
          'first'    => $first,
          'second'   => $second,
          'relation' => (int)$rel['relation'],
        ];
      }

      $this->returnJson([
        "status"         => "success",
        'has_fuzzy_date' => $hasFuzzyDate,
        'nodes'          => $nodes,
        'relations'      => $relationsOut,
      ]);

    } catch (\Throwable $e) {
      $this->log->error($e);
      $this->returnJson(['status' => 'error', 'code' => 'db_error', 'detail' => $e->getMessage()]);
    }
  }

  /**
   * Deletes all plugin rows referencing a given record across every plugin table.
   * Called by the frontend after the user confirms the two-step delete dialog.
   *
   * DELETE /api/record/{tb}/{id}/plugins
   *
   * Response: { status: 'success', deleted: N }
   */
  public function erasePlugins(): void
  {
    $tb  = $this->get['tb']  ?? null;
    $id  = (int)($this->get['id'] ?? 0);

    if (!\Auth\Authorization::canOnSomeTable('edit')) {
      $this->returnJson(['status' => 'error', 'code' => 'not_enough_privilege']);
      return;
    }

    if (!$tb || !$id) {
      $this->returnJson(['status' => 'error', 'code' => 'parameter_missing']);
      return;
    }

    if (!$this->mayChange('delete', $tb, $id)) {
      $this->returnJson(['status' => 'error', 'code' => 'not_enough_privilege']);
      return;
    }

    $deleted = 0;
    foreach ($this->cfg->get("tables.$tb.plugin") ?: [] as $plgName) {
      try {
        $affected = $this->db->query(
          "DELETE FROM {$plgName} WHERE id_link = ?",
          [$id],
          'affected'
        );
        $deleted += (int)$affected;
      } catch (\Throwable $e) {
        // Plugin table might not exist yet; skip silently.
      }
    }

    $this->returnJson(['status' => 'success', 'deleted' => $deleted]);
  }

  /**
   * Checks whether a record has data in any plugin tables before deletion.
   * The frontend calls this before erase() to show a confirmation dialog.
   *
   * GET /api/record/{tb}/check-plugins-before-delete?id=N
   *
   * Response: {
   *   status: 'success',
   *   plugins: [ { tb, label, count } ]   // only tables with count > 0
   * }
   */
  public function checkPluginsBeforeDelete(): void
  {
    $tb  = $this->get['tb'] ?? null;
    $id  = (int)($this->get['id'] ?? 0);

    if (!\Auth\Authorization::canOnSomeTable('edit')) {
      $this->returnJson(['status' => 'error', 'code' => 'not_enough_privilege']);
      return;
    }

    if (!$tb || !$id) {
      $this->returnJson(['status' => 'error', 'code' => 'parameter_missing']);
      return;
    }

    if (!$this->mayChange('delete', $tb, $id)) {
      $this->returnJson(['status' => 'error', 'code' => 'not_enough_privilege']);
      return;
    }

    $plugins = [];
    foreach ($this->cfg->get("tables.$tb.plugin") ?: [] as $plgName) {
      try {
        $result = $this->db->query(
          "SELECT COUNT(*) AS cnt FROM {$plgName} WHERE id_link = ?",
          [$id],
          'read'
        );
        $cnt = (int)($result[0]['cnt'] ?? 0);
        if ($cnt > 0) {
          $plugins[] = [
            'tb'    => $plgName,
            'label' => $this->cfg->get("tables.$plgName.label") ?: $plgName,
            'count' => $cnt,
          ];
        }
      } catch (\Throwable $e) {
        // Plugin table might not exist yet; skip silently.
      }
    }

    $this->returnJson(['status' => 'success', 'plugins' => $plugins]);
  }

  public function erase(): void
  {
    $tb  = $this->get['tb'] ?? null;
    $raw = $this->get['id'] ?? null;

    if (!$tb || !$raw) {
      $this->returnJson(['status' => 'error', 'code' => 'no_id_provided']);
      return;
    }

    // Fast path (writer and up): no per-record ownership check needed.
    // self_writer fails this and falls through to the per-id check below,
    // where each record's own creator decides. If even the best case —
    // as if this user owned the record — still fails, nothing in the
    // batch could ever be deletable, so reject upfront instead of doing
    // a wasted Read per id.
    $hasBlanketEdit = \Auth\Authorization::can('edit', table: $tb);
    if (!$hasBlanketEdit && !\Auth\Authorization::can('edit', \Auth\CurrentUser::id(), $tb)) {
      $this->returnJson(['status' => 'error', 'code' => 'not_enough_privilege']);
      return;
    }

    if (!in_array($tb, $this->cfg->get('tables.*.name') ?? [], true)) {
      $this->returnJson(['status' => 'error', 'code' => 'no_record_deleted']);
      return;
    }

    // Accept both a single id and an array of ids
    $ids = is_array($raw) ? array_map('intval', $raw) : [(int)$raw];

    // Plugin tables (is_plugin=1) have no `creator` of their own — see
    // resolveOwnerCreator() / issue #64. Resolved once here, outside the
    // loop, since it's the same for every id in this batch (all deleted
    // from the same $tb).
    $deleter = new \Record\Delete($this->db, $this->cfg, $this->access());

    $ok    = [];
    $error = [];

    foreach ($ids as $id) {
      if (!$id) continue;
      try {
        // Existence, ownership, row scope, locks and the delete itself: \Record\Delete.
        if ($deleter->delete($tb, $id) === \Record\Delete::DELETED) {
          $ok[] = $id;
        } else {
          $error[] = $id;
        }
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
    $tb = $this->get['tb'] ?? null;

    // A deleted row cannot be matched against a row filter, so the list is
    // limited to principals who may read the whole table.
    if (!\Auth\Authorization::can('read', table: $tb)
        || ($tb && !$this->access()->unrestricted('read', $tb))) {
      $this->returnJson(['status' => 'error', 'code' => 'not_enough_privilege']);
      return;
    }

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
    $tb  = $this->get['tb']  ?? null;
    $id  = (int)($this->get['id'] ?? 0);

    if (!\Auth\Authorization::can('read', table: $tb)) {
      $this->returnJson(['status' => 'error', 'code' => 'not_enough_privilege']);
      return;
    }

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

    if (!$this->mayReadHistory($tb, $id)) {
      $this->returnJson(['status' => 'error', 'code' => 'not_enough_privilege']);
      return;
    }

    try {
      $rows = $this->db->query(
        "SELECT * FROM bdus_versions WHERE tb = ? AND rowid = ? ORDER BY id DESC",
        [$tb, $id]
      ) ?: [];
      // `channel` is there from M053 on; the snapshot itself is served by getVersionDiff().
      $rows = array_map(fn($r) => array_intersect_key(
        $r,
        array_flip(['id', 'userid', 'time', 'operation', 'channel'])
      ), $rows);

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
    // Before touching the version (its existence must not leak): can this principal read anything?
    if (!\Auth\Authorization::canOnSomeTable('read')) {
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

      if (!$this->mayReadHistory($tb, $rid)) {
        $this->returnJson(['status' => 'error', 'code' => 'not_enough_privilege']);
        return;
      }
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
          "SELECT * FROM {$pluginName} WHERE id_link = ?",
          [$rid]
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

      // The entry of a creation holds no state to go back to.
      if (($ver['operation'] ?? '') === 'create') {
        $this->returnJson(['status' => 'error', 'code' => 'version_not_restorable']);
        return;
      }

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
          "SELECT * FROM {$pluginName} WHERE id_link = ?",
          [$rid]
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
              "DELETE FROM {$pluginName} WHERE id_link = ?",
              [$rid], 'boolean'
            );
            foreach ($snapshotRows as $r) {
              unset($r['id'], $r['table_link']);
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
              "DELETE FROM {$pluginName} WHERE id_link = ?",
              [$rid], 'boolean'
            );
            foreach ($snapshotPlugins[$pluginName] as $r) {
              unset($r['id'], $r['table_link']);
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
