<?php
/**
 * @author			Julian Bogdani <jbogdani@gmail.com>
 * @copyright		BraDypUS, Julian Bogdani <jbogdani@gmail.com>
 * @license			See file LICENSE distributed with this code
 * @since			Aug 11, 2012
 */

class debug extends Controller
{

	public function sql2json()
	{
		$param = $this->post;

		$fields = ['id', 'channel', 'level', 'message', 'time'];

		$q = 'SELECT * FROM ' . PREFIX . 'log WHERE ';

		$v = [];
		$w = [];

		if ($param['sSearch']) {
			foreach ($fields as $f) {
				$w[] = "$f LIKE ?";
				$v[] = "%{$param['sSearch']}%";
			}
			$q .= implode(' OR ', $w);
		} else {
			$q .= ' 1=1';
		}

		$response['sEcho'] = intval($param['sEcho']);
		$response['query_arrived'] = $q;

		if ( isset($param['iTotalRecords']) ) {
			$response['iTotalRecords'] = $param['iTotalRecords'];
		} else {
			$res_tot = $this->db->query('SELECT count(id) as tot FROM ' . PREFIX . 'log WHERE 1=1');
			$response['iTotalRecords'] = $res_tot[0]['tot'];
		}

		$response['iTotalDisplayRecords'] = $response['iTotalRecords'];

		if (isset($param['iSortCol_0'])) {
			$q .= ' ORDER BY ' . $fields[$param['iSortCol_0']] . ' ' . ($param['sSortDir_0']==='asc' ? 'asc' : 'desc');
		} else {
			$q .= ' ORDER BY id DESC';
		}

		if (isset($param['iDisplayStart']) && $param['iDisplayLength'] !== '-1') {
			$q .= ' LIMIT ' . $param['iDisplayLength'] . ' OFFSET ' . $param['iDisplayStart'];
		} else {
			$q .= ' LIMIT 30 OFFSET 0 ';
		}

		$response['query_executed'] = $q;

		error_log($q);
		error_log(json_encode($v));

		$response['aaData'] = $this->db->query($q, $v);

		foreach ($response['aaData'] as $id => &$row) {
			$date = new DateTime();
			$date->setTimestamp($row['time']);
			$row['time'] = $date->format('Y-m-d H:i:s');
			$response['aaData'][$id]['DT_RowId'] = $row['id'];
		}

		echo json_encode($response);
	}

	public function read()
	{
		
		
		$fields = ['id', 'channel', 'level', 'message', 'time'];
		
		$this->render('debug', 'read', [
			'th_fields' => '<th>' . implode('</th><th>', $fields) . '</th>',
			'm_data' => '{"mData":"' . implode('"},{"mData":"', $fields) . '"}',
			'ajaxSource' => "./?obj=debug&method=sql2json"
		]);
	}
}
