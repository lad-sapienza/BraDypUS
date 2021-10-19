<?php

/**
 * @copyright 2007-2021 Julian Bogdani
 * @license AGPL-3.0; see LICENSE
 * @since			Jan 10, 2013
 */

use \Record\Read;
use Template\Template;

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
            $record = new Record($this->get['tb'], $id, $this->db, $this->cfg);

            if (is_array($this->post['core'])) {
              $record->setCore($this->post['core']);
            }

            if (is_array($this->post['plg'])) {
              foreach ($this->post['plg'] as $plg_name => $plg_data) {
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
          } catch (\Throwable $e) {
            $error[$id] = true;
            $this->log->error($e);
          }
        }
      }

      if (count($ok) == count($this->request['id'])) {
        $data['status'] = 'success';
        $data['verbose'] = \tr::get('success_saved');
        $inserted_id ? $data['inserted_id'] = $inserted_id : '';
      } elseif (count($error) == count($this->request['id'])) {
        $data['status'] = 'error';
        $data['verbose'] = \tr::get('error_saved');
      } else {
        $data['status'] = 'warning';
        $data['verbose'] = \tr::get('partial_success_saved', [implode(', ', $ok), implode(', ', $error)]);
      }
    } catch (\Throwable $e) {
      $data['status'] = 'error';
      $data['verbose'] = \tr::get('error_saved');
      $this->log->error($e);
    }

    echo json_encode($data);
  }



  public function erase()
  {
    if (!\utils::canUser('edit')) {
      $data = [
        'status' => 'error', 
        'text' => '<div class="text-danger">'
          . '<strong>' . \tr::get('attention') . ':</strong> ' . \tr::get('not_enough_privilege') . '</p>'
          . '</div>'
      ];
      echo json_encode($data);
      return;
    }
    if (is_array($this->request['id'])) {
      $error = [];
      foreach ($this->request['id'] as $id) {
        try {
          $record = new Record($this->get['tb'], $id, $this->db, $this->cfg);

          $record->delete();

          $ok[] = true;
        } catch (\Throwable $e) {
          $this->log->error($e);
          $error[] = true;
        }
      }

      if (count($this->request['id']) ===  count($error)) {
        $data = array('status' => 'error', 'text' => \tr::get('no_record_deleted'));
      } elseif (count($this->request['id']) == count($ok)) {
        $data = array('status' => 'success', 'text' => \tr::get('all_record_deleted'));
      } else {
        $data = array('status' => 'warning', 'text' => \tr::get('partially_deleted_with_count', [count($ok), count($error)]));
      }
    } else {
      $data = array('status' => 'error', 'text' => \tr::get('no_id_provided'));
    }

    echo json_encode($data);
  }


  public function show()
  {
    $tb = $this->request['tb'];
    $context = $this->request['a'];
    $id = $this->request['id'];
    $id_field = $this->request['id_field'];

    if (!$tb) {
      throw new \Exception(\tr::get('tb_missing'));
    }

    // user must have enough privileges
    if (!\utils::canUser('read')) {
      echo '<div class="text-danger">'
        . '<strong>' . \tr::get('attention') . ':</strong> ' . \tr::get('not_enough_privilege') . '</p>'
        . '</div>';
      return;
    }
    // a record id must be provided in edit & read & preview mode
    if ($context !== 'add_new' && !$id && !$id_field) {
      throw new \Exception(\tr::get('no_id_to_view'));
    }

    // no data are retrieved if context is add_new or multiple edit!
    if ($context === 'add_new' || ($context === 'edit' and count($id) > 1)) {
      $id_arr = ['new'];
      $flag_idfield = false;
    } elseif ($id_field) {
      $id_arr = $id_field;
      $flag_idfield = true;
    } else {
      $id_arr = $id;
      $flag_idfield = false;
    }


    //Can not display more than 100 records!
    $total_records = count($id_arr);
    if ($total_records > 100) {
      echo '<div class="alert">' . \tr::get('too_much_records', [$total_records, '100']) . '</div>';
      return;
    }

    $step = 10;

    foreach ($id_arr as $index => $one_id) {
      $index = $index + 1;

      if ($index > ($step)) {
        return;
      }
      if (($key = array_search($one_id, $id_arr)) !== false) {
        unset($id_arr[$key]);
      }
      if ($index === $total_records) {
        $continue_url = 'end';
      } elseif ($index === $step) {
        echo $continue_url = 'id[]=' . implode('&id[]=', $id_arr);
      }

      if ($one_id === 'new') {
        $one_id = null;
      }

      if ($flag_idfield) {
        $readRecord = new Read(null, $one_id, $tb, $this->db, $this->cfg);
      } else {
        $readRecord = new Read($one_id, null, $tb, $this->db, $this->cfg);
      }

      if (
        $context === 'edit' &&
        (!\utils::canUser('edit', $readRecord->getCore('creator', true)) || (count($id) > 1 && !\utils::canUser('multiple_edit')))
      ) {
        echo '<h2>' . \tr::get('not_enough_privilege') . '</h2>';
        continue;
      }

      if ($context === 'add_new' && !\utils::canUser('add_new')) {
        echo '<h2>' . \tr::get('not_enough_privilege') . '</h2>';
        continue;
      }

      $fieldObj = new Template($context, $readRecord, $this->db, $this->cfg);

      // get template
      $template_file = $this->getTemplate($tb, $context);

      if ($template_file) {
        $html = $this->compileTmpl(PROJ_DIR . 'templates/', $template_file, ['print' => $fieldObj]);
      } else {
        $html = $fieldObj->showall();
      }

      $this->render('record', 'show', [
        'action' => $context,
        'html' => $html,
        'multiple_id' => (count((array)$id) > 1) ? \tr::get('multiple_edit_alert', [count($id), implode('; id: ', $id)]) : false,
        'tb' => $tb,
        'id_url' => is_array($id) ? 'id[]=' . implode('&id[]=', $id) : false,
        'totalRecords' => $total_records,
        'id' => $flag_idfield ? $readRecord->getCore('id', true) : $one_id,
        'can_edit' => (\utils::canUser('edit', $readRecord->getCore('creator', true)) || (count($id) > 1 && \utils::canUser('multiple_edit'))),
        'can_erase' => \utils::canUser('edit', $readRecord->getCore('creator', true)),
        'continue_url' => $continue_url,
        'virtual_keyboard' => $this->cfg->get('main.virtual_keyboard')
      ]);
    }
  }

  private function getTemplate(string $tb, string $context)
  {
    $stripped_tb = str_replace($this->prefix, '', $tb);

    if ($context === 'add_new') {
      $context = 'edit';
    }
    $paths = [
      // preference saved template
      \pref::getTmpl($tb, $context),

      // config, context-bound, template
      $this->cfg->get("tables.{$tb}.tmpl_{$context}"),

      // default, context-bound template: {tb_name}_{context}.twig eg. siti_edit.twig
      $stripped_tb . '_' . $context . '.twig',

      // default, context indipendent template
      $stripped_tb . '.twig'
    ];

    $tmpl = false;

    foreach ($paths as $path) {
      if ($path && file_exists(PROJ_DIR . 'templates/' . $path) && !$tmpl) {
        $tmpl = $path;
      }
    }
    return $tmpl;
  }

  public function showResults()
  {
    if (!\utils::canUser('read')) {
      echo \utils::message(\tr::get('not_enough_privilege'), 'error', true);
      return;
    }

    if (!$this->request['tb']) {
      throw new \Exception(\tr::get('tb_missing'));
    }

    $queryObj = new QueryFromRequest($this->db, $this->cfg, $this->request, true);

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
      'records_found' => ($noResult ? \tr::get('no_record_found') : \tr::get('x_record_found', [$count])),
      // boolean, can current user add new records?
      'can_user_add' => \utils::canUser('add_new'),
      // boolean, can current user read this record?
      'can_user_read' => \utils::canUser('read'),
      // boolean, can current user edit this records?
      'can_user_edit' => \utils::canUser('edit'),

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
      'lang' => \pref::getLang(),
      // boolean: if true infinite scrolling of databatables will be activated
      'infinte_scrolling' => \pref::get('infinite_scrolling'),
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

      $qObj = new QueryFromRequest($this->db, $this->cfg, $this->request, true);

      $response['sEcho'] = intval($this->request['sEcho']);
      $response['query_arrived'] = $qObj->getQuery();

      $response['iTotalRecords'] = $response['iTotalDisplayRecords'] = isset($this->request['iTotalRecords']) ? $this->request['iTotalRecords'] : $qObj->getTotal();

      if (isset($this->request['iDisplayStart']) && $this->request['iDisplayLength'] != '-1') {
        $qObj->setLimit(intval($this->request['iDisplayStart']), $this->request['iDisplayLength']);
      }

      if (isset($this->request['iSortCol_0'])) {
        $fields = array_keys($qObj->getFields());
        $qObj->setOrder($fields[$this->request['iSortCol_0']], ($this->request['sSortDir_0'] === 'asc' ? 'asc' : 'desc'));
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
}
