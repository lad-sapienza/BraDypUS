<?php
/**
 * @copyright 2007-2025 Julian Bogdani
 * @license AGPL-3.0; see LICENSE
 * @since Aug 10, 2012
 */

class search_replace_ctrl extends Controller
{
	// ── v5 API endpoints ──────────────────────────────────────────────────────

	/**
	 * GET ?obj=search_replace_ctrl&method=getTableList
	 * Returns all tables the current user can write to.
	 * Response: { tables: [ { name, label } ] }
	 */
	public function getTableList(): void
	{
		if (!\utils::canUser('admin')) {
			$this->returnJson(['status' => 'error', 'code' => 'not_enough_privilege']);
			return;
		}

		$names  = $this->cfg->get('tables.*.name') ?: [];
		$tables = [];
		foreach ($names as $name) {
			$tables[] = [
				'name'  => $name,
				'label' => $this->cfg->get("tables.$name.label") ?? $name,
			];
		}
		$this->returnJson(["status" => "success", "tables" => $tables]);
	}

	/**
	 * GET ?obj=search_replace_ctrl&method=getFieldList&tb=TABLE
	 * Returns text-like fields for a given table.
	 * Response: { fields: [ { name, label } ] }
	 */
	public function getFieldList(): void
	{
		if (!\utils::canUser('admin')) {
			$this->returnJson(['status' => 'error', 'code' => 'not_enough_privilege']);
			return;
		}

		$tb = $this->get['tb'] ?? null;
		if (!$tb) {
			$this->returnJson(['status' => 'error', 'code' => 'parameter_missing']);
			return;
		}

		$names  = $this->cfg->get("tables.{$tb}.fields.*.name") ?: [];
		$fields = [];
		foreach ($names as $name) {
			$type = $this->cfg->get("tables.{$tb}.fields.{$name}.type") ?? 'text';
			if (in_array($type, ['text', 'textarea', 'combo_select'], true)) {
				$fields[] = [
					'name'  => $name,
					'label' => $this->cfg->get("tables.{$tb}.fields.{$name}.label") ?? $name,
				];
			}
		}
		$this->returnJson(["status" => "success", "fields" => $fields]);
	}

	/**
	 * POST ?obj=search_replace_ctrl&method=doReplace
	 * Executes a bulk REPLACE() on a single field. Admin only.
	 * Body: { tb, fld, search, replace }
	 * Response: { status, code, affected: N }
	 */
	public function doReplace(): void
	{
		if (!\utils::canUser('admin')) {
			$this->returnJson(['status' => 'error', 'code' => 'not_enough_privilege']);
			return;
		}

		$tb      = $this->post['tb']      ?? null;
		$fld     = $this->post['fld']     ?? null;
		$search  = $this->post['search']  ?? null;
		$replace = $this->post['replace'] ?? '';

		if (!$tb || !$fld || $search === null || $search === '') {
			$this->returnJson(['status' => 'error', 'code' => 'parameter_missing']);
			return;
		}

		// Validate tb and fld against config to prevent SQL injection
		$knownTb  = $this->cfg->get("tables.{$tb}");
		$knownFld = $this->cfg->get("tables.{$tb}.fields.{$fld}");
		if (!$knownTb || !$knownFld) {
			$this->returnJson(['status' => 'error', 'code' => 'not_enough_privilege']);
			return;
		}

		try {
			$affected = $this->db->query(
				"UPDATE {$tb} SET {$fld} = REPLACE({$fld}, ?, ?)",
				[$search, $replace],
				'affected'
			);
			$this->returnJson([
				'status'   => 'success',
				'code'     => 'ok_search_replace',
				'affected' => (int)$affected,
			]);
		} catch (\Throwable $e) {
			$this->log->error($e);
			$this->returnJson(['status' => 'error', 'code' => 'db_error', 'detail' => $e->getMessage()]);
		}
	}

}
