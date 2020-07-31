<?php
/**
 * @author			Julian Bogdani <jbogdani@gmail.com>
 * @copyright		BraDypUS, Julian Bogdani <jbogdani@gmail.com>
 * @license			See file LICENSE distributed with this code
 * @since			Apr 8, 2012
 */

ob_start();

try {

	$basePath = './';

	require_once './lib/constants.php';

	if ($_GET['logout']) {
		try {
			$user = new User(new DB());
			$user->logout();

		} catch (myException $e) {
			User::forceLogOut();
		}
	}
	if ($_GET['mini']) {
		Compress::modScripts();
		utils::emptyDir(MAIN_DIR . 'cache', false);
	}

	$controller = new Controller($_GET, $_POST, $_REQUEST);

	$controller->route();

} catch (Throwable $e) {
	Meta::logException($e);

	echo utils::message( tr::get('generic_error'), 'error', true);

	if (DEBUG_ON) {
		var_dump($e);
	}
}
ob_end_flush();
