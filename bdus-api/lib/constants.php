<?php
/**
 * @copyright 2007-2022 Julian Bogdani
 * @license AGPL-3.0; see LICENSE
 * @since			Apr 7, 2012
*/

// required since php 5.0.1: http://php.net/manual/en/function.date-default-timezone-set.php
date_default_timezone_set('Europe/Rome');

/**
 * Main CONSTANTS are always set
 */
define ( 'MAIN_DIR',	$basePath);

/**
 * Set session lifetime to last 8h
 */
ini_set('session.gc_maxlifetime', (60*60*8)); #8h

/**
 * Error reporting is set to none
 */
error_reporting(0);

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
if ( isset($_REQUEST['app']) && is_dir(MAIN_DIR . 'projects/' . $_REQUEST['app'] ) ) {
	define ( 'APP', $_REQUEST['app']);
	$_SESSION['app'] = APP;
} elseif (isset($_SESSION['app'])) {
	if (is_dir(MAIN_DIR . 'projects/' . $_SESSION['app'] )) {
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
	
	define ( 'PREFIX', APP . '__');
	
	define ( 'PROJ_DIR', 		MAIN_DIR . 'projects/' . APP . '/');
	
	/*
	 * Create directories that MUST exist for each valid app
	 */
	$must_exist_dirs = [
		MAIN_DIR . 'cache',
		MAIN_DIR . 'cache/img',
		PROJ_DIR . 'files', 
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
 * If debug is explicitly set to 0|1, stop|stop debug_mode
 */
if (isset($_GET['debug'])) {
	if ( $_GET['debug'] === '1' ) {
		$_SESSION['debug_mode'] = true;
	} else {
		$_SESSION['debug_mode'] = false;
	}
}


/**
 * Set DEBUG_ON as $_SESSION['debug_mode']
 */
define('DEBUG_ON', isset($_SESSION['debug_mode']) && $_SESSION['debug_mode']);

/**
 * Set cache if debug is false, 
 * otherwise set to true
 */
if (DEBUG_ON === true) {
	define('CACHE', serialize( [ "autoescape" => false, "debug" => true ] ));
	// Error reporting is set to ALL but NOT: Warning or Notice
	error_reporting(E_ALL & ~E_WARNING & ~E_NOTICE);
	ini_set('error_log', 'logs/error.log');

} else {
	define('CACHE', serialize([ "autoescape" => false, "cache" => "cache"]));
}

require_once $basePath . 'lib/autoLoader.php';
require_once $basePath . 'vendor/autoload.php';
new autoLoader($basePath . 'lib/', $basePath . 'modules/');
