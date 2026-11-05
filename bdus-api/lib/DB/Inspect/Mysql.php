<?php

/**
 * @copyright 2007-2022 Julian Bogdani
 * @license AGPL-3.0; see LICENSE
 */

namespace DB\Inspect;

use DB\DBInterface;

/**
 * Interface to interact with database structure
 */
class Mysql implements InspectInterface
{
  private $db;

  public function __construct(DBInterface $db)
  {
    $this->db = $db;
  }

  public function tableExists(string $tb): bool
  {
    $res = $this->db->query(
      "SELECT COUNT(*) as tot FROM information_schema.TABLES WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = ?",
      [$tb]
    );

    return $res && !empty($res) && $res[0]['tot'] > 0;
  }

  public function isView(string $tb): bool
  {
    $res = $this->db->query(
      "SELECT COUNT(*) as tot FROM information_schema.VIEWS WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = ?",
      [$tb]
    );

    return $res && !empty($res) && $res[0]['tot'] > 0;
  }

  public function foreignKeys(string $tb): array
  {
    $rows = $this->db->query(
      "SELECT k.CONSTRAINT_NAME AS cname, k.COLUMN_NAME AS from_col,
              k.REFERENCED_TABLE_NAME AS to_tb, k.REFERENCED_COLUMN_NAME AS to_col,
              rc.DELETE_RULE AS del, rc.UPDATE_RULE AS upd
       FROM information_schema.KEY_COLUMN_USAGE k
       JOIN information_schema.REFERENTIAL_CONSTRAINTS rc
         ON rc.CONSTRAINT_SCHEMA = k.TABLE_SCHEMA
        AND rc.CONSTRAINT_NAME = k.CONSTRAINT_NAME
        AND rc.TABLE_NAME = k.TABLE_NAME
       WHERE k.TABLE_SCHEMA = DATABASE() AND k.TABLE_NAME = ?
         AND k.REFERENCED_TABLE_NAME IS NOT NULL",
      [$tb]
    ) ?: [];

    // Composite keys: several rows with the same constraint name — skip them.
    $perName = [];
    foreach ($rows as $r) {
      $perName[$r['cname']] = ($perName[$r['cname']] ?? 0) + 1;
    }

    $ret = [];
    foreach ($rows as $r) {
      if ($perName[$r['cname']] !== 1) {
        continue;
      }
      $ret[] = [
        'from_col'  => $r['from_col'],
        'to_tb'     => $r['to_tb'],
        'to_col'    => $r['to_col'],
        'on_delete' => strtoupper($r['del']),
        'on_update' => strtoupper($r['upd']),
      ];
    }
    return $ret;
  }

  public function listObjects(): array
  {
    $objs = $this->db->query(
      "SELECT TABLE_NAME AS name, TABLE_TYPE AS ttype
       FROM information_schema.TABLES
       WHERE TABLE_SCHEMA = DATABASE()
         AND TABLE_TYPE IN ('BASE TABLE','VIEW')
         AND TABLE_NAME NOT LIKE 'bdus\\_%'"
    ) ?: [];

    $byTb = [];
    foreach ($this->db->query(
      "SELECT TABLE_NAME AS tb, COLUMN_NAME AS fld, DATA_TYPE AS type, COLUMN_KEY AS ckey
       FROM information_schema.COLUMNS
       WHERE TABLE_SCHEMA = DATABASE()
       ORDER BY TABLE_NAME, ORDINAL_POSITION"
    ) ?: [] as $c) {
      $byTb[$c['tb']][] = ['fld' => $c['fld'], 'type' => $c['type'], 'pk' => $c['ckey'] === 'PRI'];
    }

    $views = [];
    foreach ($this->db->query(
      "SELECT TABLE_NAME AS name, VIEW_DEFINITION AS def, IS_UPDATABLE AS upd
       FROM information_schema.VIEWS WHERE TABLE_SCHEMA = DATABASE()"
    ) ?: [] as $v) {
      $views[$v['name']] = $v;
    }

    $ret = [];
    foreach ($objs as $o) {
      $isView = $o['ttype'] === 'VIEW';
      $ret[] = [
        'name'          => $o['name'],
        'kind'          => $isView ? 'view' : 'table',
        'columns'       => $byTb[$o['name']] ?? [],
        'sql'           => $isView ? ($views[$o['name']]['def'] ?? null) : null,
        // MySQL has no INSTEAD OF triggers: a view is writable or it is not.
        'view_writable' => $isView ? (($views[$o['name']]['upd'] ?? 'NO') === 'YES' ? 'probe' : 'none') : null,
      ];
    }
    return $ret;
  }

  public function tableColumns(string $tb): array
  {
    $ret = [];

    $res = $this->db->query("DESCRIBE {$tb}");
    if (!$res || empty($res)) {
      throw new \Exception("Error on getting column list for table {$tb}");
    }

    foreach ($res as $row) {
      $ret[] = [
        'fld' => $row['Field'],
        'type' => $row['Type']
      ];
    }
    return $ret;
  }

  public function getAllTables(): array
  {
    $res = $this->db->query("SHOW FULL TABLES WHERE Table_type = 'BASE TABLE'");

    $ret = [];
    foreach ($res as $row) {
      array_push($ret, array_values($row)[0]);
    }
    return $ret;
  }
}
