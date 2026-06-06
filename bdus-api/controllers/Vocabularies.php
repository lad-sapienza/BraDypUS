<?php

namespace Bdus\Controllers;

/**
 * @copyright 2007-2022 Julian Bogdani
 * @license AGPL-3.0; see LICENSE
 * @since			Aug 10, 2012
 */

 use \DB\System\Manage;

class Vocabularies extends \Bdus\Controller
{
	private $sys_manager = false;

	private function getSysMng()
	{
		if (!$this->sys_manager){
			$this->sys_manager = new Manage($this->db);
		}
		return $this->sys_manager;
	}

	/**
	 * Returns a map of vocabulary names to the config fields that reference them.
	 *
	 * GET /api/vocabularies/usages
	 *
	 * Response: { status, usages: { vocName: [{ tb, tb_label, field, field_label }] } }
	 */
	public function usages(): void
	{
		if (!\Auth\Authorization::can('read')) {
			$this->returnJson(['status' => 'error', 'code' => 'not_enough_privilege']);
			return;
		}

		$tableNames = $this->cfg->get('tables.*.name') ?: [];
		$usages = [];

		foreach ($tableNames as $tbName => $_) {
			$tbLabel = $this->cfg->get("tables.$tbName.label") ?? $tbName;
			$fields  = $this->cfg->get("tables.$tbName.fields") ?: [];
			foreach ($fields as $fldKey => $fldDef) {
				$vocSet = $fldDef['vocabulary_set'] ?? null;
				if ($vocSet) {
					$usages[$vocSet][] = [
						'tb'          => $tbName,
						'tb_label'    => $tbLabel,
						'field'       => is_string($fldKey) ? $fldKey : ($fldDef['name'] ?? $fldKey),
						'field_label' => $fldDef['label'] ?? $fldKey,
					];
				}
			}
		}

		$this->returnJson(['status' => 'success', 'usages' => $usages]);
	}

	public function list()
	{
		$res = $this->getSysMng()->getBySQL('bdus_vocabularies', '1=1 ORDER BY voc, sort');
		$grouped = [];
		foreach ($res as $row) {
			$grouped[$row['voc']][] = [
				'id'   => (int) $row['id'],
				'def'  => $row['def'],
				'sort' => (int) $row['sort'],
			];
		}
		$vocs = [];
		foreach ($grouped as $name => $items) {
			$vocs[] = ['name' => $name, 'items' => $items];
		}
		$this->returnJson(["status" => "success", "vocs" => $vocs]);
	}

	public function edit()
	{
		$id = $this->get['id'];
		$val = $this->request['val'];

		$res = $this->getSysMng()->editRow('bdus_vocabularies', $id, [
			'def' => $val
		]);
		
		if ( $res ) {
			$this->returnJson(['status' => 'success', 'code' => 'ok_def_update']);
		} else {
			$this->returnJson(['status' => 'error', 'code' => 'error_def_update']);
		}
	}
	
	public function erase()
	{
		$id = $this->get['id'];

		$res = $this->getSysMng()->deleteRow('bdus_vocabularies', $id );

		if ( $res ) {
			$this->returnJson(['status' => 'success', 'code' => 'ok_def_erase']);
		} else {
			$this->returnJson(['status' => 'error', 'code' => 'error_def_erase']);
		}
	}
	
	public function add()
	{
		$voc = $this->request['voc'];
		$def = $this->request['def'];

		$res = $this->getSysMng()->addRow('bdus_vocabularies', [
			'voc' => $voc,
			'def' => $def
		]);

		if ( $res ) {
			$this->returnJson(['status' => 'success', 'code' => 'ok_def_added']);
		} else {
			$this->returnJson(['status' => 'error', 'code' => 'error_def_added']);
		}
	}
	
	public function sort()
	{
		$error = false;
		// Accept both legacy GET sort[sort]=id and new POST { ids: [...] }
		$ids = $this->post['ids'] ?? null;
		if ($ids !== null) {
			foreach ($ids as $sort => $id) {
				$res = $this->getSysMng()->editRow('bdus_vocabularies', (int)$id, ['sort' => (int)$sort]);
				if (!$res) $error = true;
			}
		} else {
			$sortArray = $this->get['sort'];
			foreach ($sortArray as $sort => $id) {
				$res = $this->getSysMng()->editRow('bdus_vocabularies', (int)$id, ['sort' => (int)$sort]);
				if (!$res) $error = true;
			}
		}
		$error
			? $this->returnJson(['status' => 'error', 'code' => 'error_sort_update'])
			: $this->returnJson(['status' => 'success', 'code' => 'ok_sort_update']);
	}
}