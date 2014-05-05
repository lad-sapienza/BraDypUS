<?php
/**
 * @author			Julian Bogdani <jbogdani@gmail.com>
 * @copyright		BraDypUS 2007-2013
 * @license			See file LICENSE distributed with this code
 * @since			Feb 21, 2013
 */

class preferences_ctrl extends Controller
{
	public function open()
	{
		$langs = utils::dirContent(LOCALE_DIR);
		
		array_walk($langs, function(&$el){ $el =  str_replace('.inc', null, $el); });
		
		$this->render('preferences', 'panel', array(
				'tr' => new tr(),
				'uid' => uniqid('pref'),
				'infinite_scroll'=> pref::get('infinite_scroll'),
				'all_langs' => $langs,
				'current_lang' => pref::get('lang'),
				'user_id' => $_SESSION['user']['id'],
				'most_recent' => pref::get('most_recent_no') ? pref::get('most_recent_no') : 10 
				));
	}
	
	public function save()
	{
		pref::set($this->get['key'], $this->get['val']);
	}
	
	public function save2db()
	{
		try
		{
			pref::save2DB();
			utils::response('pref_saved_in_db');
		}
		catch(myException $e)
		{
			utils::response('pref_not_saved_in_db', 'error');
		}
	}
}