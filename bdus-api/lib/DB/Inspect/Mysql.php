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
