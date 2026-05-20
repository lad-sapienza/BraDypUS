<?php

/**
 * @copyright 2007-2022 Julian Bogdani
 * @license AGPL-3.0; see LICENSE
 * @since Oct 17, 2013
 */
class version
{
  /**
   * Returns current version number, as reported in package.json
   * @throws \Exception in case of errors
   * @return string
   */
  public static function current(): string
  {
    if (!file_exists('composer.json')) {
      throw new \Exception('File `composer.json` not found.');
    }
    $va = json_decode(file_get_contents('composer.json'), true);
    if (!$va || !is_array($va)) {
      throw new \Exception('File `composer.json` can not be parsed in: ' . __FILE__ . ', ' . __LINE__);
    } else {
      return $va['version'];
    }
  }
}
