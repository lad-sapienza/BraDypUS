<?php
/**
 * Search and replace controller class
 * @author			Julian Bogdani <jbogdani@gmail.com>
 * @copyright		BraDypUS, Julian Bogdani <jbogdani@gmail.com>
 * @license			See file LICENSE distributed with this code
 * @since			Aug 10, 2012
 */

class search_replace_ctrl extends Controller
{
	public function main_page()
	{
		echo $this->render('search_replace', 'main_page', array(
				'tbs' => cfg::getNonPlg()
				));
	}
	
	/**
	 * Echoes json encoded array of available fields for $table
	 */
	public function getFld()
	{
		$tb = $this->get['tb'];
		
		echo json_encode(cfg::fldEl($tb, 'all', 'label'));
	}
	
	/**
	 * Executes search & replace query and returns no of affected rows
	 */
	public function replace()
	{
		$tb = $this->get['tb'];
		$fld = $this->get['fld'];
		$search = $this->get['search'];
		$replace = $this->get['replace'];
		
		try
		{
			$db = new DB();
			
			$query = "UPDATE `" . $tb . "` SET `" . $fld . "` = REPLACE (`" . $fld . "`, '" . str_replace("'", "\'", $search) . "', '" . str_replace("'", "\'", $replace ) . "')";
			
			$values = false;
			
			$no = $db->query($query, $values, 'affected');
			
			echo $no;
		}
		catch(myException $e)
		{
			$e->log();
			echo 'error';
		}
	}
}