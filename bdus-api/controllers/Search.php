<?php

namespace Bdus\Controllers;

/**
 * @copyright 2007-2022 Julian Bogdani
 * @license AGPL-3.0; see LICENSE
 * @since			Sep 11, 2012
 */

class Search extends \Bdus\Controller
{

  // ──────────────────────────────────────────────────────────────────────────
  // Vue v5 API methods
  // ──────────────────────────────────────────────────────────────────────────

  /**
   * Returns the configuration needed to build the advanced-search UI:
   * field list (main table + plugins), operator list, connector list.
   *
   * GET ?obj=search_ctrl&method=getAdvancedConfig&tb=TABLE
   *
   * Response:
   * {
   *   fields:     [ { value: "tb:field", label: "Field label",
   *                   ref_tb?: "categories", ref_field?: "name" }, ... ],
   *   operators:  [ { value: "_icontains", key: "contains" }, ... ],
   *   connectors: [ "AND", "OR" ]
   * }
   *
   * ref_tb / ref_field are present only on lookup fields (id_from_tb).
   */
  public function getAdvancedConfig(): void
  {
    $tb = $this->get['tb'] ?? null;
    if (!$tb) {
      $this->returnJson(['status' => 'error', 'code' => 'parameter_missing']);
      return;
    }

    // Main-table fields
    $fields = [];
    foreach ((array) $this->cfg->get("tables.{$tb}.fields") as $f) {
      $fields[] = $this->advFieldEntry($tb, $f);
    }

    // Plugin fields grouped by plugin label
    $plugins = $this->cfg->get("tables.{$tb}.plugin");
    if (is_array($plugins)) {
      foreach ($plugins as $plg) {
        $plgLabel = $this->cfg->get("tables.{$plg}.label") ?: $plg;
        foreach ((array) $this->cfg->get("tables.{$plg}.fields") as $f) {
          $fields[] = $this->advFieldEntry($plg, $f, $plgLabel . ' › ');
        }
      }
    }

    // Operators: value is the JsonFilter operator, key is the i18n locale key.
    // The frontend is responsible for translating key → label via t(key).
    $operators = [
      ['value' => '_icontains',  'key' => 'contains'],
      ['value' => '_eq',         'key' => 'is_exactly'],
      ['value' => '_ncontains',  'key' => 'doesnt_contain'],
      ['value' => '_starts_with', 'key' => 'starts_with'],
      ['value' => '_ends_with',   'key' => 'ends_with'],
      ['value' => '_nstarts_with','key' => 'not_starts_with'],
      ['value' => '_nends_with',  'key' => 'not_ends_with'],
      ['value' => '_empty',      'key' => 'is_empty'],
      ['value' => '_nempty',     'key' => 'is_not_empty'],
      ['value' => '_gt',         'key' => 'bigger'],
      ['value' => '_lt',         'key' => 'smaller'],
    ];

    // Connectors: AND/OR are language-agnostic technical terms.
    // XOR was dropped in v5: unused in practice and unsupported by JsonFilter.
    $connectors = ['AND', 'OR'];
    $resp = compact('fields', 'operators', 'connectors');
    $resp['status'] = 'success';

    $this->returnJson($resp);
  }

  /**
   * Builds one entry of the advanced-search field list.
   *
   * Lookup fields (id_from_tb) additionally carry ref_tb / ref_field so the
   * frontend can emit a nested JsonFilter traversal
   * ({ field: { ref_field: { _op: value } } }) — these fields store the id of
   * the referenced record, while autocomplete suggests the referenced table's
   * id_field values.
   */
  private function advFieldEntry(string $tb, array $f, string $labelPrefix = ''): array
  {
    $entry = [
      'value' => "{$tb}:{$f['name']}",
      'label' => $labelPrefix . (($f['label'] ?? '') ?: $f['name']),
    ];
    if (!empty($f['id_from_tb'])) {
      $refTb = $f['id_from_tb'];
      $entry['ref_tb']    = $refTb;
      $entry['ref_field'] = $this->cfg->get("tables.{$refTb}.id_field") ?: 'id';
    }
    return $entry;
  }

  // ──────────────────────────────────────────────────────────────────────────
  // Shared methods (used by both v4 and v5)
  // ──────────────────────────────────────────────────────────────────────────

