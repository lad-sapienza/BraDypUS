<?php
/**
 * @author			Julian Bogdani <jbogdani@gmail.com>
 * @copyright		BraDypUS, Julian Bogdani <jbogdani@gmail.com>
 * @license			See file LICENSE distributed with this code
 * @since			Jan 10, 2013
 */

class record_ctrl extends Controller
{

	public function save_data()
	{
		try
		{
			if (!$this->request['id'])
			{
				$this->request['id'] = [false];
			}
			else if (!is_array($this->request['id']))
			{
				$this->request['id'] = (array)$this->request['id'];
			}


			if (is_array($this->request['id']))
			{
				foreach ($this->request['id'] as $id)
				{
					try
					{
						$record = new Record($this->get['tb'], $id, new DB);

						if (is_array($this->post['core']))
						{
							$record->setCore($this->post['core']);
						}

						if (is_array($this->post['plg']))
						{
							foreach ($this->post['plg'] as $plg_name=>$plg_data)
							{
								$record->setPlugin($plg_name, $plg_data);
							}
						}

						$a = $record->persist();

						if (!$id)
						{
							$inserted_id = $record->getId();
						}

						if ($a)
						{
							$ok[$id] = true;
						}
						else
						{
							$error[$id] = true;
						}
					}
					catch (myException $e)
					{
						$error[$id] = true;
						$e->log();
					}
				}
			}

			if (count($ok) == count($this->request['id']))
			{
				$data['status'] = 'success';
				$data['verbose'] = tr::get('success_saved');
				$inserted_id ? $data['inserted_id'] = $inserted_id : '';
			}
			else if (count($error) == count($this->request['id']))
			{
				$data['status'] = 'error';
				$data['verbose'] = tr::get('error_saved');
			}
			else
			{
				$data['status'] = 'warning';
				$data['verbose'] = tr::sget('partial_success_saved', array(implode(', ', $ok), imaplode(', ', $error)));
			}
		}
		catch (myException $e){
			$data['status'] = 'error';
			$data['verbose'] = tr::get('error_saved');
			$e->log($e);
		}

		echo json_encode($data);
	}



	public function erase()
	{
		if (!utils::canUser('edit'))
		{
			$data = array('status' => 'error', 'text'=> utils::alert_div('not_enough_privilege'));
			echo json_encode($data);
			return;
		}
		if (is_array($this->request['id']))
		{
			foreach($this->request['id'] as $id)
			{
				try
				{
					$record = new Record($this->get['tb'], $id, new DB);

					$record->delete();

					$ok[] = true;
				}
				catch (myException $e)
				{
					$error[] = true;
				}
			}

			if (count($this->request['id']) == count($error))
			{
				$data = array('status' => 'error', 'text' => tr::get('no_record_deleted'));
			}
			else if (count($this->request['id']) == count($ok))
			{
				$data = array('status' => 'success', 'text' => tr::get('all_record_deleted'));
			}
			else
			{
				$data = array('status' => 'warning', 'text' => tr::sget('partially_deleted_with_count', array(count($ok), $count($error) ) ) );
			}
		}
		else
		{
			$data = array('status' => 'error', 'text' => tr::get('no_id_provided') );
		}

		echo json_encode($data);
	}


	public function show()
	{
		if ( !$this->request['tb'] )
		{
			throw new myException(tr::get('tb_missing'));
		}

		// user must have enough privileges
		if (!utils::canUser('read') )
		{
			utils::alert_div('not_enough_privilege', true);
			return;
		}
		// a record id must be provided in edit & read & preview mode
		if ( $this->request['a'] !== 'add_new' && !$this->request['id'] && !$this->request['id_field'] )
		{
			throw new myException(tr::get('no_id_to_view'));
		}

		// $show_next is the id to show in edit mode, after edit is completed
		if ($this->request['a'] == 'add_new')
		{
			$show_next = "['last_added']";
		}
		else if (is_array($this->request['id']))
		{
			$show_next = "['" . implode($this->request['id']) . "']";
		}
		else if (is_array($this->request['id_field']))
		{
			$show_next = "['" . implode($this->request['id_field']) . "']";
		}

		// no data are retrieved if context is add_new or multiple edit!
		if ($this->request['a'] == 'add_new' OR ($this->request['a'] == 'edit' AND count($this->request['id']) > 1))
		{
			$id_arr = array('new');
		}
		else if ($this->request['id_field'])
		{
			$id_arr = $this->request['id_field'];
			$flag_idfield = true;
		}
		else
		{
			$id_arr = $this->request['id'];
		}

		//Can not display more than 500 records!
		if (count($id_arr) > 500)
		{
			echo '<div class="alert">' . tr::sget('too_much_records', array(count($id_arr), '500')) . '</div>';
			return;
		}
		$step = 10;
		foreach ($id_arr as $index=>$id)
		{
			if ($index > ($step))
			{
				return;
			}
			if ($index == ($step-1) && $id_arr[($index + $step)])
			{
				$continue_url = 'id[]=' . implode('&id[]=', array_slice($id_arr, ($index + $step)));
			}
			else if ($index == (count($id_arr) -1))
			{
				$continue_url = 'end';
			}

			if ($id == 'new')
			{
				$id = false;
			}

			$record = new Record($this->request['tb'], ($flag_idfield ? false : $id), new DB);

			if ($flag_idfield)
			{
				$record->setIdField($id);
			}

			if ($this->request['a'] == 'edit' &&
					(!utils::canUser('edit', $record->getCore('creator')) || ( count($this->request['id']) > 1 && !utils::canUser('multiple_edit') ) ) )
			{
				echo '<h2>' . tr::get('not_enough_privilege') . '</h2>';
				continue;
			}

			if ($this->request['a'] == 'add_new' && !utils::canUser('add_new'))
			{
				echo '<h2>' . tr::get('not_enough_privilege') . '</h2>';
				continue;
			}

			$tmpl = new ParseTmpl($this->request['a'], $record);

     	$this->render('record', 'show', array(
					'form_id' => uniqid('editadd') . rand(10,999),
					'action' => $this->request['a'],
					'html' => $tmpl->parseAll(),
					'multiple_id' => (count($this->request['id']) > 1) ? tr::sget('multiple_edit_alert', array(count($this->request['id']), implode('; id: ', $this->request['id']))) : false,
					'tb' => $this->request['tb'],
					'id_url' => is_array($this->request['id']) ? 'id[]=' . implode('&id[]=', $this->request['id']) : false,
					'totalRecords' => count($id_arr),
					'id' => $flag_idfield ? $record->getCore('id') : $id,
					'show_next' => $show_next,
					'can_edit' => (utils::canUser('edit', $record->getCore('creator')) || ( count($this->request['id']) > 1 && utils::canUser('multiple_edit') ) ),
					'can_erase' => utils::canUser('edit', $record->getCore('creator')),
					'continue_url' => $continue_url,
					'virtual_keyboard' => cfg::main('virtual_keyboard')
			));
		}


	}

