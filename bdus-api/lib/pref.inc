<?php

/**
/**
 * @copyright 2007-2021 Julian Bogdani
 * @license AGPL-3.0; see LICENSE
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

use DB\DBInterface;
use DB\System\Manage;

class pref
{
  /**
   * Sets or unsets a template for thable in context
   *
   * @param string $tb
   * @param string $context
   * @param string|null $tmpl
   * @return void
   */
  public static function setTmpl(string $tb, string $context, ?string $tmpl): void
  {
    if ($tmpl) {
      $_SESSION['pref']['tmpl'][$tb][$context] = $tmpl;
    } else {
      unset($_SESSION['pref']['tmpl'][$tb][$context]);
      if (empty($_SESSION['pref']['tmpl'][$tb])) {
        unset($_SESSION['pref']['tmpl'][$tb]);
      }
      if (empty($_SESSION['pref']['tmpl'])) {
        unset($_SESSION['pref']['tmpl']);
      }
    }
  }

  /**
   * Gets template name for table and context
   * 
   * @param string $tb	table name
   * @param string $context	context
   */
  public static function getTmpl(string $tb, string $context): string
  {
    return $_SESSION['pref']['tmpl'][$tb][$context];
  }


  /**
   * Get user preference
   * @param string $el	preference key to get
   */
  public static function get(string $el): string
  {
    return $_SESSION['pref'][$el];
  }

  /**
   * Sets or unsets a preference
   *
   * @param string $el
   * @param string|null $val
   * @return void
   */
  public static function set(string $el, ?string $val): void
  {
    if ($val) {
      $_SESSION['pref'][$el] = $val;
    } else {
      unset($_SESSION['pref']);
    }
  }

  /**
   * Sets user language
   * 
   * @param string $lang language code
   */
  public static function setLang(string $lang): void
  {
    $_SESSION['pref']['lang'] = $lang;
  }

  /**
   * Gets user language
   * 
   * @return string
   */
  public static function getLang(): string
  {
    return $_SESSION['pref']['lang'];
  }

  /**
   * Saves pref data to db
   *
   * @param DBInterface $db
   * @return boolean
   */
  public static function save2DB(DBInterface $db): bool
  {
    \cookieAuth::update();
    $sys_manager = new Manage($db, PREFIX);
    return $sys_manager->editRow(
      'users',
      $_SESSION['user']['id'],
      [
        'settings' => serialize($_SESSION['pref'])
      ]
    );
  }
}
