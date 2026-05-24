<?php
/**
 * @copyright 2007-2022 Julian Bogdani
 * @license AGPL-3.0; see LICENSE
 * @since			Aug 10, 2012
 */

 use \DB\System\Manage;

class vocabularies_ctrl extends Controller
{
	private $sys_manager = false;

	private function getSysMng()
	{
		if (!$this->sys_manager){
			$this->sys_manager = new Manage($this->db);
		}
		return $this->sys_manager;
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
			$this->response('ok_def_update', 'success');
		} else {
			$this->response('error_def_update', 'error');
		}
	}
	
	public function erase()
	{
		$id = $this->get['id'];

		$res = $this->getSysMng()->deleteRow('bdus_vocabularies', $id );

		if ( $res ) {
			$this->response('ok_def_erase', 'success');
		} else {
			$this->response('error_def_erase', 'error');
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
			$this->response('ok_def_added', 'success');
		} else {
			$this->response('error_def_added', 'error');
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
			? $this->response('error_sort_update', 'error')
			: $this->response('ok_sort_update', 'success');
	}
}