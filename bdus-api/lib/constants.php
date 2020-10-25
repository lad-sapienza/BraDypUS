<?php
/**
* @author			Julian Bogdani <jbogdani@gmail.com>
* @copyright		BraDypUS, Julian Bogdani <jbogdani@gmail.com>
* @license			See file LICENSE distributed with this code
* @since			Apr 7, 2012
*/

// required since php 5.0.1: http://php.net/manual/en/function.date-default-timezone-set.php
date_default_timezone_set('Europe/Rome');

/**
 * Main CONSTANTS are always set
 */
define ( 'MAIN_DIR',	$basePath);
define ( 'LIB_DIR',		MAIN_DIR . 'lib' . DIRECTORY_SEPARATOR );
define ( 'MOD_DIR',		MAIN_DIR . 'modules' . DIRECTORY_SEPARATOR );
define ( 'LOCALE_DIR',	MAIN_DIR . 'locale' . DIRECTORY_SEPARATOR );
define ( 'PREFIX_DELIMITER', '__');

/**
 * Set session lifetime to last 8h
 */
ini_set('session.gc_maxlifetime', (60*60*8)); #8h

/**
 * Error reporting is set to ALL but nor Notice
 */
error_reporting(E_ALL & ~E_NOTICE);

/**
 * Turn off display errors
 */
ini_set('display_errors', 'off');

/**
 * Start session
 */
session_start();

/**
 * Force logout
 */
if ($_GET['logout']){
	$_SESSION = [];
	session_destroy();
	header("Location: ./");
}

/**
 * Define APP from REQUEST['app] (POST or GET): 
 * Or define from SESSION['app']
 * In both cases, application directory must exist!
 */
if ( isset($_REQUEST['app']) && is_dir(__DIR__ . '/../projects/' . $_REQUEST['app'] ) ) {
	define ( 'APP', $_REQUEST['app']);
} elseif (isset($_SESSION['app'])) {
	if (is_dir(__DIR__ . '/../projects/' . $_SESSION['app'] )) {
		define ( 'APP', $_SESSION['app']);
	} else {
		/**
		 * Edge case: session app is set, but application direcory is not set. 
		 * Force logout
		 */
		$_SESSION = [];
		session_destroy();
		header("Location: ./");
	}
}
/**
 * Define PROJ_DIR and PREFIX: APP dependent
 */
if( defined('APP') ) {
	
	$_SESSION['app'] = APP;
	define ( 'PREFIX', APP . PREFIX_DELIMITER);
	
	define ( 'PROJ_DIR', 		MAIN_DIR . 'projects' . DIRECTORY_SEPARATOR . APP . DIRECTORY_SEPARATOR);
	define ( 'PROJ_TMP_DIR',	MAIN_DIR . 'projects' . DIRECTORY_SEPARATOR . APP . DIRECTORY_SEPARATOR . 'tmp' . DIRECTORY_SEPARATOR . $_SESSION['user']['id'] . '/');
	
	/*
	 * Create directories that MUST exist for each valid app
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
 * If debug is explicitly set to 0, stop debug_mode
 */
if ( @$_GET['debug'] === '0' ) {
	$_SESSION['debug_mode'] = false;
}

/**
 * Set DEBUG_ON as true if debug mode is on
 * Otherwise set it to false
 */
if ( @$_GET['debug'] === '1' || $_SESSION['debug_mode'] ) {
	$_SESSION['debug_mode'] = true;
	define('DEBUG_ON', true);
} else {
	$_SESSION['debug_mode'] = false;
	define('DEBUG_ON', false);
}

/**
 * Set cache if debug is false, 
 * otherwise set to true
 */
if (DEBUG_ON === true) {
	define('CACHE', serialize( [ "autoescape" => false, "debug" => true ] ));
	error_reporting(E_ALL);
} else {
	define('CACHE', serialize([ "autoescape" => false, "cache" => "cache"]));
}

require_once LIB_DIR . 'autoLoader.php';
require_once $root . 'vendor/autoload.php';
new autoLoader(LIB_DIR, MOD_DIR);
