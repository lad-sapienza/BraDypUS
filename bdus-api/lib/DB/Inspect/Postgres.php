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
class Postgres implements InspectInterface
{
  private $db;

  public function __construct(DBInterface $db)
  {
    $this->db = $db;
  }

  public function tableExists(string $tb): bool
  {
    $res = $this->db->query(
      "SELECT COUNT(*) as tot FROM information_schema.tables WHERE table_name = ? AND table_schema = current_schema()",
      [$tb]
    );

    return $res && !empty($res) && $res[0]['tot'] > 0;
  }

  public function isView(string $tb): bool
  {
    $res = $this->db->query(
      "SELECT COUNT(*) as tot FROM information_schema.tables WHERE table_name = ? AND table_schema = current_schema() AND table_type = 'VIEW'",
      [$tb]
    );

    return $res && !empty($res) && $res[0]['tot'] > 0;
  }

  public function foreignKeys(string $tb): array
  {
    $rows = $this->db->query(
      "SELECT con.conname AS cname,
              att.attname AS from_col,
              rcl.relname AS to_tb,
              ratt.attname AS to_col,
              con.confdeltype AS del,
              con.confupdtype AS upd,
              cardinality(con.conkey) AS ncols
       FROM pg_constraint con
       JOIN pg_class cl   ON cl.oid  = con.conrelid
       JOIN pg_namespace ns ON ns.oid = cl.relnamespace
       JOIN pg_class rcl  ON rcl.oid = con.confrelid
       JOIN pg_attribute att  ON att.attrelid  = con.conrelid  AND att.attnum  = con.conkey[1]
       JOIN pg_attribute ratt ON ratt.attrelid = con.confrelid AND ratt.attnum = con.confkey[1]
       WHERE con.contype = 'f' AND cl.relname = ? AND ns.nspname = current_schema()",
      [$tb]
    ) ?: [];

    $rule = ['a' => 'NO ACTION', 'r' => 'RESTRICT', 'c' => 'CASCADE', 'n' => 'SET NULL', 'd' => 'SET DEFAULT'];
    $ret = [];
    foreach ($rows as $r) {
      if ((int) $r['ncols'] !== 1) {
        continue;
      }
      $ret[] = [
        'from_col'  => $r['from_col'],
        'to_tb'     => $r['to_tb'],
        'to_col'    => $r['to_col'],
        'on_delete' => $rule[$r['del']] ?? 'NO ACTION',
        'on_update' => $rule[$r['upd']] ?? 'NO ACTION',
      ];
    }
    return $ret;
  }

  public function listObjects(): array
  {
    $objs = $this->db->query(
      "SELECT table_name AS name, table_type AS ttype
       FROM information_schema.tables
       WHERE table_schema = current_schema()
         AND table_type IN ('BASE TABLE','VIEW')
         AND table_name NOT LIKE 'bdus\_%'"
    ) ?: [];

    $cols = $this->db->query(
      "SELECT table_name AS tb, column_name AS fld, data_type AS type
       FROM information_schema.columns
       WHERE table_schema = current_schema()
       ORDER BY table_name, ordinal_position"
    ) ?: [];
    $byTb = [];
    foreach ($cols as $c) {
      $byTb[$c['tb']][] = ['fld' => $c['fld'], 'type' => $c['type'], 'pk' => false];
    }

    $pks = $this->db->query(
      "SELECT kcu.table_name AS tb, kcu.column_name AS fld
       FROM information_schema.table_constraints tc
       JOIN information_schema.key_column_usage kcu
         ON kcu.constraint_name = tc.constraint_name AND kcu.table_schema = tc.table_schema
       WHERE tc.constraint_type = 'PRIMARY KEY' AND tc.table_schema = current_schema()"
    ) ?: [];
    foreach ($pks as $p) {
      foreach ($byTb[$p['tb']] ?? [] as $i => $c) {
        if ($c['fld'] === $p['fld']) {
          $byTb[$p['tb']][$i]['pk'] = true;
        }
      }
    }

    $updatable = [];
    foreach ($this->db->query(
      "SELECT table_name AS name, is_updatable AS upd, is_insertable_into AS ins
       FROM information_schema.views WHERE table_schema = current_schema()"
    ) ?: [] as $v) {
      $updatable[$v['name']] = ($v['upd'] === 'YES' || $v['ins'] === 'YES');
    }
    $instead = [];
    foreach ($this->db->query(
      "SELECT DISTINCT event_object_table AS name FROM information_schema.triggers
       WHERE event_object_schema = current_schema() AND action_timing = 'INSTEAD OF'"
    ) ?: [] as $t) {
      $instead[$t['name']] = true;
    }

    $ret = [];
    foreach ($objs as $o) {
      $isView = $o['ttype'] === 'VIEW';
      $sql = null;
      $writable = null;
      if ($isView) {
        $d = $this->db->query(
          "SELECT pg_get_viewdef((quote_ident(current_schema()) || '.' || quote_ident(?))::regclass, true) AS def",
          [$o['name']]
        );
        $sql = $d[0]['def'] ?? null;
        $writable = isset($instead[$o['name']]) ? 'trigger'
          : (($updatable[$o['name']] ?? false) ? 'probe' : 'none');
      }
      $ret[] = [
        'name'          => $o['name'],
        'kind'          => $isView ? 'view' : 'table',
        'columns'       => $byTb[$o['name']] ?? [],
        'sql'           => $sql,
        'view_writable' => $writable,
      ];
    }
    return $ret;
  }

  public function tableColumns(string $tb): array
  {
    $ret = [];

    $res = $this->db->query("SELECT * FROM information_schema.columns WHERE table_name = ? AND table_schema = current_schema()", [$tb]);
    if (!$res || empty($res)) {
      throw new \Exception("Error on getting column list for table {$tb}");
    }

    foreach ($res as $row) {
      $ret[] = [
        'fld' => $row['column_name'],
        'type' => $row['data_type']
      ];
    }

    return $ret;
  }

  public function getAllTables(): array
  {
    $res = $this->db->query("SELECT tablename FROM pg_catalog.pg_tables WHERE schemaname != 'pg_catalog' AND  schemaname != 'information_schema'");

    $ret = [];
    foreach ($res as $row) {
      array_push($ret, $row['tablename']);
    }
    return $ret;
  }
}
