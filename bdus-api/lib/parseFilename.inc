<?php
/**
 * @author			Julian Bogdani <jbogdani@gmail.com>
 * @copyright		BraDypUS 2007-2012
 * @license			See file LICENSE distributed with this code
 * @since			Aug 11, 2012
 */

class parseFilename
{
	private static $file = array();

	public static function parse($filename)
	{
		self::$file = array();
		$filename = str_replace(' ', null, $filename);
		$pathinfo = pathinfo($filename);
		$filename = $pathinfo['filename'];
		$ext = $pathinfo['extension'];

		$parts = explode('_', $filename);

		self::$file['all'] = $filename;
		self::$file['prefix'] = $parts[0];

		self::$file['ext'] = $ext;

		if ($parts[1] && $parts[2])
		{
			self::$file['tb'] = PREFIX . '__' . $parts[1];
			
			self::commas($parts[2]);
		}
		
		return self::$file;
	}
	
	
	private static function commas($str)
	{
		if (preg_match('/,/', $str))
		{
			$parts = explode(',', $str);
			foreach($parts as $part)
			{
				self::ranges($part);
			}
		}
		else
		{
			self::ranges($str);
		}
	}

	private static function ranges($str)
	{
		if (preg_match('/-/', $str))
		{
			$parts = explode('-', $str);
				
			$start = (int)$parts[0];
			$end = (int)$parts[1];

			for($x=$start; $x<=$end; $x++)
			{
				self::$file['id'][] = $x;
			}
		}
		else
		{
			self::$file['id'][] = (int)$str;
		}
	}
}