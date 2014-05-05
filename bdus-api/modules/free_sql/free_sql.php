<?php
/**
 * @author			Julian Bogdani <jbogdani@gmail.com>
 * @copyright		BraDypUS, Julian Bogdani <jbogdani@gmail.com>
 * @license			See file LICENSE distributed with this code
 * @since			Jan 8, 2013
 */

class free_sql_ctrl
{
	public static function import($filename, $start = false, $offset = false, $totalqueries = false)
	{
		try
		{
			$bigRestore = new bigRestore(new DB());
			
			$bigRestore->runImport($filename, $start, $offset, $totalqueries);
			
			echo $bigRestore->getResponse(true);
		}
		catch (Exception $e)
		{
			echo json_encode(array('status'=>'error', 'text'=>$e->getMessage()));
		}
	}

	
	public static function input()
	{
		if (utils::canUser('super_admin'))
		{
			$uid = uniqid('upload');
			
			echo '<div class="upload"></div>' .
					'<textarea style="width:97%; height: 220px" placeholder="Enter SQL code here"></textarea>' .
					'<div class="status" style="display:none">' .
						'<div class="progress progress-success">' .
							'<div class="bar" style="width: 0%"></div>' .
						'</div>' .
						'<div class="lead verbose"></div>' .
					'</div>'
			;
		}
		else
		{
			echo tr::get('not_enough_privilege');
		}
	}
	
	public static function run($post)
	{
		try
		{
			$db = new DB();
			
			$db->beginTransaction();
			
			$ret = $db->doQuery($post['sql']);
			
			$db->commit();
			
			if ($ret === false)
			{
				throw new myException();
			}
			
			utils::response('Query executed!');
		}
		catch (myException $e)
		{
			$db->rollBack();
			utils::response('Error. No query executed! <pre><strong>DEBUG:</strong><br />' . $e->getMessage() . '</pre>', 'error');
		}
	}
}