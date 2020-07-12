<?php
/**
 * Translation class
 * @author			Julian Bogdani <jbogdani@gmail.com>
 * @copyright		BraDypUS, Julian Bogdani <jbogdani@gmail.com>
 * @license			See file LICENSE distributed with this code
 * @since			Apr 7, 2012
 * @uses			cfg
 */


class tr
{

	private static $language_strings;

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
			try {
				$def_lang = cfg::main('lang');
			} catch(myException $e) {
				// do nothing
			}

			$computer_lang = substr($_SERVER["HTTP_ACCEPT_LANGUAGE"], 0, 2);

			if ($def_lang) {
				pref::setLang($def_lang);
			} else if ($computer_lang AND file_exists (LOCALE_DIR . $computer_lang . '.inc') ) {
				pref::setLang($computer_lang);
			} else {
				pref::setLang('en');
			}

		}
		require_once LOCALE_DIR . pref::getLang() . '.inc';

		self::$language_strings = $lang;
	}

	/**
	 *
	 * Translates $string
	 * @param string $string string to translate
	 * @param mixed $args	single argument (string) or array of arguments for formatting
	 * @return string	translated string
	 */
	public static function get($string, $args = [])
	{
		$lang = self::$language_strings;

		$translated = $lang[$string] ? $lang[$string] : $string;

		if (is_string($args)){
			$args = [$args];
		}

		if (is_array($args) && count($args) > 0) {
			return vsprintf(self::get($translated), $args);
		}

		return $translated;
	}

	/**
	 *
	 * Returns current language as json (is_string is false) or as javascript variable (is_string = true)
	 * @param boolean $is_string
	 */
	public static function lang2json($is_string = false)
	{
		$lang = self::$language_strings;

		if ($is_string) {
			echo 'var lang = ' . json_encode($lang) . ';';
		} else {
			return json_encode($lang);
		}
	}
}
