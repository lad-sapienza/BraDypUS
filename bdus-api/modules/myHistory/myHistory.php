<?php
/**
 * @author			Julian Bogdani <jbogdani@gmail.com>
 * @copyright		BraDypUS, Julian Bogdani <jbogdani@gmail.com>
 * @license			See file LICENSE distributed with this code
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
		if (utils::write_in_file(PROJ_HISTORY)) {
			utils::response('ok_history_erased');
		} else  {
			utils::response('error_history_erased'. 'error');
		}
	}


	public static function autoPack()
	{
		try {

			// get log size
			$size = (int)filesize(PROJ_HISTORY);

			// Process only if filesize is bigger then 1mb
			if ($size < 1024) {
				return;
			}

			// check PROJ_BUP_DIR is defined
			if (!defined('PROJ_BUP_DIR')) {
				throw new myException("Application error: PROJ_BUP_DIR is not defined");
			}

			// check folder PROJ_BUP_DIR exists
			if (!is_dir(PROJ_BUP_DIR)) {
				throw new myException("Application error: " . PROJ_BUP_DIR . " is not a directory");
			}

			$destfile = PROJ_BUP_DIR . 'history-' . date('Y-m-d_H-i-s');

			utils::write_in_file($destfile, file_get_contents(PROJ_HISTORY), true);
			file_put_contents(PROJ_HISTORY, '');

		} catch (myException $e) {
			$e->log();
		}

	}
}
