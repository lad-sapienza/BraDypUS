<?php
/**
 * @author			Julian Bogdani <jbogdani@gmail.com>
 * @copyright		BraDypUS 2007-2012
 * @license			See file LICENSE distributed with this code
 * @since			Jan 10, 2012
 */
 
class autoloader
{
	public function __construct()
	{
		spl_autoload_register(array($this, 'loader'));
	}
	
	private function loader($className)
	{
		if (class_exists($className))
		{
			return true;
		}
		
		if (preg_match('/_ctrl/', $className))
		{
			$mod = str_replace('_ctrl', null, $className);
			
			if(file_exists(MOD_DIR . $mod . '/' . $mod . '.php'))
			{
				require_once MOD_DIR . $mod . '/' . $mod . '.php';
			}
			else
			{
				return false;
				//throw new myException('Error: Can not load class `' . $className . '` (file: ' . MOD_DIR . mod . '/' . $mod . '.php)');
			}
		}
		else
		{
			if (file_exists(LIB_DIR . $className . '.inc'))
			{
				require_once LIB_DIR . $className . '.inc';
			}
			else if (file_exists(LIB_DIR . 'interfaces/' . $className . '.inc'))
			{
				require_once LIB_DIR . 'interfaces/' . $className . '.inc';
			}
			else
			{
				return false;
				//throw new myException('Error: Can not load class `' . $className . '`');
			}
		}
		
	}
}