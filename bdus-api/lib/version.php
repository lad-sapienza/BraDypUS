<?php
/**
 * @copyright 2007-2021 Julian Bogdani
 * @license AGPL-3.0; see LICENSE
 * @since Oct 17, 2013
 */
class version
{
	public static function current()
	{
		$va = json_decode( file_get_contents('package.json'), true);
		if (!$va || !is_array($va)) {
			throw new Exception('File `package.json` can not be parsed ' . __FILE__ . ', ' . __LINE__);
		} else {
			return $va['version'];
		}
	}

}
?>
