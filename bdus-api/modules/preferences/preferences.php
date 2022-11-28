<?php
/**
 * @copyright 2007-2022 Julian Bogdani
 * @license AGPL-3.0; see LICENSE
 * @since			Feb 21, 2013
 */

class preferences_ctrl extends Controller
{
	public function open()
	{
		$this->render('preferences', 'panel', [
			'infinite_scrolling'=> \pref::get('infinite_scrolling'),
			'all_langs' => \tr::getAvailable(),
			'current_lang' => \pref::get('lang'),
			'user_id' => $_SESSION['user']['id']
		]);
	}
	
	public function save()
	{
		\pref::set($this->get['key'], $this->get['val']);
	}
	
	public function save2db()
	{
		try {
			\pref::save2DB($this->db);
			$this->response('pref_saved_in_db', 'success');
		} catch(\Throwable $e) {
			$this->response('pref_not_saved_in_db', 'error');
		}
	}
}