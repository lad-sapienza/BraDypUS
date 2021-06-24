<?php

/**
 * @copyright 2007-2021 Julian Bogdani
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
      "SELECT COUNT(*) as tot FROM information_schema.TABLES WHERE TABLE_NAME = ?",
      [$tb]
    );

    return $res && !empty($res) && $res[0]['tot'] > 0;
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
    $res = $this->db->query("SHOW TABLES");

    $ret = [];
    foreach ($res as $row) {
      array_push($ret, array_values($row)[0]);
    }
    return $ret;
  }
}
