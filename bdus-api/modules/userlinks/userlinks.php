<?php
/**
 * @copyright 2007-2021 Julian Bogdani
 * @license AGPL-3.0; see LICENSE
 * @since			Aug 17, 2012
 */

class userlinks_ctrl extends Controller
{

	public function get_all_tables()
	{
		try {
			$ret['status'] = 'success';
			$ret['info'] = $this->cfg->get('tables.*.label', 'is_plugin', null);
		} catch (\Throwable $e) {
			$this->log->error($e);
			$ret['status'] = 'error';
			$ret['info'] = $e->getMessage();
		}
		$this->returnJson($ret);

	}


	public function addUserLink()
	{
		$thistb = $this->get['thistb'];
		$thisid = $this->get['thisid'];
		$tb = $this->get['tb'];
		$id = $this->get['id'];

		try
		{
			if (!$thistb)	throw new \Exception("Missing required parameter thistb");
			if (!$thisid)	throw new \Exception("Missing required parameter thisid");
			if (!$tb) 		throw new \Exception("Missing required parameter tb");
			if (!$id) 		throw new \Exception("Missing required parameter id");
			
			$record = new Record($thistb, $thisid, $this->db, $this->cfg);
			foreach($id as $id) {
				if ($record->addUserLink($tb, $id)) {
					$ok[] = true;
				} else {
					$no[] = true;
				}
			}

			if (!$no) {
				$this->response('all_links_saved','success');
			} else if ($no && $ok) {
				error_log(cont($ok) . ' links were saved, ' . count($no) . ' links were not!');
				$this->response('some_links_saved', 'error');
			} else {
				$this->response('no_link_saved', 'error');
			}
		} catch(\DB\DBException $e) {
			// No log, already logged
			$this->response('no_link_saved', 'error');
		} catch(\Throwable $e) {
			$this->log->error($e);
			$this->response('no_link_saved', 'error');
		}
	}

	public function deleteUserLink()
	{
		$id = $this->get['id'];
		$record = new Record('no importance', false, $this->db, $this->cfg);

		if ($record->deleteUserLink($id)) {
			$this->response('ok_userlink_erased', 'success');
		} else {
			$this->response('error_userlink_erased', 'success');
		}
	}

	public function showUserLinks()
	{
		$tb = $this->get['tb'];
		$id = $this->get['id'];
		$context = $this->get['context'];

		$record = new Record($tb, $id, $this->db, $this->cfg);

		$links = $record->getUserLinks();

		if ($links) {
			$tmp = array();

			foreach ($links as $link) {
				$tmp[] = '<li>' .
						'<span class="btn-link userlink_read" data-tb="' . $link['tb'] . '" data-id="' . $link['ref_id'] . '">' .
							$this->cfg->get("tables.{$link['tb']}.label") . ', id:' . $link['ref_id'] .
						'</span>'
					. ( ($context == 'edit') ? ' [<span class="btn-link userlink_delete" data-id="' . $link['id'] . '">' . \tr::get('erase') . '</span>]' : '')
					. '</li>';
			}
		}

		$html = '<p><i class="fa fa-link"></i>  <strong>' . \tr::get('user_links') . '</strong></p>'
				. ( $tmp ? '<ul>' . implode('', $tmp) . '</ul>' : \tr::get('no_user_links'))
				. ( ($context == 'edit') ?
						'<p>'
							. '<span class="btn btn-default btn-sm userlink_reload" data-table="' . $tb . '" data-id="' . $id . '"><i class="fa fa-repeat"></i> ' . \tr::get('reload') . '</span>'
							. '<span class="btn btn-default btn-sm userlink_add" data-table="' . $tb . '" data-id="' . $id . '"><i class="fa fa-plus"></i> ' . \tr::get('add') . '</span>'
						. '</p>' : '');

		echo $html;
	}
}
