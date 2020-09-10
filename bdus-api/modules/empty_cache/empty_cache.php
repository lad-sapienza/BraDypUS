<?php
/**
 * @author			Julian Bogdani <jbogdani@gmail.com>
 * @copyright		BraDypUS, Julian Bogdani <jbogdani@gmail.com>
 * @license			See file LICENSE distributed with this code
 * @since			Aug 10, 2013
 */

class empty_cache_ctrl extends Controller
{
	public function doEmpty()
	{
		try {
			\utils::emptyDir(MAIN_DIR . 'cache', false);
			\utils::response('ok_cache_emptied', 'success');
		} catch (\Exception $e) {
			\utils::response('error_cache_not_emptied', 'error');
		}
	}
}