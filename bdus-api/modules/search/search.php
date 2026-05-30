<?php
/**
 * @copyright 2007-2022 Julian Bogdani
 * @license AGPL-3.0; see LICENSE
 * @since			Sep 11, 2012
 */

class search_ctrl extends Controller
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
   *   fields:     [ { value: "tb:field", label: "Field label" }, ... ],
   *   operators:  [ { value: "LIKE", label: "contains" }, ... ],
   *   connectors: [ { value: "AND", label: "AND" }, ... ]
   * }
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
    foreach ($this->cfg->get("tables.{$tb}.fields.*.label") as $name => $label) {
      $fields[] = ['value' => "{$tb}:{$name}", 'label' => $label ?: $name];
    }

    // Plugin fields grouped by plugin label
    $plugins = $this->cfg->get("tables.{$tb}.plugin");
    if (is_array($plugins)) {
      foreach ($plugins as $plg) {
        $plgLabel = $this->cfg->get("tables.{$plg}.label") ?: $plg;
        foreach ($this->cfg->get("tables.{$plg}.fields.*.label") as $name => $label) {
          $fields[] = [
            'value' => "{$plg}:{$name}",
            'label' => $plgLabel . ' › ' . ($label ?: $name),
          ];
        }
      }
    }

    // Operators: value is the JsonFilter operator, key is the i18n locale key.
    // The frontend is responsible for translating key → label via t(key).
    $operators = [
      ['value' => '_icontains',  'key' => 'contains'],
      ['value' => '_eq',         'key' => 'is_exactly'],
      ['value' => '_ncontains',  'key' => 'doesnt_contain'],
      ['value' => '_starts_with','key' => 'starts_with'],
      ['value' => '_ends_with',  'key' => 'ends_with'],
      ['value' => '_empty',      'key' => 'is_empty'],
      ['value' => '_nempty',     'key' => 'is_not_empty'],
      ['value' => '_gt',         'key' => 'bigger'],
      ['value' => '_lt',         'key' => 'smaller'],
    ];

    // Connectors: AND/OR/XOR are language-agnostic technical terms.
    $connectors = ['AND', 'OR', 'XOR'];
    $resp = compact('fields', 'operators', 'connectors');
    $resp['status'] = 'success';

    $this->returnJson($resp);
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
        $second_field = $this->cfg->get("tables.{$second_table}.id_field");
        $q = "SELECT {$second_field} AS val FROM {$second_table} WHERE 1=1 GROUP BY {$second_field}";
      } else {
        $q = "SELECT {$fld} AS val FROM {$tb} WHERE {$fld} IS NOT NULL GROUP BY {$fld}";
      }

      $rows   = $this->db->query($q);
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
			$queryObj = new QueryFromRequest($this->db, $this->cfg, $this->request, true);
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
