<?php
/**
 * @copyright 2007-2021 Julian Bogdani
 * @license AGPL-3.0; see LICENSE
 * @since Oct 17, 2013
 */
class version
{
	private static function parse()
	{
		$va = parse_ini_file('version', 1);
		if (!$va) {
			throw new Exception('File `version` can not be parsed ' . __FILE__ . ', ' . __LINE__);
		} else {
			return $va;
		}
	}

	public static function changelog()
	{
		return self::parse();
	}

	public static function current()
	{
		$va = array_keys(self::parse());
		// https://stackoverflow.com/a/35599573/586449
		usort($va, 'version_compare');
		return end($va);
	}

}
?>
