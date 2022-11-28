<?php
/**
 * @copyright 2007-2022 Julian Bogdani
 * @license AGPL-3.0; see LICENSE
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
		$tb = $this->request['tb'];
		$fld = $this->request['fld'];

		// check if query field ia a id_from_tb field
		$second_table = $this->cfg->get("tables.$tb.fields.$fld.id_from_tb");

		if ($second_table) {
			$second_field = $this->cfg->get("tables.{$second_table}.id_field");
			$q = "SELECT {$second_field} as {$fld} FROM {$second_table} WHERE 1=1 GROUP BY {$second_field}";

		} else {

			$q = "SELECT {$fld} FROM {$tb} WHERE 1=1 GROUP BY {$fld}";
		}

		$res = $this->db->query($q);
		foreach ($res as $r){
			$arr[] = $r[$fld];
		}
		echo json_encode($arr);
	}
	/**
	 * @param $this->request
	 */
	public function test()
	{
		try {
			$queryObj = new QueryFromRequest($this->db, $this->cfg, $this->request, true);
			$resp['status'] = 'success';
			$resp['verbose'] = \tr::get('test_ok_x_found', [$queryObj->getTotal()]);
		} catch (\Throwable $e) {
			$resp['status'] = 'error';
			$resp['verbose'] = \tr::get('test_error');
		}

		echo json_encode($resp);
	}



	/**
	 * @param $this->request['tb']
	 */
	public function expertGUI()
	{
		$tb = $this->request['tb'];
		
		$this->render('search', 'expertGUI', [
			'tb'		=> $tb,
			'fields'	=> $this->cfg->get("tables.{$tb}.fields.*.label"),
			'operators'	=> array ('=', '!=', 'LIKE', '>', '<', '>=', '<=', 'IS NULL', 'IS NOT NULL', '(', ')', '%', "'", 'AND', 'OR', 'NOT')
		]);
	}



	/**
	 *
	 * @param $this->request['tb']
	 */
	public function advancedGUI()
	{
		$tb = $this->request['tb'];
		$this->render('search', 'advanced', [
			'fields'	=> $this->opt_all_flds($tb),
			'operators'	=> $this->opt_operators(),
			'connector'	=> $this->opt_connector($tb),
			'order'		=> $this->opt_all_flds($tb),
			'tb'		=> $tb,
		]);
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
				'LIKE'		=>	\tr::get('contains'),
				'=' 		=>	\tr::get('is_exactly'),
				'NOT LIKE'	=>	\tr::get('doesnt_contain'),
				'starts_with'=>	\tr::get('starts_with'),
				'ends_with'	=>	\tr::get('ends_with'),
				'is_empty'	=> 	\tr::get('is_empty'),		// SQL: field='' OR field IS NULL
				'is_not_empty'=>\tr::get('is_not_empty'),
				'>'			=>	\tr::get('bigger'),
				'<'			=>	\tr::get('smaller')
		);

		foreach ($operators as $operator => $label)
		{
			$opt[] = '<option value="' . $operator . '">' . $label . '</option>';
		}
		return implode("\n", $opt);
	}


	private function opt_all_flds($tb)
	{
		$fields = $this->cfg->get("tables.$tb.fields.*.label");

		foreach ($fields as $name => $label) {
			$opt[] = '<option value="' . $tb . ':' . $name .'">' . $label . '</option>';
		}

		$plg = $this->cfg->get("tables.{$tb}.plugin");

		if (is_array($plg)) {
			foreach ($plg as $p) {

				$fields = $this->cfg->get("tables.{$p}.fields.*.label");

				foreach ($fields as $name => $label) {
					$opt[] = '<option value="' . $p . ':' . $name .'">' . $this->cfg->get("tables.$p.label") . " > " . $label . '</option>';
				}
			}
		}
		return implode("\n", $opt);
	}
}
