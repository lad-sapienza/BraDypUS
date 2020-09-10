<?php
/**
 * @author			Julian Bogdani <jbogdani@gmail.com>
 * @copyright		BraDypUS, Julian Bogdani <jbogdani@gmail.com>
 * @license			See file LICENSE distributed with this code
 * @since			Mar 17, 2013
 */

class cookieAuth
{
	private static $name = 'bdusLogin';
	
	private static $time = 2592000; // =30 * 24 * 3600 = 30 days
	
	public static function debug()
	{
		return [
			'name' => self::$name,
			'content' => $_COOKIE[self::$name]
		];
	}
	
	public static function set()
	{
		$data['user'] = $_SESSION['user'];
		$data['app'] = $_SESSION['app'];
		$data['pref'] = $_SESSION['pref'];
		
		setcookie(self::$name, serialize($data), (time() + self::$time));
	}
	
	public static function update()
	{
		if (isset($_COOKIE[self::$name])) {
			$data['user'] = $_SESSION['user'];
			$data['app'] = $_SESSION['app'];
			$data['pref'] = $_SESSION['pref'];
		
			setcookie(self::$name, serialize($data), (time() + self::$time));
		}
	}
	
	public static function get()
	{
		if ( !isset($_COOKIE[self::$name]) ) {
			return false;
		}
		$data = unserialize($_COOKIE[self::$name]);
			
		if ($data['app'] && $data['user']) {
			$_SESSION['app'] = $data['app'];
			$_SESSION['user'] = $data['user'];
			$_SESSION['pref'] = $data['pref'];
			return true;
		} else {
			return false;
		}
	}
	
	public static function destroy()
	{
		if (isset($_COOKIE[self::$name])) {
			setcookie(self::$name, false, time()-100);
		}
	}
}