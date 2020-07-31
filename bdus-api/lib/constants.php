<?php
/**
 * @author			Julian Bogdani <jbogdani@gmail.com>
 * @copyright		BraDypUS, Julian Bogdani <jbogdani@gmail.com>
 * @license			See file LICENSE distributed with this code
 * @since			Apr 7, 2012
 */
/*
 * Main CONSTANTS
 */

define ( 'MAIN_DIR',	$basePath);
define ( 'LIB_DIR',		MAIN_DIR . 'lib/' );
define ( 'MOD_DIR',		MAIN_DIR . 'modules/' );
define ( 'LOCALE_DIR',	MAIN_DIR . 'locale/');
$error_log = MAIN_DIR . 'logs/error.log';

// required since 5.0.1: http://php.net/manual/en/function.date-default-timezone-set.php
date_default_timezone_set('Europe/Rome');

// SETS ERROR REPORTING
( ini_get('error_reporting') <> 6135 )	?	ini_set('error_reporting', 6135) : '';

if (!is_dir(MAIN_DIR . 'sessions')){
	@mkdir(MAIN_DIR . 'sessions');
	if (!is_dir(MAIN_DIR . 'sessions')){
		throw new Exception('Cannot create sessions folder');
	}
}

//SETS SESSION SAVE PATH
ini_set ( 'session.save_path', MAIN_DIR . 'sessions' );

// TURN OFF ERROR DISPLAY
ini_set('display_errors', 'off');

//SETS SESSION MAX LIFE TIME
ini_set('session.gc_maxlifetime', (3600*8)); #8h

//START SESSION
session_start();

/*
 * Session CONSTANTS
 */
($_REQUEST['app'] && is_dir('./projects/' . $_REQUEST['app'])) ? $_SESSION['app'] = $_REQUEST['app'] : '';

define ( 'PREFIX_DELIMITER', '__');

if($_SESSION['app']) {
	define ( 'PROJ_DIR', MAIN_DIR . "projects/{$_SESSION['app']}/");
	define ( 'APP', $_SESSION['app']);
	define ( 'PREFIX', APP . PREFIX_DELIMITER);
}

/*
 * PROJect CONSTANTS
 */
if ( defined('PROJ_DIR')) {
	define ('PROJ_TMP_DIR',		PROJ_DIR . 'tmp/' . $_SESSION['user']['id'] . '/');
	$error_log 				= 	PROJ_DIR . 'error.log';


	/*
	 * Create directories that MUST exist
	 */
	$must_exist_dirs = [
		PROJ_DIR . 'files', 
		PROJ_TMP_DIR, 
		PROJ_DIR . 'backups', 
		PROJ_DIR . 'export', 
		PROJ_DIR . 'db'
	];

	foreach($must_exist_dirs as $dir) {
		if (!is_dir($dir)) {
			mkdir($dir, 0777, true);
		}
	}
}

// LOG PHP ERRORS TO APPLICATION ERROR.LOG FILE
define ('ERROR_LOG', $error_log);
ini_set('error_log', ERROR_LOG);


/**
 * If debug=1 or debug=1 has previously set, debug is turned
 * If debug=0, debug is turned off
 */ 

if (@$_REQUEST['debug'] === '0') {
    $_SESSION['debug_mode'] = false;
    define('DEBUG_ON', false);
    define('CACHE', serialize([ "autoescape" => false, "cache" => "cache"]));
} else if ( @$_REQUEST['debug'] === '1') {
	$_SESSION['debug_mode'] = true;
	define('DEBUG_ON', true);
	define('CACHE', serialize( [ "autoescape" => false, "debug" => true ] ));
} else if ($_SESSION['debug_mode']) {
	define('DEBUG_ON', true);
	define('CACHE', serialize( [ "autoescape" => false, "debug" => true ] ));
} else {
	define('DEBUG_ON', false);
    define('CACHE', serialize([ "autoescape" => false, "cache" => "cache"]));
}

require_once LIB_DIR . 'myException.php';
require_once LIB_DIR . 'autoLoader.php';
require_once $root . 'vendor/autoload.php';
new autoLoader();

set_error_handler('Meta::logError', 6135);

if (defined('PROJ_DIR')) {
	cfg::load(false, true);
}
