<?php
/**
 * @author			Julian Bogdani <jbogdani@gmail.com>
 * @copyright		BraDypUS, Julian Bogdani <jbogdani@gmail.com>
 * @license			See file LICENSE distributed with this code
 * @since			Apr 8, 2012
 * 
 * List of system query parameters:
 * 	GET: mini:		if 1 minifies all js scripts
 * 	GET: logout:	if 1/true/set forces user logout
 * 	GET: debug:		if 1 starts debug_mode (sets SESSION: debug_mode)
 * 					if 0 stops debug_mode (unsets SESSION: debug_mode)
 * 
 * Controller related
 * REQUEST: obj		Object name to run
 * REQUEST: method	Method name to run
 * REQUEST: param	Params to pass to object::method
 * 
 */

ob_start();

try {
	$basePath = './';

	require_once './lib/constants.php';

	$application = new \Bdus\App($_GET, $_POST, $_REQUEST);

	$application->setDebug(DEBUG_ON);

	if (defined('PREFIX')){
		$application->setPrefix(PREFIX);
	}

	if (defined('APP')) {
		$application->setApp(APP);
	}

	$application->start();

} catch (\Throwable $e) {

	echo json_encode([
		"text" => tr::get('generic_error'), 
		"status" => 'error'
	], JSON_UNESCAPED_UNICODE);

	if (DEBUG_ON) {
		echo "<strong>" . $e->getMessage() . "</strong>";
		echo "<hr>";
		echo nl2br($e->getTraceAsString());
		echo "<hr>";
		echo "<pre>";
		var_dump($e);
		echo "</pre>";
	}
}
ob_end_flush();
