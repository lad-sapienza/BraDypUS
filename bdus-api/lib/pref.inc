<?php
/**
 * Manages user preerences
 * @author			Julian Bogdani <jbogdani@gmail.com>
 * @copyright		BraDypUS 2007-2012
 * @license			See file LICENSE distributed with this code
 * @since			Aug 11, 2012
 * 
 * $_SESSION['pref']
 * 	infinte_scroll				boolean, if true infine scroll in result page is activated, else pagination is used
 * 	tmpl
 * 		table-name
 * 			context-of-usage	string,	edit|add_new|read|previre
 * 				template-name	string,	name of template
 * 	lang	string,	user language
 * 	preview
 * 		table-name
 * 			array of fields ids
 */

class pref
{
	/**
	 * Sets template for thable in context
	 * @param string $tb	table name
	 * @param string $context	context
	 * @param string $tmpl	template file name
	 */
	public static function setTmpl($tb, $context, $tmpl = false)
	{
		if ($tmpl)
		{
			$_SESSION['pref']['tmpl'][$tb][$context] = $tmpl;
		}
		else
		{
			unset($_SESSION['pref']['tmpl'][$tb][$context]);
			if (empty($_SESSION['pref']['tmpl'][$tb]))
			{
				unset($_SESSION['pref']['tmpl'][$tb]);
			}
			if (empty($_SESSION['pref']['tmpl']))
			{
				unset($_SESSION['pref']['tmpl']);
			}
		}
		
	}
	
	/**
	 * Gets template name for table and context
	 * @param string $tb	table name
	 * @param string $context	context
	 */
	public static function getTmpl($tb, $context)
	{
		return $_SESSION['pref']['tmpl'][$tb][$context];
	}
	
	
	/**
	 * Get user preference
	 * @param string $el	preference key to get
	 */
	public static function get($el)
	{
		return $_SESSION['pref'][$el];
	}
	
	/**
	 * 
	 * @param string $el	element key to set
	 * @param string $val	value to set, if false key will be unset
	 */
	public static function set($el, $val = false)
	{
		if ($val)
		{
			$_SESSION['pref'][$el] = $val;
		}
		else
		{
			unset($_SESSION['pref']);
		}
	}
	
	/**
	 * Sets user language
	 * @param string $lang language code
	 */
	public static function setLang($lang)
	{
		$_SESSION['pref']['lang'] = $lang;
	}
	
	/**
	 * Gets user language
	 */
	public static function getLang()
	{
		return $_SESSION['pref']['lang'];
	}
	
	/**
	 * Saves pref data to db
	 */
	public static function save2DB()
	{
		$user = new User(new DB());
		
		cookieAuth::update();

		return $user->update($_SESSION['user']['id'], false, false, false, false, serialize($_SESSION['pref']));
	}
	
	/**
	 * Loads ref data from db
	 * @param string $app
	 */
	public static function getFromDB($app = false)
	{
		
		$user = new User(new DB($app));
		
		$uData = $user->getUser(array('id'=>$_SESSION['user']['id']));
		
		unset($_SESSION['pref']);
		if ($uData[0]['settings'])
		{
			$_SESSION['pref'] = unserialize($uData[0]['settings']);
		}
	}
}