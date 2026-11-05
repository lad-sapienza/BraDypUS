<?php

/**
 * @copyright 2007-2022 Julian Bogdani
 * @license AGPL-3.0; see LICENSE
 */

namespace DB\Inspect;

use DB\DBInterface;

/**
 * SQLite implementation of Inspect Interface
 */
class Sqlite implements InspectInterface
{
  private $db;

  public function __construct(DBInterface $db)
  {
    $this->db = $db;
  }

  public function tableExists(string $tb): bool
  {
    $res = $this->db->query(
      "SELECT count(name) as tot FROM sqlite_master WHERE type IN ('table','view') AND name = ?",
      [$tb]
    );

    return $res && !empty($res) && $res[0]['tot'] > 0;
  }

  public function isView(string $tb): bool
  {
    $res = $this->db->query(
      "SELECT count(name) as tot FROM sqlite_master WHERE type='view' AND name = ?",
      [$tb]
    );

    return $res && !empty($res) && $res[0]['tot'] > 0;
  }

  public function listObjects(): array
  {
    $rows = $this->db->query(
      "SELECT name, type, sql FROM sqlite_master
       WHERE type IN ('table','view')
         AND name NOT LIKE 'sqlite\_%' ESCAPE '\'
         AND name NOT LIKE 'bdus\_%' ESCAPE '\'"
    ) ?: [];

    // Virtual tables (FTS...) and their shadow tables are not user data.
    $virtual = [];
    foreach ($rows as $r) {
      if ($r['type'] === 'table' && stripos((string) $r['sql'], 'CREATE VIRTUAL TABLE') === 0) {
        $virtual[] = $r['name'];
      }
    }
    $isShadow = function (string $name) use ($virtual): bool {
      foreach ($virtual as $v) {
        if (preg_match('/^' . preg_quote($v, '/') . '_(data|idx|content|docsize|config)$/', $name)) {
          return true;
        }
      }
      return false;
    };

    $ret = [];
    foreach ($rows as $r) {
      if (in_array($r['name'], $virtual, true) || $isShadow($r['name'])) {
        continue;
      }
      $isView  = $r['type'] === 'view';
      $columns = [];
      foreach ($this->db->query("PRAGMA table_info('{$r['name']}')") ?: [] as $c) {
        $columns[] = ['fld' => $c['name'], 'type' => (string) $c['type'], 'pk' => (int) $c['pk'] > 0];
      }

      $writable = null;
      if ($isView) {
        $trg = $this->db->query(
          "SELECT COUNT(*) AS tot FROM sqlite_master
           WHERE type='trigger' AND tbl_name = ? AND UPPER(sql) LIKE '%INSTEAD OF%'",
          [$r['name']]
        );
        $writable = (!empty($trg) && $trg[0]['tot'] > 0) ? 'trigger' : 'none';
      }

      $ret[] = [
        'name'          => $r['name'],
        'kind'          => $isView ? 'view' : 'table',
        'columns'       => $columns,
        'sql'           => $isView ? $r['sql'] : null,
        'view_writable' => $writable,
      ];
    }
    return $ret;
  }

  public function tableColumns(string $tb): array
  {
    $ret = [];

    $res = $this->db->query("PRAGMA table_info('{$tb}')");
    if (!$res || empty($res)) {
      throw new \Exception("Error on getting column list for table {$tb}");
    }

    foreach ($res as $row) {
      $ret[] = [
        'fld' => $row['name'],
        'type' => $row['type']
      ];
    }

    return $ret;
  }

  public function getAllTables(): array
  {
    $res = $this->db->query("SELECT name FROM sqlite_master WHERE type ='table'");

    $ret = [];
    foreach ($res as $row) {
      array_push($ret, $row['name']);
    }
    return $ret;
  }
}
