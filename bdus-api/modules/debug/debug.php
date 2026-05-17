<?php
/**
 * @copyright 2007-2022 Julian Bogdani
 * @license AGPL-3.0; see LICENSE
 * @since			Aug 11, 2012
 */

class debug_ctrl extends Controller
{

  // ──────────────────────────────────────────────────────────────────────────
  // Vue v5 API methods
  // ──────────────────────────────────────────────────────────────────────────

  /**
   * Returns paginated log entries, newest first.
   *
   * GET ?obj=debug_ctrl&method=getLogs
   *     &page=1&per_page=50&level=400&search=STRING
   *
   * Response:
   * {
   *   total: int,
   *   data: [{ id, channel, level, level_name, message, time }, ...]
   * }
   */
  public function getLogs(): void
  {
    $tb      = $this->prefix . 'log';
    $page    = max(1,   (int)($this->get['page']     ?? 1));
    $perPage = min(200, max(1, (int)($this->get['per_page'] ?? 50)));
    $level   = (int)($this->get['level'] ?? 0);
    $search  = trim($this->get['search'] ?? '');

    $where  = '1=1';
    $values = [];

    if ($level > 0) {
      $where   .= ' AND level = ?';
      $values[] = $level;
    }
    if ($search !== '') {
      $where   .= ' AND message LIKE ?';
      $values[] = "%{$search}%";
    }

    try {
      $total  = (int)($this->db->query(
        "SELECT count(id) as tot FROM {$tb} WHERE {$where}",
        $values
      )[0]['tot'] ?? 0);

      $offset = ($page - 1) * $perPage;
      $rows   = $this->db->query(
        "SELECT * FROM {$tb} WHERE {$where} ORDER BY id DESC LIMIT ? OFFSET ?",
        array_merge($values, [$perPage, $offset])
      );

      $levelNames = [
        100 => 'DEBUG',     200 => 'INFO',      250 => 'NOTICE',
        300 => 'WARNING',   400 => 'ERROR',      500 => 'CRITICAL',
        550 => 'ALERT',     600 => 'EMERGENCY',
      ];

      foreach ($rows as &$row) {
        $row['level_name'] = $levelNames[$row['level']] ?? (string)$row['level'];
        $date = new \DateTime();
        $date->setTimestamp((int)$row['time']);
        $row['time'] = $date->format('Y-m-d H:i:s');
      }

      $this->returnJson(['total' => $total, 'data' => $rows ?? []]);

    } catch (\Throwable $e) {
      $this->log->error($e);
      $this->returnJson(['status' => 'error', 'code' => 'db_error', 'detail' => $e->getMessage()]);
    }
  }

  /**
   * Deletes log entries older than N days.
   *
   * POST { days: int }   — days must be >= 1
   *
   * Response: { status, code, deleted: int, days: int }
   */
  public function purgeLogs(): void
  {
    $days   = max(1, (int)($this->post['days'] ?? 30));
    $cutoff = time() - ($days * 86400);
    $tb     = $this->prefix . 'log';

    try {
      $deleted = (int)$this->db->query(
        "DELETE FROM {$tb} WHERE time < ?",
        [$cutoff],
        'affected'
      );
      $this->returnJson([
        'status'  => 'success',
        'code'    => 'log_purge_success',
        'deleted' => $deleted,
        'days'    => $days,
      ]);
    } catch (\Throwable $e) {
      $this->log->error($e);
      $this->returnJson(['status' => 'error', 'code' => 'db_error', 'detail' => $e->getMessage()]);
    }
  }

}
