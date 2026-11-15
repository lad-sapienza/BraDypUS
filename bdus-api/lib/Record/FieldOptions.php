<?php

/**
 * @copyright 2007-2025 Julian Bogdani
 * @license AGPL-3.0; see LICENSE
 */

namespace Record;

use DB\DBInterface;
use Config\Config;
use Auth\Access;

/**
 * The values a field offers (select options): from another table's column,
 * from the records of a lookup table, from a vocabulary or from a static list.
 * Values coming from a table are limited to the rows the principal may read.
 */
class FieldOptions
{
  public function __construct(
    private readonly DBInterface $db,
    private readonly Config $cfg,
    private readonly Access $access,
  ) {}

  /**
   * Resolves select options for a field from its configured source.
   */
  public function resolve(string $tb, string $fld): array
  {
    // Validate $tb against known config tables to prevent config traversal.
    // $fld is used only as a config key, not directly in SQL.
    $knownTables = array_keys($this->cfg->get('tables') ?? []);
    if (!in_array($tb, $knownTables, true)) {
      return [];
    }

    $allFields = $this->cfg->get("tables.{$tb}.fields.*") ?? [];
    $cfg = $allFields[$fld] ?? [];

    if (!empty($cfg['get_values_from_tb'])) {
      [$refTb, $refFld] = array_pad(explode(':', $cfg['get_values_from_tb'], 2), 2, null);
      if (!\Auth\Authorization::can('read', table: $refTb)) {
        return [];
      }
      [$andReadable, $readableValues] = $this->andReadable($refTb);
      $rows = $this->db->query(
        "SELECT DISTINCT {$refFld} as v FROM {$refTb} WHERE {$refFld} IS NOT NULL AND {$refFld} != ''{$andReadable} ORDER BY {$refFld}",
        $readableValues
      ) ?: [];
      return array_map(fn($r) => ['value' => $r['v'], 'label' => $r['v']], $rows);
    }

    if (!empty($cfg['id_from_tb'])) {
      $refTb  = $cfg['id_from_tb'];
      if (!\Auth\Authorization::can('read', table: $refTb)) {
        return [];
      }
      $idFld  = $this->cfg->get("tables.{$refTb}.id_field") ?: 'id';
      [$andReadable, $readableValues] = $this->andReadable($refTb);
      $rows   = $this->db->query(
        "SELECT id, {$idFld} as lbl FROM {$refTb} WHERE 1=1{$andReadable} ORDER BY {$idFld}",
        $readableValues
      ) ?: [];
      return array_map(fn($r) => ['value' => $r['id'], 'label' => $r['lbl']], $rows);
    }

    if (!empty($cfg['vocabulary_set'])) {
      $rows = $this->db->query(
        "SELECT def as v FROM bdus_vocabularies WHERE voc = ? ORDER BY sort",
        [$cfg['vocabulary_set']]
      ) ?: [];
      return array_map(fn($r) => ['value' => $r['v'], 'label' => $r['v']], $rows);
    }

    if (!empty($cfg['dic'])) {
      return array_map(fn($v) => ['value' => $v, 'label' => $v], (array)$cfg['dic']);
    }

    return [];
  }

  /**
   * SQL to append (with AND) so a query over $tb only sees the rows the
   * principal may read: [' AND (cond)', values], or ['', []].
   *
   * @return array{0: string, 1: array}
   */
  private function andReadable(string $tb): array
  {
    [$cond, $values] = $this->access->rowCondition('read', $tb);
    return $cond === '1=1' ? ['', []] : [' AND (' . $cond . ')', $values];
  }
}
