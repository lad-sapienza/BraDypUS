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

  /** Options returned by default when a client asks for a page (see resolvePage()). */
  public const DEFAULT_LIMIT = 100;
  /** Upper bound of a page. */
  public const MAX_LIMIT = 500;

  /**
   * Resolves select options for a field from its configured source: all of them,
   * with no limit (for callers that need the complete list, such as the MCP tools).
   */
  public function resolve(string $tb, string $fld): array
  {
    return $this->resolvePage($tb, $fld)['options'];
  }

  /**
   * Resolves the options of a field, at most $limit of them.
   *
   * Only the sources that grow with the data are limited: the distinct values of
   * a column (`get_values_from_tb`: the most used first) and the records of a
   * lookup table (`id_from_tb`: by label). Vocabularies and static lists are
   * configuration, so they are always returned whole.
   *
   * When the list is complete the result is exactly the unlimited one; when it
   * is cut, `truncated` is true and $q (a "contains" search over the label, run
   * on the server) is how a client reaches the rest.
   *
   * @param string|null $q       search text (contains), over the label
   * @param int|null    $limit   page size; null = no limit
   * @param string|null $include ids (`;`-separated) of lookup records to return
   *                             even if outside the page: the ones already selected
   * @return array{options: array<int, array{value: mixed, label: mixed}>, truncated: bool}
   */
  public function resolvePage(string $tb, string $fld, ?string $q = null, ?int $limit = null, ?string $include = null): array
  {
    // Validate $tb against known config tables to prevent config traversal.
    // $fld is used only as a config key, not directly in SQL.
    $knownTables = array_keys($this->cfg->get('tables') ?? []);
    if (!in_array($tb, $knownTables, true)) {
      return ['options' => [], 'truncated' => false];
    }

    $allFields = $this->cfg->get("tables.{$tb}.fields.*") ?? [];
    $cfg = $allFields[$fld] ?? [];
    $q = $q === null ? '' : trim($q);

    if (!empty($cfg['get_values_from_tb'])) {
      [$refTb, $refFld] = array_pad(explode(':', $cfg['get_values_from_tb'], 2), 2, null);
      if (!\Auth\Authorization::can('read', table: $refTb)) {
        return ['options' => [], 'truncated' => false];
      }
      [$andReadable, $readableValues] = $this->andReadable($refTb);
      $alphabetical = "SELECT DISTINCT {$refFld} as v FROM {$refTb} WHERE {$refFld} IS NOT NULL AND {$refFld} != ''{$andReadable} ORDER BY {$refFld}";

      if ($limit === null && $q === '') {
        $rows = $this->db->query($alphabetical, $readableValues) ?: [];
        return ['options' => $this->valueOptions($rows), 'truncated' => false];
      }

      // The most used values first; one more than asked, to know if there are others.
      $text   = $this->textOf($refFld);
      $where  = "{$refFld} IS NOT NULL AND {$refFld} != ''{$andReadable}";
      $params = $readableValues;
      $order  = '';
      if ($q !== '') {
        [$like, $esc] = [$this->like(), $this->escapeLike($q)];
        $where   .= " AND {$text} {$like} ? ESCAPE '!'";
        $params[] = '%' . $esc . '%';
        $order    = "CASE WHEN {$text} {$like} ? ESCAPE '!' THEN 0 ELSE 1 END, ";
        $params[] = $esc . '%';
      }
      $cap  = $limit === null ? '' : ' LIMIT ' . ($limit + 1);
      $rows = $this->db->query(
        "SELECT {$refFld} as v, COUNT(*) as c FROM {$refTb} WHERE {$where} GROUP BY {$refFld} ORDER BY {$order}c DESC, {$refFld}{$cap}",
        $params
      ) ?: [];

      $truncated = $limit !== null && count($rows) > $limit;
      if ($truncated) {
        $rows = array_slice($rows, 0, $limit);
      } elseif ($q === '') {
        // Complete list: same order as ever (the database's alphabetical order).
        $rows = $this->db->query($alphabetical, $readableValues) ?: [];
      }
      return ['options' => $this->valueOptions($rows), 'truncated' => $truncated];
    }

    if (!empty($cfg['id_from_tb'])) {
      $refTb  = $cfg['id_from_tb'];
      if (!\Auth\Authorization::can('read', table: $refTb)) {
        return ['options' => [], 'truncated' => false];
      }
      $idFld  = $this->cfg->get("tables.{$refTb}.id_field") ?: 'id';
      [$andReadable, $readableValues] = $this->andReadable($refTb);

      $where  = "1=1{$andReadable}";
      $params = $readableValues;
      $order  = '';
      if ($q !== '') {
        $text = $this->textOf($idFld);
        [$like, $esc] = [$this->like(), $this->escapeLike($q)];
        $where   .= " AND {$text} {$like} ? ESCAPE '!'";
        $params[] = '%' . $esc . '%';
        $order    = "CASE WHEN {$text} {$like} ? ESCAPE '!' THEN 0 ELSE 1 END, ";
        $params[] = $esc . '%';
      }
      $cap  = $limit === null ? '' : ' LIMIT ' . ($limit + 1);
      $rows = $this->db->query(
        "SELECT id, {$idFld} as lbl FROM {$refTb} WHERE {$where} ORDER BY {$order}{$idFld}{$cap}",
        $params
      ) ?: [];

      $truncated = $limit !== null && count($rows) > $limit;
      if ($truncated) {
        $rows = array_slice($rows, 0, $limit);
      }

      $options = array_map(fn($r) => ['value' => $r['id'], 'label' => $r['lbl']], $rows);

      // Records already selected stay selectable (and show their label) even if
      // they fall outside the page.
      $wanted = array_values(array_filter(
        array_map('trim', explode(';', (string) $include)),
        fn($i) => $i !== '' && ctype_digit($i)
      ));
      $have = array_flip(array_map(fn($o) => (string) $o['value'], $options));
      $wanted = array_values(array_filter($wanted, fn($i) => !isset($have[$i])));
      if ($wanted) {
        $marks = implode(',', array_fill(0, count($wanted), '?'));
        $extra = $this->db->query(
          "SELECT id, {$idFld} as lbl FROM {$refTb} WHERE id IN ({$marks}){$andReadable} ORDER BY {$idFld}",
          array_merge(array_map('intval', $wanted), $readableValues)
        ) ?: [];
        foreach ($extra as $r) {
          $options[] = ['value' => $r['id'], 'label' => $r['lbl']];
        }
      }

      return ['options' => $options, 'truncated' => $truncated];
    }

    if (!empty($cfg['vocabulary_set'])) {
      $rows = $this->db->query(
        "SELECT def as v FROM bdus_vocabularies WHERE voc = ? ORDER BY sort",
        [$cfg['vocabulary_set']]
      ) ?: [];
      return ['options' => $this->valueOptions($rows), 'truncated' => false];
    }

    if (!empty($cfg['dic'])) {
      return [
        'options'   => array_map(fn($v) => ['value' => $v, 'label' => $v], (array)$cfg['dic']),
        'truncated' => false,
      ];
    }

    return ['options' => [], 'truncated' => false];
  }

  /** [{value, label}] where both are the `v` column of each row. */
  private function valueOptions(array $rows): array
  {
    return array_map(fn($r) => ['value' => $r['v'], 'label' => $r['v']], $rows);
  }

  /** The column as text, for LIKE. */
  private function textOf(string $col): string
  {
    return 'CAST(' . $col . ' AS ' . ($this->db->getEngine() === 'mysql' ? 'CHAR' : 'TEXT') . ')';
  }

  private function like(): string
  {
    return \SQL\Like::operator($this->db->getEngine());
  }

  /** Escapes LIKE wildcards, with `!` as the escape character. */
  private function escapeLike(string $q): string
  {
    return str_replace(['!', '%', '_'], ['!!', '!%', '!_'], $q);
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
