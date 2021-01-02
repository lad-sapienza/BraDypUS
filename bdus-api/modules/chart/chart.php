<?php
/**
 * @copyright 2008-2021 Julian Bogdani
 * @license AGPL-3.0; see LICENSE
 * @since			Aug 18, 2012
 */

 use \DB\System\Manage;

class chart_ctrl extends Controller
{
	/**
	 *
	 * @param string $this->get['tb']
	 * @param string $this->get['query']
	 */
	public function show_chart_builder()
	{
		$tb = $this->get['tb'];
		$obj_encoded = $this->get['obj_encoded'];

		$this->render('chart', 'show_chart_builder', [
			'tb' => $tb,
			'obj_encoded' => $obj_encoded,
			'fields' => $this->cfg->get("tables.$tb.fields.*.label")
		]);
	}

	/**
	 *
	 * @param string $this->get['tb']
	 * @param string $this->get['remove']
	 */
	public function show_row()
	{
		$this->render('chart', 'show_row', [
			'remove' => $this->get['remove'],
			'flds' => $this->cfg->get("tables.{$this->get['tb']}.fields.*.label"),
		]);
	}

	/**
	 *
	 * @param array $this->post
	 */
	public function process_chart_data()
	{
		$post = $this->post;

		if (!empty($post['series'])) {
			$group_by = ' GROUP BY ' . $post['series'] . ' ';
		}

		foreach ($post['bar_fld'] as $bar_id=>$bar_arr) {
			$bar[] = ' ' . $post['bar_function'][$bar_id] . '(' . implode(') + ' . $post['bar_function'][$bar_id] . '(', $bar_arr) . ') '
				. ( $post['bar_name'][$bar_id] ? ' AS ' . $post['bar_name'][$bar_id] . '' : '');
		}

		$sql = 'SELECT ' .
			( $post['series'] ? $post['series'] . ' as series_name, ' : '')  .
			implode(', ', $bar) .
			' FROM ' . $post['tb'] . ' WHERE ' .
			($post['query'] ? ' ' . base64_decode($post['query']). ' ' : ' 1=1 ');

		if ($group_by && preg_match('/ORDER BY/', $sql)) {
			$sql = preg_replace('/(.+)ORDER BY(.+)/', '$1 ' . $group_by . ' ORDER BY $2', $sql);
		} else if($group_by) {
			$sql .= ' ' . $group_by;
		}

		$formatted_data = $this->formatResult( $this->db->query($sql) );

		$this->render('chart', 'display_chart', [
			'encoded_query'	=> base64_encode( $sql ),
			'data'			=> $formatted_data['data'],
			'series'		=> $formatted_data['series'],
			'ticks'			=> $formatted_data['ticks']
		]);

	}

	/**
	 *
	 * @param array $this->post
	 * @throws \Exception
	 */
	public function save_chart_as()
	{
		$post = $this->post;
		
		try {
			if (!$post['query_text']) {
				throw new \Exception('No query text to save!');
			}
			if (!$post['name']) {
				$post['name'] = uniqid('chart_');
			}

			$sys_manager = new Manage($this->db, $this->prefix);
			$res = $sys_manager->addRow('charts', [
				'user_id'	=> $_SESSION['user']['id'],
				'name'		=> $post['name'],
				'sqltext'		=> QueryFromRequest::makeSafeStatement( base64_decode( $post['query_text'] ) ),
				'date'		=> (new \DateTime())->format('Y-m-d H:i:s'),
			]);

			if ( $res ) {
				$this->response('ok_save_chart');
			} else {
				throw new \Exception('Save chart query returned false');
			}
		} catch(\Throwable $e) {
			$this->log->error($e);
			$this->response('error_save_chart', 'error');
		}
	}

	/**
	 *
	 * @param int $this->get['id']
	 */
	public function delete_chart()
	{
		$id = $this->get['id'];

		$sys_manager = new Manage($this->db, $this->prefix);
		$res = $sys_manager->deleteRow('charts', $id);

		if ( $res ) {
			$this->response('ok_chart_erase', 'success');
		} else {
			$this->response('error_chart_erase', 'error');
		}
	}

	/**
	 *
	 * @param int $this->get['id']
	 */
	public function display_chart()
	{
		try {
			$id = $this->get['id'];

			$sys_manager = new Manage($this->db, $this->prefix);
			$chart = $sys_manager->getById('charts', $id);

			$data = $this->db->query($chart['sqltext']);

			$formatted_data = $this->formatResult($data);

			$this->render('chart', 'display_chart', [
				'encoded_query' => base64_encode( $chart['sqltext'] ),
				'data' 			=> $formatted_data['data'],
				'series'		=> $formatted_data['series'],
				'ticks'			=> $formatted_data['ticks']
			]);

		} catch (\Throwable $th) {
			echo \utils::message(\tr::get('chart_id_missing', [$this->get['id']]), 'error', true);
		}
		
		
	}


	public function show_all_charts()
	{
		$sys_manager = new Manage($this->db, $this->prefix);
		$all_charts = $sys_manager->getBySQL('charts', '1=1');

		$this->render('chart', 'show_all_charts', [
			'all_charts' => $all_charts,
			'can_admin' => \utils::canUser('admin'),
		]);

	}

	public function edit_form()
	{
		$id = $this->get['id'];

		$sys_manager = new Manage($this->db, $this->prefix);
		$chart = $sys_manager->getById('charts', $id);

		$this->render('chart', 'edit_form', [
			'chart'	=> $chart
		]);
	}

	public function update_chart()
	{
		$id = $this->post['id'];
		$name = $this->post['name'];
		$text = $this->post['text'];

		try {
			$sys_manager = new Manage($this->db, $this->prefix);
			$res = $sys_manager->editRow('charts', $id, [
				'name' => $name,
				'sqltext' => QueryFromRequest::makeSafeStatement($text)
			] );
			
			if ( $res ) {
				$this->response('ok_update_chart');
			} else {
				throw new \Exception('Update query returned false');
			}
		} catch (\Throwable $e) {
			$this->log->error($e);
			$this->response('error_update_chart', 'error');
		}
	}

	/**
	 * Gets array of data from chart SQL
	 * and returns array with formatted data:
	 * 		series: indexed array of series names ("label" => $series_label)
	 * 		data: array of arrayes with numeric data
	 * 		ticks: Array with ticks (x label)
	 *
	 * @param array $data	array of data of chart SQL
	 * @return array
	 */
	private function formatResult( array $data): array
	{
		
		$row = 0;
		$out = [];
		foreach($data as $id => $one_series) {

			if ($one_series['series_name'] === '') {
				$one_series['series_name'] = \tr::get('no_value');
			}

			$out['series'][$row] = [ 'label' => $one_series['series_name'] ];

			unset($one_series['series_name']);

			$column = 0;

			foreach ($one_series as $label=>$value){

				$out['data'][$row][$column] = (int)$value;

				$out['ticks'][$column] = $label;

				$column++;
			}

			$row++;
		}

		return $out;
	}

}
