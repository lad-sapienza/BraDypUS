<?php
/**
 * @author			Julian Bogdani <jbogdani@gmail.com>
 * @copyright		BraDypUS, Julian Bogdani <jbogdani@gmail.com>
 * @license			See file LICENSE distributed with this code
 * @since			Feb 21, 2013
 */

class preferences_ctrl extends Controller
{
	public function open()
	{
		$this->render('preferences', 'panel', [
			'infinite_scroll'=> pref::get('infinite_scroll'),
			'all_langs' => \tr::getAvailable(),
			'current_lang' => pref::get('lang'),
			'user_id' => $_SESSION['user']['id']
		]);
	}
	
	public function save()
	{
		pref::set($this->get['key'], $this->get['val']);
	}
	
	public function save2db()
	{
		try {
			pref::save2DB($this->db);
			\utils::response('pref_saved_in_db');
		} catch(\Throwable $e) {
			\utils::response('pref_not_saved_in_db', 'error');
		}
	}
}