	public function showResults()
	{
		if (!utils::canUser('read'))
		{
			echo utils::message(tr::get('not_enough_privilege'), 'error', 1);
			return;
		}

		if ( !$this->request['tb'] )
		{
			throw new myException(tr::get('tb_missing'));
		}

		$queryObj = new Query(new DB(), $this->request, true);

		$count = $this->request['total'] ?: $queryObj->getTotal();

		if ($count == 0)
		{
			$noResult = true;
		}

		$this->render('record', 'result', array(
				'tb' => $this->request['tb'],
				'records_found' => ($noResult ? tr::get('no_record_found') : tr::sget('x_record_found', $count)),
				'can_user_add' => utils::canUser('add_new'),
				'can_user_read' => utils::canUser('read'),
				'can_user_edit' => utils::canUser('edit'),
				'urlencoded_query' => urlencode($queryObj->getQuery()),
				'encoded_query' => base64_encode($queryObj->getQuery()),
				'encoded_where' => $queryObj->getWhere(true),
				'uid' => uniqid(),
				'noResult' => $noResult,
				'noDblClick' => $this->request['noDblClick'],
				'hasRS' => cfg::tbEl($this->request['tb'], 'rs'),
				'noOpts' => $this->request['noOpts'],
				'col_names' => $queryObj->getFields(),
				'iTotalRecords' => $count,
				'lang' => pref::getLang(),
				//TODO: user panel controle for user preferences
				'infinte_scroll' => pref::get('infinite_scroll'),
        'select_one' => $this->request['select_one'],
				'hideId' => (cfg::fldEl($this->request['tb'], 'id', 'hide') == 1)
		));


	}

	/**
	 * http://datatables.net/usage/server-side
	 * 	REQUEST
	 * 		q_encoded
	 * 		sEcho: (int)
	 * 		iTotalRecords: (int)
	 * 		iDisplayStart
	 * 		iDisplayLength
	 * 		iSortCol_0
	 * 		sSortDir_0
	 * 		sSearch
	 * 		iTotalDisplayRecords
	 */
	public function sql2json()
	{
		$this->request['type'] = 'encoded';

		$qObj = new Query(new DB(), $this->request, true);

		$response['sEcho'] = intval($this->request['sEcho']);
		$response['query_arrived'] = $qObj->getQuery();

		$response['iTotalRecords'] = $response['iTotalDisplayRecords'] = isset($this->request['iTotalRecords']) ? $this->request['iTotalRecords'] : $qObj->getTotal();

		if (isset($this->request['iDisplayStart']) && $this->request['iDisplayLength'] != '-1')
		{
			$qObj->setLimit(intval( $this->request['iDisplayStart'] ), $this->request['iDisplayLength']);
		}

		if (isset($this->request['iSortCol_0']))
		{
			$fields = array_keys($qObj->getFields());
			$qObj->setOrder($fields[$this->request['iSortCol_0']], ($this->request['sSortDir_0']==='asc' ? 'asc' : 'desc'));
		}

		if ($this->request['sSearch'])
		{
			$qObj->setSubQuery($this->request['sSearch']);
			$response['iTotalDisplayRecords'] = $qObj->getTotal();
		}

		$response['query_executed'] = $qObj->getQuery();

		$response['aaData'] = $qObj->getResults();

		foreach($response['aaData'] as $id => &$row) {
			$response['aaData'][$id]['DT_RowId'] = $row['id'];
		}

		echo json_encode($response);
	}



	public function check_duplicates()
	{
		try
		{
			if (!$this->request['fld'] OR !$this->request['val'])
			{
				throw new myException('Field name and value are required');
			}
			if (preg_match('/core\[/', $this->request['fld']))
			{
				$arr = explode('][', preg_replace('/core\[(.+)\]/', '$1', $this->request['fld']));

				$query = 'SELECT count(*) as `tot` FROM `' . $arr[0] . '` WHERE `' . $arr[1] . '`=:' . $arr[1];

				$res = DB::start()->query($query, array(':' . $arr[1] => $this->request['val']), 'read');

				if ($res[0]['tot'] > 0)
				{
					echo 'error';
				}
			}
		}
		catch (myException $e)
		{
			echo 'error';
			$e->log();
		}

	}
}
