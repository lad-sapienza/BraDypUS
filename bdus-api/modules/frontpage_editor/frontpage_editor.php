<?php
/**
 * @author			Julian Bogdani <jbogdani@gmail.com>
 * @copyright		BraDypUS 2007-2012
 * @license			All rights reserved
 * @since			Aug 11, 2012
 */

class frontpage_editor_ctrl
{
	/**
	 * returns filepath
	 */
	private static function getFile(){
		return PROJ_DIR . 'welcome.html';
	}
	
	/**
	 * Reads content from files and echoes it
	 */
	public static function getContent()
	{
		$file = self::getFile();
		
		if (file_exists($file))
		{
			$start = fopen($file, "r");
		}
		else
		{
			$start=fopen($file, "w+");
		}
		
		$text=fread($start, filesize($file));
		
		echo $text;
		
	}
	
	/**
	 * Saves content to file, stripslashed and with no php tags
	 * @param array $content array(text=>content)
	 */
	public static function saveContent($content)
	{
		$file = self::getFile();
		
		$content = utils::clean_text_from_php($content['text']);
		
		$dest = fopen($file, "w");
		
		if(!fwrite($dest, $content))
		{
			fclose($dest);
			echo 'error';
		}
	}
}