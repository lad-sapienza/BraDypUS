<?php
/**
 * @author			Julian Bogdani <jbogdani@gmail.com>
 * @copyright		BraDypUS, Julian Bogdani <jbogdani@gmail.com>
 * @license			See file LICENSE distributed with this code
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
    Meta::addErrorLog($this);
	}
}
