<?php
/**
 * @author			Julian Bogdani <jbogdani@gmail.com>
 * @copyright		BraDypUS, Julian Bogdani <jbogdani@gmail.com>
 * @license			See file LICENSE distributed with this code
 * @since			Apr 7, 2012
 */

// required since 5.0.1: http://php.net/manual/en/function.date-default-timezone-set.php
date_default_timezone_set('Europe/Rome');

/**
 * Main CONSTANTS are always set
 */
define ( 'MAIN_DIR',	$basePath);
define ( 'LIB_DIR',		MAIN_DIR . 'lib/' );
define ( 'MOD_DIR',		MAIN_DIR . 'modules/' );
define ( 'LOCALE_DIR',	MAIN_DIR . 'locale/');
define ( 'PREFIX_DELIMITER', '__');

// No project available: app error log
$error_log = MAIN_DIR . 'logs/error.log';
ini_set('error_log', $error_log);

// Make shure sessions folders exist
if (!is_dir(MAIN_DIR . 'sessions')){
	@mkdir(MAIN_DIR . 'sessions');
}
if (is_dir(MAIN_DIR . 'sessions') && is_writable(MAIN_DIR . 'sessions') ){
	//SETS SESSION SAVE PATH
	ini_set ( 'session.save_path', MAIN_DIR . 'sessions' );
}

//SETS SESSION MAX LIFE TIME
ini_set('session.gc_maxlifetime', (3600*8)); #8h


// SETS ERROR REPORTING
( ini_get('error_reporting') <> 6135 )	?	ini_set('error_reporting', 6135) : '';
// TURN OFF ERROR DISPLAY
ini_set('display_errors', 'off');


//START SESSION
session_start();

if ($_GET['logout']){
	$_SESSION = array();
	session_destroy();
	header("Location: ./");
}

/*
 * Set APP from REQUEST['app']
 * APP dir must exist!
 */
if ( isset($_REQUEST['app']) && is_dir(__DIR__ . '/../projects/' . $_REQUEST['app'] ) ) {
	define ( 'APP', $_REQUEST['app']);
} elseif (isset($_SESSION['app'])) {
	define ( 'APP', $_SESSION['app']);
}
/**
 * Define PROJ_DIR and PREFIX: APP dependent
 */
if( defined('APP') ) {

	$_SESSION['app'] = APP;
	define ( 'PREFIX', APP . PREFIX_DELIMITER);

	define ( 'PROJ_DIR', 		MAIN_DIR . 'projects/' . APP . '/');
	define ( 'PROJ_TMP_DIR',	MAIN_DIR . 'projects/' . APP . '/tmp/' . $_SESSION['user']['id'] . '/');

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
			@mkdir($dir, 0777, true);
		}
	}

	$must_be_writtable = $must_exist_dirs;

	foreach ($must_be_writtable as $file) {
		if (!is_writable($file)){
			die("Directory $dir is not writable. Application cannot start!");
		}
	}
}

/**
 * If debug=1 or debug=1 has previously set, debug is turned
 * If debug=0, debug is turned off
 */ 

if (@$_GET['debug'] === '0') {
    $_SESSION['debug_mode'] = false;
    define('DEBUG_ON', false);
    define('CACHE', serialize([ "autoescape" => false, "cache" => "cache"]));
} else if ( @$_GET['debug'] === '1') {
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

require_once LIB_DIR . 'autoLoader.php';
require_once $root . 'vendor/autoload.php';
new autoLoader(LIB_DIR, MOD_DIR);
