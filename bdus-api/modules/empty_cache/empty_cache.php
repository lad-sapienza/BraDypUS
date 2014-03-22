<?php
/**
 * @author			Julian Bogdani <jbogdani@gmail.com>
 * @copyright		BraDypUS 2007-2013
 * @license			All rights reserved
 * @since			Aug 10, 2013
 */

class empty_cache_ctrl extends Controller
{
	public function doEmpty()
	{
		try
		{
			utils::emptyDir(MAIN_DIR . 'cache', false);
			
			$response = array('status'=> 'success', 'text'=>tr::get('ok_cache_emptied'));
		}
		catch (myException $e)
		{
			$response = array('status'=> 'error', 'text'=>tr::get('error_cache_not_emptied'));
		}
		
		echo json_encode($response);
	}
}