<?php

namespace Bdus\Controllers;

/**
 * @copyright 2007-2022 Julian Bogdani
 * @license AGPL-3.0; see LICENSE
 */

class MyHistory extends \Bdus\Controller
{
  // ── v5 API ────────────────────────────────────────────────────────────────

  /**
   * Returns paginated edit history from the versions table.
   *
   * GET ?obj=myHistory_ctrl&method=getHistory
   *     &page=1&per_page=50&tb=TABLE&user=USER
   *
   * Response: { total: int, data: [{ id, user, time, tb, rowid, content, editsql, editvalues }] }
   */
  public function getHistory(): void
  {
    if (!\Auth\Authorization::canOnSomeTable('read')) {
      $this->returnJson(['status' => 'error', 'code' => 'not_enough_privilege']);
      return;
    }

    $vTb     = 'bdus_versions';
    $page    = max(1,   (int)($this->get['page']     ?? 1));
    $perPage = min(200, max(1, (int)($this->get['per_page'] ?? 50)));
    $tb      = trim($this->get['tb']   ?? '');
    $user    = trim($this->get['user'] ?? '');

    $where  = '1=1';
    $values = [];

    if ($tb !== '') {
      $where   .= ' AND tb = ?';
      $values[] = $tb;
    }
    if ($user !== '') {
      $where   .= ' AND userid LIKE ?';
      $values[] = "%{$user}%";
    }

    // Level Reader and above keep the whole history. A user at level Enter sees
    // only the edits of the records their groups let them read.
    $level = \Auth\CurrentUser::privilege();
    if ($level !== null && $level > \Auth\Authorization::READ) {
      $parts = [];
      foreach ($this->cfg->get('tables.*.name') ?: [] as $name) {
        if (!is_string($name) || preg_match('/^[A-Za-z0-9_]+$/', $name) !== 1
            || !\Auth\Authorization::can('read', table: $name)) {
          continue;
        }
        [$cond, $condValues] = $this->access()->rowCondition('read', $name);
        if ($cond === '1=1') {
          $parts[]  = 'tb = ?';
          $values[] = $name;
        } else {
          $parts[]  = "(tb = ? AND rowid IN (SELECT id FROM {$name} WHERE {$cond}))";
          $values   = array_merge($values, [$name], $condValues);
        }
      }
      $where .= $parts ? ' AND (' . implode(' OR ', $parts) . ')' : ' AND 1=0';
    }

    try {
      $total = (int)($this->db->query(
        "SELECT count(id) as tot FROM {$vTb} WHERE {$where}",
        $values
      )[0]['tot'] ?? 0);

      $offset = ($page - 1) * $perPage;
      $rows   = $this->db->query(
        "SELECT id, userid AS user, time, tb, rowid, content, editsql, editvalues
           FROM {$vTb}
          WHERE {$where}
          ORDER BY id DESC
          LIMIT ? OFFSET ?",
        array_merge($values, [$perPage, $offset])
      ) ?: [];

      foreach ($rows as &$row) {
        if ($row['time']) {
          $d = new \DateTime();
          $d->setTimestamp((int)$row['time']);
          $row['time'] = $d->format('Y-m-d H:i:s');
        }
      }

      $this->returnJson(["status" => "success", "total" => $total, "data" => $rows]);

    } catch (\Throwable $e) {
      $this->log->error($e);
      $this->returnJson(['status' => 'error', 'code' => 'db_error', 'detail' => $e->getMessage()]);
    }
  }

}
