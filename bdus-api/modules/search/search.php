<?php
/**
 * @author			Julian Bogdani <jbogdani@gmail.com>
 * @copyright		BraDypUS 2007-2012
 * @license			See file LICENSE distributed with this code
 * @since			Sep 11, 2012
 */

class search_ctrl extends Controller
{
	
	/**
	 * @param $this->request['tb']
	 * @param $this->request['fld']
	 */
	public function getUsedValues()
	{
		$q = "SELECT `{$this->request['fld']}` FROM `{$this->request['tb']}` WHERE 1 GROUP BY `{$this->request['fld']}`";
		$db = new DB();
		$res = $db->query($q);
		foreach ($res as $r)
		{
			$arr[] = $r[$this->request['fld']];
		}
		echo json_encode($arr);
	}
	/**
	 * @param $this->request
	 */
	public function test()
	{
		try
		{
			$queryObj = new Query(new DB(), $this->request, true);
			$resp['status'] = 'success';
			$resp['verbose'] = tr::sget('test_ok_x_found', $queryObj->getTotal());
		}
		catch (myException $e)
		{
			$resp['status'] = 'error';
			$resp['verbose'] = tr::get('test_error');
		}
		
		echo json_encode($resp);
	}
	
	
	
	/**
	 * @param $this->request['tb']
	 */
	public function expertGUI()
	{
		$this->render('search', 'expertGUI', array(
				'tr'		=> new tr(),
				'tb'		=> $this->request['tb'],
				'fields'	=> cfg::fldEl($this->request['tb'], 'all', 'name'),
				'operators'	=> array ('=', '!=', 'LIKE', '>', '<', '>=', '<=', 'IS NULL', 'IS NOT NULL', '(', ')', '%', "'", 'AND', 'OR', 'NOT'),
				'uid'		=> uniqid('uid')
		));
	}
	
	
	
	/**
	 * 
	 * @param $this->request['tb']
	 */
	public function advancedGUI()
	{
		$this->render('search', 'advanced', array(
				'tr'		=> new tr(),
				'fields'	=> $this->opt_all_flds($this->request['tb']),
				'operators'	=> $this->opt_operators(),
				'connector'	=> $this->opt_connector($this->request['tb']),
				'order'		=> $this->opt_all_flds($this->request['tb']),
				'tb'		=> $this->request['tb'],
				'uid'		=> uniqid('uid')
				));
	}
	

	private function opt_connector()
	{
		
		$connectors = array(
			'AND'	=>	'AND',
			'OR' 	=>	'OR',
			'XOR'	=>	'XOR'	
		);
		
		foreach ($connectors as $connector => $label)
		{
			$opt[] = '<option value="' . $connector . '">' . $label . '</option>';
		}
		return implode("\n", $opt);
	}
	
	
	private function opt_operators()
	{
		$operators = array(
				'LIKE'		=>	tr::get('contains'),
				'=' 		=>	tr::get('is_exactly'),
				'NOT LIKE'	=>	tr::get('doesnt_contain'),
				'starts_with'=>	tr::get('starts_with'),
				'ends_with'	=>	tr::get('ends_with'),
				'is_empty'	=> 	tr::get('is_empty'),		// SQL: `field`='' OR `field` IS NULL
				'is_not_empty'=>tr::get('is_not_empty'),
				'>'			=>	tr::get('bigger'),
				'<'			=>	tr::get('smaller')
		);
		
		foreach ($operators as $operator => $label)
		{
			$opt[] = '<option value="' . $operator . '">' . $label . '</option>';
		}
		return implode("\n", $opt);
	}
	
	
	private function opt_all_flds($tb)
	{
		$fields = cfg::fldEl($tb, 'all', 'label');
		
		foreach ($fields as $name=>$label)
		{
			$opt[] = '<option value="' . $tb . ':' . $name .'">' . $label . '</option>';
		}
		$plg = cfg::tbEl($tb, 'plugin');
		
		if (is_array($plg))
		{
			foreach ($plg as $p)
			{
				$fields = cfg::fldEl($p, 'all', 'label');
				
				foreach ($fields as $name=>$label)
				{
					$opt[] = '<option value="' . $p . ':' . $name .'"># ' . $label . '</option>';
				}
			}
		}
		return implode("\n", $opt);
	}
}