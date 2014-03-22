<?php
/**
 * Translation class
 * @author			Julian Bogdani <jbogdani@gmail.com>
 * @copyright		BraDypUS 2007-2011
 * @license			All rights reserved
 * @since			Apr 7, 2012
 * @uses			cfg
 */


class tr
{
	/**
	 * 
	 * Returns lang variable using:
	 * 1) $_SESSION['pref']['lang']
	 * 2) system default language
	 * 3) browser default language
	 * 4) en
	 */
	public static function load_file()
	{
		/*
		 * Set $_SESSION['pref']['lang']
		 */
		// language may be
		if ( !pref::getLang() )
		{
			try
			{
				$def_lang = cfg::main('lang');
			}
			catch(myException $e)
			{
				// do nothing
			}
			
			$computer_lang = substr($_SERVER["HTTP_ACCEPT_LANGUAGE"], 0, 2);
			
			if ($def_lang)
			{
				pref::setLang($def_lang);
			}
			else if ($computer_lang AND file_exists (LOCALE_DIR . $computer_lang . '.inc') )
			{
				pref::setLang($computer_lang);
			}
			else
			{
				pref::setLang('en');
			}
			
		}
		require_once LOCALE_DIR . pref::getLang() . '.inc';
		
		$_SESSION['language_strings'] = $lang;
	}
	
	/**
	 * 
	 * Returns formatted string
	 * @param string $string	input string
	 * @param mixed $args		array of arguments for formatting
	 * @param boolean $escape	boolean escape
	 */
	public static function sget($string, $args, $escape = false)
	{
		if (!is_array($args))
		{
			$args = array($args);
		}
		$ret = vsprintf(self::get($string), $args);
		
		return $escape ? str_replace("'", "\'", $ret) : $ret;
	}
	
	/**
	 * 
	 * Translates $string
	 * @param string $string string to translate
	 * @param boolean $escape boolean controle if string mus be escaped or not
	 */
	public static function get($string, $escape = false)
	{
		$lang = $_SESSION['language_strings'];
	
		$ret = $lang[$string] ? $lang[$string] : $string;
	
		return $escape ? str_replace("'", "\'", $ret) : $ret;
	}
	
	/**
	 * 
	 * Returns current language as json (is_string is false) or as javascript variable (is_string = true)
	 * @param boolean $is_string
	 */
	public static function lang2json($is_string = false)
	{
		$lang = $_SESSION['language_strings'];
		
		if ($is_string)
		{
			echo 'var lang = ' . json_encode($lang) . ';';
		}
		else
		{
			return json_encode($lang);
		} 
	}
	
	/**
	 * 
	 * Uses self::get and echoes translated string
	 * @param string $string
	 * @param boolean $escape boolean controle if string mus be escaped or not
	 */
	public static function show($string, $escape = false)
	{
		echo self::get($string, $escape);
	}
	
}