<?php
/**
 * @copyright 2007-2022 Julian Bogdani
 * @license AGPL-3.0; see LICENSE
 * @since			Aug 10, 2013
 */

class empty_cache_ctrl extends Controller
{
	/**
	 * @deprecated v5 — the Twig template cache no longer exists; the v5
	 *             frontend is a Vite-built SPA with no server-side cache.
	 *             This module can be removed once all v4 UIs are retired.
	 */
	public function doEmpty()
	{
		try {
			\utils::emptyDir(MAIN_DIR . 'cache', false);
			$this->response('ok_cache_emptied', 'success');
		} catch (\Exception $e) {
			$this->response('error_cache_not_emptied', 'error');
		}
	}
}