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
			$this->sys_manager = new Manage($this->db, $this->prefix);
		}
		return $this->sys_manager;
	}

	private function getFullVocabularies()
	{
		$res = $this->getSysMng()->getBySQL('vocabularies', '1=1 ORDER BY voc, sort');
		$vocs = [];

		foreach($res as $arr) {
			$vocs[$arr['voc']][$arr['id']] = $arr['def'];
		}
		return $vocs;
	}

	public function list()
	{
		if ($this->wantsJson()) {
			$res = $this->getSysMng()->getBySQL('vocabularies', '1=1 ORDER BY voc, sort');
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
			$this->returnJson(['vocs' => $vocs]);
		} else {
			// @deprecated v5 — Twig render, remove when legacy UI is retired
			$this->render('vocabularies', 'list', [
				'vocs' => $this->getFullVocabularies(),
			]);
		}
	}

	/**
	 * @deprecated v5 — remove when legacy UI is retired
	 * 
	 */
	public function add_new_form()
	{
		$voc = $this->get['voc'] ?: false ;

		if (!$voc) {
			$all_vocs = array_keys($this->getFullVocabularies());
		}
		
		$this->render('vocabularies', 'add_new_form', [
			'voc' => $voc,
			'all_vocs' => $all_vocs,
		]);
	}
	
	public function edit()
	{
		$id = $this->get['id'];
		$val = $this->get['val'];

		$res = $this->getSysMng()->editRow('vocabularies', $id, [
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

		$res = $this->getSysMng()->deleteRow('vocabularies', $id );

		if ( $res ) {
			$this->response('ok_def_erase', 'success');
		} else {
			$this->response('error_def_erase', 'error');
		}
	}
	
	public function add()
	{
		$voc = $this->get['voc'];
		$def = $this->get['def'];

		$res = $this->getSysMng()->addRow('vocabularies', [
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
				$res = $this->getSysMng()->editRow('vocabularies', (int)$id, ['sort' => (int)$sort]);
				if (!$res) $error = true;
			}
		} else {
			$sortArray = $this->get['sort'];
			foreach ($sortArray as $sort => $id) {
				$res = $this->getSysMng()->editRow('vocabularies', (int)$id, ['sort' => (int)$sort]);
				if (!$res) $error = true;
			}
		}
		$error
			? $this->response('error_sort_update', 'error')
			: $this->response('ok_sort_update', 'success');
	}
}