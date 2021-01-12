<?php
/**
 * @copyright 2007-2021 Julian Bogdani
 * @license AGPL-3.0; see LICENSE
 * @since			Aug 11, 2012
 */

class debug_ctrl extends Controller
{

	public function sql2json()
	{
		$params = $this->post;

		echo \utils::jsonForTabletop($this->db, $this->prefix . 'log', $params);
	}

	public function read()
	{
		$fields = ['id', 'channel', 'level', 'message', 'time'];
		
		$this->render('debug', 'read', [
			'th_fields' => '<th>' . implode('</th><th>', $fields) . '</th>',
			'm_data' => '{"mData":"' . implode('"},{"mData":"', $fields) . '"}',
			'ajaxSource' => "./?obj=debug_ctrl&method=sql2json"
		]);
	}
}
