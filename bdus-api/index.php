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
 * 	env: BRADYPUS_DEBUG=1  enables debug mode (verbose errors, Twig debug, no cache)
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

	// ── REST API v1 ───────────────────────────────────────────────────────────
	// Detected before the normal Bdus routing; constants.php (autoloader) is
	// already loaded at this point so all namespaced classes are available.
	if (isset($_SERVER['REQUEST_URI']) && preg_match('#/api/v1(/|$)#', $_SERVER['REQUEST_URI'])) {
		\API\V1\Router::handle();
		ob_end_flush();
		exit;
	}

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

	// Always respond with valid JSON — debug details go to logs/error.log, not the response body.
	echo json_encode([
		"text" => 'generic_error',
		"status" => 'error',
		"debug" => DEBUG_ON ? $e->getMessage() : null,
	], JSON_UNESCAPED_UNICODE);
}
ob_end_flush();
