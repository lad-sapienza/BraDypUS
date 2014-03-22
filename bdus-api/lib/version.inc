<?php

/**
 * @author			Julian Bogdani <jbogdani@gmail.com>
 * @copyright		BraDyUS. Communicating Cultural Heritage, http://bradypus.net 2007-2013 
 * @license			All rights reserved
 * @since				Oct 17, 2013
 */
class version
{
	private static function parse()
	{
		$va = parse_ini_file('version', 1);
		if (!$va)
		{
			throw new Exception('File `version` can not be parsed ' . __FILE__ . ', ' . __LINE__);
		}
		else
		{
			return $va;
		}
	}

	public static function changelog()
	{
		return self::parse();
	}

	public static function current()
	{
		$va = self::parse();
		return max(array_keys($va));
	}

}
?>
