<?php
/**
 * @author			Julian Bogdani <jbogdani@gmail.com>
 * @copyright		BraDypUS 2007-2012
 * @license			All rights reserved
 * @since			Sep 10, 2012
 */


class myHistory_ctrl
{
	public static function show_all()
	{
		echo '<pre>' . file_get_contents(PROJ_HISTORY) . '</pre>';
	}
	
	public function erase()
	{
		if (utils::write_in_file(PROJ_HISTORY))
		{
			utils::response('ok_history_erased');
		}
		else 
		{
			utils::response('error_history_erased'. 'error');
		}
	}
}