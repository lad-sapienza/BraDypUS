<?php
/**
 * Translation class
 * @author			Julian Bogdani <jbogdani@gmail.com>
 * @copyright		BraDypUS, Julian Bogdani <jbogdani@gmail.com>
 * @license			See file LICENSE distributed with this code
 * @since			Apr 7, 2012
 * @uses			cfg
 * @uses			pref
 */


class tr
{

	private static $language_strings = false;

	/**
	 *
	 * Returns lang variable using:
	 * 1) $_SESSION['pref']['lang']
	 * 2) system default language
	 * 3) browser default language
	 * 4) en
	 */
	private static function load_file()
	{
		/*
		 * Set $_SESSION['pref']['lang']
		 */
		// language may be
		if ( !pref::getLang() ) {

			try {
				$def_lang = cfg::main('lang');
			} catch(\Throwable $e) {
				// cfg throws Exception on error, eg.: app is not set yet
				// if def_lang cannot be set, do nothing
			}

			$computer_lang = substr($_SERVER["HTTP_ACCEPT_LANGUAGE"], 0, 2);

			if ($def_lang && file_exists (LOCALE_DIR . $def_lang . '.json')) {
				pref::setLang($def_lang);
			} else if ($computer_lang && file_exists (LOCALE_DIR . $computer_lang . '.json') ) {
				pref::setLang($computer_lang);
			} else {
				pref::setLang('en');
			}
		}
		self::$language_strings = json_decode(
			file_get_contents(LOCALE_DIR . pref::getLang() . '.json'),
			true
		);

		return self::$language_strings;
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
		$lang = self::$language_strings ?: self::load_file();

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
	 * Returns current language as json
	 * @return string JSON encoded
	 */
	public static function lang2json()
	{
		if(!self::$language_strings){
			return json_encode(self::load_file());
		}
		return json_encode(self::$language_strings);
	}

	public static function getAvailable(): array
	{
		$langs = utils::dirContent(LOCALE_DIR) ?? [];
		
		array_walk($langs, function(&$el){ $el =  str_replace('.json', null, $el); });

		return $langs;
	}
}
