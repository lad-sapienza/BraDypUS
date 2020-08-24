<?php
/**
 * @author			Julian Bogdani <jbogdani@gmail.com>
 * @copyright		BraDypUS, Julian Bogdani <jbogdani@gmail.com>
 * @license			See file LICENSE distributed with this code
 * @since			Aug 18, 2012
 */

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
		$query = $this->get['query'];

		$this->render('chart', 'show_chart_builder', [
			'tb' => $tb,
			'query' => $query,
			'fields' => cfg::fldEl($this->get['tb'], 'all', 'label')
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
			'flds' => cfg::fldEl($this->get['tb'], 'all', 'label'),
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
			( $post['series'] ?: '')  .
			implode(', ', $bar) .
			' FROM ' . $post['tb'] . ' WHERE ' .
			($post['query'] ? ' ' . base64_decode($post['query']). ' ' : '' . ' 1=1 ');

		if ($group_by && preg_match('/ORDER BY/', $sql)) {
			$sql = preg_replace('/(.+)ORDER BY(.+)/', '$1 ' . $group_by . ' ORDER BY $2', $sql);
		} else if($group_by) {
			$sql .= ' ' . $group_by;
		}

		$ch = new Charts($this->db);

		$ch->formatResult(false, $sql);

		$this->render('chart', 'display_chart', [
			'data'	=> $ch->getData()
		]);

	}

	/**
	 *
	 * @param array $this->post
	 * @throws myException
	 */
	public function save_chart_as()
	{
		$post = $this->post;
		
		try {
			if (!$post['query_text']) {
				throw new myException('No query text to save!');
			}
			if (!$post['name']) {
				$post['name'] = uniqid('chart_');
			}

			$chart = new Charts($this->db);

			if ($chart->save($post['name'], $post['query_text'])) {
				utils::response('ok_save_chart');
			} else {
				throw myException('Save chart query returned false');
			}
		} catch(myException $e) {
			$this->log->error($e);
			utils::response('error_save_chart', 'error');
		}
	}

	/**
	 *
	 * @param int $this->get['id']
	 */
	public function delete_chart()
	{
		$id = $this->get['id'];

		$chart = new Charts($this->db);

		if ( $chart->erase($id) ) {
			utils::response('ok_chart_erase');
		} else {
			utils::response('error_chart_erase', 'error');
		}
	}

	/**
	 *
	 * @param int $this->get['id']
	 */
	public function display_chart()
	{
		try {
			$chart = new Charts($this->db);

			$chart->formatResult($this->get['id']);

			$this->render('chart', 'display_chart', [
				'data'	=> $chart->getData()
			]);

		} catch (\Throwable $th) {
			echo utils::message(tr::get('chart_id_missing', [$this->get['id']]), 'error', true);
		}
		
		
	}


	public function show_all_charts()
	{
		$charts = new Charts($this->db);

		$this->render('chart', 'show_all_charts', [
			'all_charts' => $charts->getCharts(),
			'can_admin' => utils::canUser('admin'),
		]);

	}

	/**
	 *
	 * @param int $this->get['id']
	 */
	public function edit_form()
	{
		$chart = new Charts($this->db);

		$mych = $chart->getCharts($this->get['id']);

		$mych = $mych[0];

		$this->render('chart', 'edit_form', [
			'chart'	=> $mych
		]);
	}

	/**
	 *
	 * @param array $this->post
	 * @throws myException
	 */
	public function update_chart()
	{
		$id = $this->post['id'];
		$name = $this->post['name'];
		$text = $this->post['text'];

		try {
			$chart = new Charts($this->db);

			if ($chart->update($id, $name, $text)) {
				utils::response('ok_update_chart');
			} else {
				throw new myException('Update query returned false');
			}
		} catch (myException $e) {
			$this->log->error($e);
			utils::response('error_update_chart', 'error');
		}
	}

}
