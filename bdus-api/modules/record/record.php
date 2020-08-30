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
        try {
            if (!$this->request['id']) {
                $this->request['id'] = [false];
            } elseif (!is_array($this->request['id'])) {
                $this->request['id'] = (array)$this->request['id'];
            }

            $ok = [];
            $error = [];
            if (is_array($this->request['id'])) {
                foreach ($this->request['id'] as $id) {
                    try {
                        $record = new Record($this->get['tb'], $id, $this->db);

                        if (is_array($this->post['core'])) {
                            $record->setCore($this->post['core']);
                        }

                        if (is_array($this->post['plg'])) {
                            foreach ($this->post['plg'] as $plg_name=>$plg_data) {
                                $record->setPlugin($plg_name, $plg_data);
                            }
                        }

                        $a = $record->persist();

                        if (!$id) {
                            $inserted_id = $record->getId();
                        }

                        if ($a) {
                            $ok[$id] = true;
                        } else {
                            $error[$id] = true;
                        }
                    } catch (\Exception $e) {
                        $error[$id] = true;
                        $this->log->error($e);
                    }
                }
            }

            if (count($ok) == count($this->request['id'])) {
                $data['status'] = 'success';
                $data['verbose'] = tr::get('success_saved');
                $inserted_id ? $data['inserted_id'] = $inserted_id : '';
            } elseif (count($error) == count($this->request['id'])) {
                $data['status'] = 'error';
                $data['verbose'] = tr::get('error_saved');
            } else {
                $data['status'] = 'warning';
                $data['verbose'] = tr::get('partial_success_saved', [ implode(', ', $ok), implode(', ', $error) ]);
            }
        } catch (\Exception $e) {
            $data['status'] = 'error';
            $data['verbose'] = tr::get('error_saved');
            $this->log->error($e);
        }

        echo json_encode($data);
    }



    public function erase()
    {
        if (!utils::canUser('edit')) {
            $data = array('status' => 'error', 'text'=> utils::alert_div('not_enough_privilege'));
            echo json_encode($data);
            return;
        }
        if (is_array($this->request['id'])) {
            foreach ($this->request['id'] as $id) {
                try {
                    $record = new Record($this->get['tb'], $id, $this->db);

                    $record->delete();

                    $ok[] = true;
                } catch (\Exception $e) {
                    $this->log->error($e);
                    $error[] = true;
                }
            }

            if (count($this->request['id']) == count($error)) {
                $data = array('status' => 'error', 'text' => tr::get('no_record_deleted'));
            } elseif (count($this->request['id']) == count($ok)) {
                $data = array('status' => 'success', 'text' => tr::get('all_record_deleted'));
            } else {
                $data = array('status' => 'warning', 'text' => tr::get('partially_deleted_with_count', [ count($ok), $count($error) ] ) );
            }
        } else {
            $data = array('status' => 'error', 'text' => tr::get('no_id_provided') );
        }

        echo json_encode($data);
    }


    public function show()
    {
        if (!$this->request['tb']) {
            throw new \Exception(tr::get('tb_missing'));
        }

        // user must have enough privileges
        if (!utils::canUser('read')) {
            utils::alert_div('not_enough_privilege', true);
            return;
        }
        // a record id must be provided in edit & read & preview mode
        if ($this->request['a'] !== 'add_new' && !$this->request['id'] && !$this->request['id_field']) {
            throw new \Exception(tr::get('no_id_to_view'));
        }

        // no data are retrieved if context is add_new or multiple edit!
        if ($this->request['a'] === 'add_new' or ($this->request['a'] === 'edit' and count($this->request['id']) > 1)) {
            $id_arr = array('new');
        } elseif ($this->request['id_field']) {
            $id_arr = $this->request['id_field'];
            $flag_idfield = true;
        } else {
            $id_arr = $this->request['id'];
        }

        //Can not display more than 500 records!
        $total_records = count($id_arr);
        if ($total_records > 500) {
            echo '<div class="alert">' . tr::get('too_much_records', [ $total_records, '500'] ) . '</div>';
            return;
        }

        $step = 10;

        foreach ($id_arr as $index => $id) {
            $index = $index+1;

            if ($index > ($step)) {
                return;
            }
            if (($key = array_search($id, $id_arr)) !== false) {
                unset($id_arr[$key]);
            }
            if ($index === $total_records) {
                $continue_url = 'end';
            } elseif ($index === $step) {
                echo $continue_url = 'id[]=' . implode('&id[]=', $id_arr);
            }

            if ($id == 'new') {
                $id = false;
            }

            $record = new Record($this->request['tb'], ($flag_idfield ? false : $id), $this->db);

            if ($flag_idfield) {
                $record->setIdField($id);
            }

            if ($this->request['a'] == 'edit' &&
                    (!utils::canUser('edit', $record->getCore('creator')) || (count($this->request['id']) > 1 && !utils::canUser('multiple_edit')))) {
                echo '<h2>' . tr::get('not_enough_privilege') . '</h2>';
                continue;
            }

            if ($this->request['a'] == 'add_new' && !utils::canUser('add_new')) {
                echo '<h2>' . tr::get('not_enough_privilege') . '</h2>';
                continue;
            }

            $tmpl = new ParseTmpl($this->request['a'], $record, $this->log);

            $this->render('record', 'show', array(
                    'action' => $this->request['a'],
                    'html' => $tmpl->parseAll(),
                    'multiple_id' => (count((array)$this->request['id']) > 1) ? tr::get('multiple_edit_alert', [ count($this->request['id']), implode('; id: ', $this->request['id']) ] ) : false,
                    'tb' => $this->request['tb'],
                    'id_url' => is_array($this->request['id']) ? 'id[]=' . implode('&id[]=', $this->request['id']) : false,
                    'totalRecords' => $total_records,
                    'id' => $flag_idfield ? $record->getCore('id') : $id,
                    'can_edit' => (utils::canUser('edit', $record->getCore('creator')) || (count($this->request['id']) > 1 && utils::canUser('multiple_edit'))),
                    'can_erase' => utils::canUser('edit', $record->getCore('creator')),
                    'continue_url' => $continue_url,
                    'virtual_keyboard' => $this->cfg->get('main.virtual_keyboard')
            ));
        }
    }

    public function showResults()
    {
        if (!utils::canUser('read')) {
            echo utils::message(tr::get('not_enough_privilege'), 'error', 1);
            return;
        }

        if (!$this->request['tb']) {
            throw new \Exception(tr::get('tb_missing'));
        }

        $queryObj = new QueryFromRequest($this->db, $this->request, true);

        $count = $this->request['total'] ?: $queryObj->getTotal();

        if ($count === 0) {
            $noResult = true;
        }

        list($qq, $vv) = $queryObj->getQuery(true);
        $encoded_query_obj = \SQL\SafeQuery::encode($qq, $vv);

        $this->render('record', 'result', [
            // string, table name
            'tb' => $this->request['tb'],
            // string, total of records found
            'records_found' => ($noResult ? tr::get('no_record_found') : tr::get('x_record_found', [$count])),
            // boolean, can current user add new records?
            'can_user_add' => utils::canUser('add_new'),
            // boolean, can current user read this record?
            'can_user_read' => utils::canUser('read'),
            // boolean, can current user edit this records?
            'can_user_edit' => utils::canUser('edit'),
            
            'encoded_query_obj' => $encoded_query_obj,
            // string, \SQL\SafeQuery encoded query & values, to be used for bookmarking, export, matrix, charts, geoface
            'encoded_where_obj' => $queryObj->getWhereAndValues(),
            // boolean, if no records are found, set to true: no table of results will be output in template
            'noResult' => $noResult,
            // boolean, if true double click on records is not allowed
            'noDblClick' => $this->request['noDblClick'],
            // boolean, if table has or not activated RS plugin
            'hasRS' => $this->cfg->get("tables.{$this->request['tb']}.rs"),
            // boolean, if true option buttons will not be shown in template
            'noOpts' => $this->request['noOpts'],
            // array, list of preview columns, to be used for datatable
            'col_names' => $queryObj->getFields(),
            // int, Total numer of records found, to be used for datatable
            'iTotalRecords' => $count,
            // string, current system language, to be used for datatable
            'lang' => pref::getLang(),
            // boolean: if true infinite scroll of databatables will be activated
            'infinte_scroll' => pref::get('infinite_scroll'),
            // boolean: if true only one records can be selected
            'select_one' => $this->request['select_one'],
            // boolean, if true id field will be available in datatables, but hidden
            'hideId' => ($this->cfg->get("tables.{$this->request['tb']}.fields.id.hide") == 1)
        ]);
    }

    /**
     * http://datatables.net/usage/server-side
     * 	REQUEST
     * 		obj_encoded
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
        try {
            $this->request['type'] = 'obj_encoded';

            $qObj = new QueryFromRequest($this->db, $this->request, true);

            $response['sEcho'] = intval($this->request['sEcho']);
            $response['query_arrived'] = $qObj->getQuery();

            $response['iTotalRecords'] = $response['iTotalDisplayRecords'] = isset($this->request['iTotalRecords']) ? $this->request['iTotalRecords'] : $qObj->getTotal();

            if (isset($this->request['iDisplayStart']) && $this->request['iDisplayLength'] != '-1') {
                $qObj->setLimit(intval($this->request['iDisplayStart']), $this->request['iDisplayLength']);
            }

            if (isset($this->request['iSortCol_0'])) {
                $fields = array_keys($qObj->getFields());
                $qObj->setOrder($fields[$this->request['iSortCol_0']], ($this->request['sSortDir_0']==='asc' ? 'asc' : 'desc'));
            }

            if ($this->request['sSearch']) {
                $qObj->setSubQuery($this->request['sSearch']);
                $response['iTotalDisplayRecords'] = $qObj->getTotal();
            }

            $response['query_executed'] = $qObj->getQuery();

            $response['aaData'] = $qObj->getResults();

            foreach ($response['aaData'] as $id => &$row) {
                $response['aaData'][$id]['DT_RowId'] = $row['id'];
            }

        } catch (\Throwable $th) {
            $this->log->error($th);
            $response = [];
        }

        echo json_encode($response);
    }



    public function check_duplicates()
    {
        try {
            if (!$this->request['fld'] or !$this->request['val']) {
                throw new \Exception('Field name and value are required');
            }
            if (preg_match('/core\[/', $this->request['fld'])) {
                $arr = explode('][', preg_replace('/core\[(.+)\]/', '$1', $this->request['fld']));

                $query = 'SELECT count(*) as tot FROM ' . $arr[0] . ' WHERE ' . $arr[1] . ' = :' . $arr[1];

                $res = $this->db->query($query, [":{$arr[1]}" => $this->request['val']], 'read');

                if ($res[0]['tot'] > 0) {
                    echo 'error';
                }
            }
        } catch (\Exception $e) {
            echo 'error';
            $this->log->error($e);
        }
    }
}
