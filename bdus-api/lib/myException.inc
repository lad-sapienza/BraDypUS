<?php
/**
 * @author			Julian Bogdani <jbogdani@gmail.com>
 * @copyright		BraDypUS 2007-2011
 * @license			All rights reserved
 * @since			Apr 7, 2012
 */
 
class myException extends Exception
{
	/**
	 * 
	 * adds new method to Exception to log error in file
	 * @param string $file	file path to log to
	 */
	public function log ($file = NULL)
	{
		if (!$file)
		{
			$file = ERROR_LOG;
		}
		$string = '[' . date(c) . ']' 
				. $this->getMessage() 
				. '[in: ' . $this->getFile(). ', l.: ' . $this->getLine() . ']'
				. '[' . $this->getCode() . ']'
				. '[' . $this->getTraceAsString() . ']' 
				. "\n";
				
		error_log(
			$string,
			3,
			$file
			);
	}
}