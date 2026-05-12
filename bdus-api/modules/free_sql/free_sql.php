<?php
/**
 * @copyright 2007-2022 Julian Bogdani
 * @license AGPL-3.0; see LICENSE
 */

class free_sql_ctrl extends Controller
{
  /** @deprecated v5 — SQL import covered by backup_ctrl::restoreBackup() */
  public function import()
  {
    $filename = $this->get['filename'];
    $start = $this->get['start'];
    $offset = $this->get['offset'];
    $totalqueries = $this->get['totalqueries'];

    try {
      $bigRestore = new bigRestore($this->db);
      $bigRestore->runImport($filename, $start, $offset, $totalqueries);
      echo $bigRestore->getResponse(true);
    } catch (\Throwable $e) {
      $this->returnJson(['status' => 'error', 'text' => $e->getMessage()]);
    }
  }

  /** @deprecated v5 — raw SQL UI not ported; use docker exec or a DB client */
  public function input()
  {
    if (\utils::canUser('super_admin')) {
      echo '<textarea placeholder="Enter SQL code here"></textarea>';
    } else {
      echo \tr::get('not_enough_privilege');
    }
  }

  /** @deprecated v5 — raw SQL UI not ported; use docker exec or a DB client */
  public function run()
  {
    $sql = $this->post['sql'];
    try {
      $this->db->beginTransaction();
      $ret = $this->db->exec($sql);
      $this->db->commit();
      $this->response('ok_free_sql_run_affected', 'success', [$ret ?: 0]);
    } catch (\DB\DBException $e) {
      $this->log->error($e);
      $this->db->rollBack();
      $this->response('error_free_sql_run_msg', 'error', [$e->getMessage()]);
    }
  }
}
