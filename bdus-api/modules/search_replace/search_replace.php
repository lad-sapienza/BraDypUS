<?php
/**
 * @copyright 2008-2021 Julian Bogdani
 * @license AGPL-3.0; see LICENSE
 * @since			Aug 10, 2012
 */

class search_replace_ctrl extends Controller
{
	public function main_page()
	{
		$this->render('search_replace', 'main_page', [
			'tbs' => $this->cfg->get('tables.*.label')
		]);
	}

	/**
	 * Echoes json encoded array of available fields for $table
	 */
	public function getFld()
	{
		$tb = $this->get['tb'];

		echo json_encode($this->cfg->get("tables.$tb.fields.*.label"));
	}

	/**
	 * Executes search & replace query and returns no of affected rows
	 */
	public function replace()
	{
		$tb 		= $this->get['tb'];
		$fld 		= $this->get['fld'];
		$search 	= $this->get['search'];
		$replace 	= $this->get['replace'] ?? '';
		
		try {
			if (!$tb || !$fld || !$search || !$replace) {
				throw new \Exception('All fields are required');
			}

			$values = false;

			$ret = $this->db->query(
				"UPDATE {$tb} SET {$fld} = REPLACE ({$fld} , ?, ?)", 
				[ $search, $replace], 
				'affected'
			);

			$this->response('ok_search_replace', 'success', [$ret]);
		} catch(\DB\DBException $e) {
			$this->response('error_search_replace', 'error');
		} catch(\Throwable $e) {
			$this->log->error($e);
			$this->response('error_search_replace', 'error');
		}
	}
}