  public function getUsedValues(): void
  {
    $tb  = $this->get['tb']  ?? null;
    $fld = $this->get['fld'] ?? null;

    if (!$tb || !$fld) {
      $this->returnJson(['status' => 'error', 'code' => 'parameter_missing']);
      return;
    }

    try {
      // If the field references another table, fetch values from there instead.
      $second_table = $this->cfg->get("tables.{$tb}.fields.{$fld}.id_from_tb");

      if ($second_table) {
        $srcTb  = $second_table;
        $srcFld = $this->cfg->get("tables.{$second_table}.id_field");
      } else {
        $srcTb  = $tb;
        $srcFld = $fld;
      }

      // Identifiers are interpolated into SQL: accept plain names only.
      foreach ([$srcTb, $srcFld] as $ident) {
        if (!is_string($ident) || !preg_match('/^[A-Za-z_][A-Za-z0-9_]*$/', $ident)) {
          $this->returnJson(['status' => 'error', 'code' => 'parameter_invalid']);
          return;
        }
      }

      // The values come from $srcTb: only rows the principal may read contribute.
      if (!\Auth\Authorization::can('read', table: $srcTb)) {
        $this->returnJson(['status' => 'error', 'code' => 'not_enough_privilege']);
        return;
      }
      [$andReadable, $readableValues] = $this->andReadable($srcTb);

      $search = isset($this->get['q']) && is_string($this->get['q']) ? $this->get['q'] : '';
      $hasLimit = isset($this->get['limit']) && $this->get['limit'] !== '';

      if ($search === '' && !$hasLimit) {
        // Legacy behaviour: every distinct value, unfiltered.
        $q = $second_table
          ? "SELECT {$srcFld} AS val FROM {$srcTb} WHERE 1=1{$andReadable} GROUP BY {$srcFld}"
          : "SELECT {$srcFld} AS val FROM {$srcTb} WHERE {$srcFld} IS NOT NULL{$andReadable} GROUP BY {$srcFld}";
        $params = $readableValues;
      } else {
        $engine = $this->db->getEngine();
        $text   = 'CAST(' . $srcFld . ' AS ' . ($engine === 'mysql' ? 'CHAR' : 'TEXT') . ')';
        $limit  = $hasLimit ? (int) $this->get['limit'] : 0;
        if ($limit < 1) {
          $limit = 50;
        }
        $limit = min($limit, 500);

        $where  = "{$srcFld} IS NOT NULL AND {$text} <> ''{$andReadable}";
        $order  = '';
        $params = $readableValues;
        $outerParams = [];
        if ($search !== '') {
          $like    = \SQL\Like::operator($engine);
          $escaped = str_replace(['!', '%', '_'], ['!!', '!%', '!_'], $search);
          $where  .= " AND {$text} {$like} ? ESCAPE '!'";
          $params[] = '%' . $escaped . '%';
          // Values that start with q rank before those that merely contain it.
          $order = "CASE WHEN CAST(val AS " . ($engine === 'mysql' ? 'CHAR' : 'TEXT') . ") {$like} ? ESCAPE '!' THEN 0 ELSE 1 END, ";
          $outerParams[] = $escaped . '%';
        }
        $q = "SELECT val FROM (SELECT DISTINCT {$srcFld} AS val FROM {$srcTb} WHERE {$where}) s "
          . "ORDER BY {$order}val LIMIT {$limit}";
        $params = array_merge($params, $outerParams);
      }

      $rows   = $this->db->query($q, $params);
      $values = array_column($rows ?? [], 'val');

      $this->returnJson(['status' => 'success', 'values' => $values]);
    } catch (\Throwable $e) {
      $this->log->error($e);
      $this->returnJson(['status' => 'error', 'code' => 'db_error', 'detail' => $e->getMessage()]);
    }
  }
	/**
	 * @param $this->request
	 */
	public function test()
	{
		try {
			$queryObj = new \SQL\QueryFromRequest($this->db, $this->cfg, $this->request, true);
			$resp['status'] = 'success';
			$resp['code']   = 'test_ok_x_found';
			$resp['found']  = $queryObj->getTotal();
		} catch (\Throwable $e) {
			$resp['status'] = 'error';
			$resp['code']   = 'test_error';
		}

		$this->returnJson($resp);
	}



}
