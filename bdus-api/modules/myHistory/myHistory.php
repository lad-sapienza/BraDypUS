<?php
/**
 * @copyright 2007-2022 Julian Bogdani
 * @license AGPL-3.0; see LICENSE
 */

class myHistory_ctrl extends Controller
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
    if (!\utils::canUser('read')) {
      $this->returnJson(['status' => 'error', 'code' => 'not_enough_privilege']);
      return;
    }

    $vTb     = $this->prefix . 'versions';
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

      $this->returnJson(['total' => $total, 'data' => $rows]);

    } catch (\Throwable $e) {
      $this->log->error($e);
      $this->returnJson(['status' => 'error', 'code' => 'db_error', 'detail' => $e->getMessage()]);
    }
  }

  // ── Legacy v4 methods ─────────────────────────────────────────────────────

  /** @deprecated v5 — replaced by getHistory() consumed by Vue HistoryView */
  public function sql2json()
  {
    $params = $this->post;
    echo \utils::jsonForTabletop($this->db, $this->prefix . 'versions', $params);
  }

  /** @deprecated v5 — replaced by Vue HistoryView (/history route) */
  public function show_all()
  {
    $fields = ['id', 'user', 'time', 'tb', 'rowid', 'content', 'editsql', 'editvalues'];
    $this->render('myHistory', 'read', [
      'th_fields'  => '<th>' . implode('</th><th>', $fields) . '</th>',
      'm_data'     => '{"mData":"' . implode('"},{"mData":"', $fields) . '"}',
      'ajaxSource' => "./?obj=myHistory_ctrl&method=sql2json",
    ]);
  }
}
