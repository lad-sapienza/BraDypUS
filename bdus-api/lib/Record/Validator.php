<?php

/**
 * @copyright 2007-2025 Julian Bogdani
 * @license AGPL-3.0; see LICENSE
 */

namespace Record;

use DB\DBInterface;
use Config\Config;

/**
 * Server-side validation of a record payload (core fields and plugin rows)
 * against the table configuration: shape of the payload, required fields,
 * type checks, ranges, patterns, uniqueness and lookup targets. Never writes.
 *
 * Used by Record\Save before any write; it has no knowledge of the HTTP request.
 */
class Validator
{
  public function __construct(
    private readonly DBInterface $db,
    private readonly Config $cfg,
  ) {}

  /**
   * Shape of a saveRecord payload: what the client may name and with which
   * kind of values. Cleans $plgs in place (a new plugin row never carries its
   * own `id_link`: the server links it to the record being saved).
   *
   * @param  mixed $core
   * @param  mixed $plgs
   * @return array|null  an error envelope, or null when the shape is fine
   */
  public function payloadShapeErrors(string $tb, ?int $id, mixed &$core, mixed &$plgs): ?array
  {
    if (!is_array($core) || !is_array($plgs)) {
      return ['status' => 'error', 'code' => 'invalid_payload'];
    }
    if (!$id && $core === []) {
      return ['status' => 'error', 'code' => 'parameter_missing', 'detail' => 'core'];
    }

    $errors = [];
    $names  = fn(string $t): array => array_column($this->cfg->get("tables.{$t}.fields") ?: [], 'name');

    // `id` and `creator` are system fields the server sets itself (a client value is dropped).
    $coreNames = array_merge($names($tb), ['id', 'creator']);
    foreach ($core as $fld => $val) {
      if (!is_string($fld) || !in_array($fld, $coreNames, true)) {
        $errors[] = ['field' => (string) $fld, 'label' => (string) $fld, 'rule' => 'unknown_field'];
      } elseif (is_array($val) || is_object($val)) {
        $errors[] = ['field' => $fld, 'label' => $fld, 'rule' => 'invalid_type'];
      }
    }

    $plugins = $this->cfg->get("tables.{$tb}.plugin") ?: [];
    foreach ($plgs as $plgTb => $rows) {
      if (!is_string($plgTb) || !in_array($plgTb, $plugins, true) || !is_array($rows)) {
        $errors[] = ['field' => (string) $plgTb, 'label' => (string) $plgTb, 'rule' => 'unknown_plugin'];
        continue;
      }
      $plgNames = $names($plgTb);
      foreach ($rows as $i => $row) {
        if (!is_array($row) || !is_array($row['fields'] ?? [])) {
          $errors[] = ['field' => $plgTb, 'label' => $plgTb, 'rule' => 'invalid_type'];
          continue;
        }
        if (empty($row['id'])) {
          unset($plgs[$plgTb][$i]['fields']['id_link']);
        }
        foreach ($plgs[$plgTb][$i]['fields'] ?? [] as $fld => $val) {
          if (!is_string($fld) || !in_array($fld, $plgNames, true)) {
            $errors[] = ['field' => $plgTb . '.' . $fld, 'label' => $plgTb . '.' . $fld, 'rule' => 'unknown_field'];
          } elseif (is_array($val) || is_object($val)) {
            $errors[] = ['field' => $plgTb . '.' . $fld, 'label' => $fld, 'rule' => 'invalid_type'];
          }
        }
      }
    }

    return $errors ? ['status' => 'error', 'code' => 'validation_failed', 'errors' => $errors] : null;
  }

  /**
   * Validates core and plugin fields before saving.
   * Returns an array of error objects, empty if everything is valid.
   *
   * @param  string   $tb    Main table id
   * @param  int|null $id    Record id (null = INSERT); used for no_dupl to exclude self
   * @param  array    $core  Core field values { fieldName: value }
   * @param  array    $plgs  Plugin rows { plgTb: [ {_delete, fields: {fn: v}} ] }
   * @return array    [ { field, label, rule, ...extra }, ... ]
   */
  public function validatePayload(string $tb, ?int $id, array $core, array $plgs): array
  {
    $errors = [];

    // Build field-config map for core table
    $fieldMap = [];
    foreach ($this->cfg->get("tables.{$tb}.fields") ?: [] as $fld) {
      $fieldMap[$fld['name']] = $fld;
    }

    foreach ($core as $fldName => $value) {
      if (!isset($fieldMap[$fldName])) {
        continue;
      }
      $errors = array_merge(
        $errors,
        $this->validateFieldValue($fldName, $value, $fieldMap[$fldName], $tb, $id)
      );
    }

    // A new record must carry every required field: one that is left out of the
    // payload is as empty as one sent empty. (On update a field left out stays as it is.)
    if (!$id) {
      $errors = array_merge($errors, $this->missingRequired($fieldMap, $core));
    }

    // Plugin fields
    foreach ($plgs as $plgTb => $rows) {
      $plgMap = [];
      foreach ($this->cfg->get("tables.{$plgTb}.fields") ?: [] as $fld) {
        $plgMap[$fld['name']] = $fld;
      }
      foreach ($rows as $row) {
        if (!empty($row['_delete'])) {
          continue;
        }
        foreach ($row['fields'] ?? [] as $fldName => $value) {
          if (!isset($plgMap[$fldName])) {
            continue;
          }
          $errors = array_merge(
            $errors,
            $this->validateFieldValue($fldName, $value, $plgMap[$fldName], $plgTb, null)
          );
        }
        // A new plugin row is held to the same rule (never `id_link`: the server sets it).
        if (empty($row['id'])) {
          $errors = array_merge($errors, $this->missingRequired($plgMap, $row['fields'] ?? [], $plgTb));
        }
      }
    }

    return $errors;
  }

  /**
   * Required (`required` / `not_empty`) fields that a payload leaves out altogether.
   * System columns the server fills in itself are never asked of the client.
   *
   * @param  array<string,array> $fieldMap  field name => field config
   * @param  array               $values    the payload's field => value
   * @return array  error objects, like validateFieldValue()
   */
  private function missingRequired(array $fieldMap, array $values, string $prefix = ''): array
  {
    $errors = [];
    foreach ($fieldMap as $name => $cfg) {
      if (in_array($name, ['id', 'creator', 'id_link'], true) || array_key_exists($name, $values)) {
        continue;
      }
      $check = $cfg['check'] ?? [];
      $check = is_string($check) ? array_filter(array_map('trim', explode(' ', $check))) : (array) $check;
      if (in_array('required', $check, true) || in_array('not_empty', $check, true)) {
        $errors[] = ['field' => ($prefix !== '' ? $prefix . '.' : '') . $name, 'label' => $cfg['label'] ?? $name, 'rule' => 'required'];
      }
    }
    return $errors;
  }

  /**
   * Does record $value exist in the table a lookup field (`id_from_tb`) points to?
   */
  private function lookupExists(string $target, mixed $value): bool
  {
    if (!in_array($target, $this->cfg->get('tables.*.name') ?: [], true) || !is_scalar($value)) {
      return false;
    }
    if (!preg_match('/^\d+$/', (string) $value)) {
      return false;
    }
    return !empty($this->db->query("SELECT 1 FROM {$target} WHERE id = ? LIMIT 1", [(int) $value], 'read'));
  }

  /**
   * Validates a single field value against its config rules.
   * Returns an array of error objects (empty = valid).
   */
  public function validateFieldValue(
    string $fldName,
    mixed  $value,
    array  $cfg,
    string $tb,
    ?int   $excludeId
  ): array {
    $errors = [];
    $label  = $cfg['label'] ?? $fldName;
    $type   = $cfg['type']  ?? 'text';

    // Normalize check tokens
    $check = $cfg['check'] ?? [];
    if (is_string($check)) {
      $check = array_values(array_filter(array_map('trim', explode(' ', $check))));
    } else {
      $check = array_values((array)$check);
    }

    $isEmpty = ($value === null || $value === '' || $value === false);

    // required / not_empty
    if ((in_array('required', $check, true) || in_array('not_empty', $check, true)) && $isEmpty) {
      $errors[] = ['field' => $fldName, 'label' => $label, 'rule' => 'required'];
    }

    // No further checks on empty values
    if ($isEmpty) {
      return $errors;
    }

    // lookup (`id_from_tb`): the id must exist in the target table. On update only
    // when the value changes, so an old record with a dangling reference stays savable.
    if (!empty($cfg['id_from_tb']) && !$this->lookupExists((string) $cfg['id_from_tb'], $value)) {
      $unchanged = false;
      if ($excludeId) {
        $cur = $this->db->query("SELECT {$fldName} AS v FROM {$tb} WHERE id = ?", [$excludeId], 'read');
        $unchanged = isset($cur[0]) && (string) $cur[0]['v'] === (string) $value;
      }
      if (!$unchanged) {
        $errors[] = ['field' => $fldName, 'label' => $label, 'rule' => 'fk_not_found'];
      }
    }

    // int — must be a whole number
    if (in_array('int', $check, true) && !preg_match('/^-?\d+$/', (string)$value)) {
      $errors[] = ['field' => $fldName, 'label' => $label, 'rule' => 'int'];
    }

    // real — whole or decimal number, dot as separator (no comma, no exponent)
    if (in_array('real', $check, true) && !preg_match('/^-?\d+(\.\d+)?$/', (string)$value)) {
      $errors[] = ['field' => $fldName, 'label' => $label, 'rule' => 'real'];
    }

    // email
    if (in_array('email', $check, true) && !filter_var($value, FILTER_VALIDATE_EMAIL)) {
      $errors[] = ['field' => $fldName, 'label' => $label, 'rule' => 'email'];
    }

    // min / max — numeric or date (ISO string comparison for dates)
    $min = $cfg['min'] ?? null;
    $max = $cfg['max'] ?? null;

    if ($min !== null) {
      $tooSmall = ($type === 'date')
        ? ((string)$value < (string)$min)
        : (is_numeric($value) && (float)$value < (float)$min);
      if ($tooSmall) {
        $errors[] = ['field' => $fldName, 'label' => $label, 'rule' => 'min', 'min' => $min];
      }
    }
    if ($max !== null) {
      $tooBig = ($type === 'date')
        ? ((string)$value > (string)$max)
        : (is_numeric($value) && (float)$value > (float)$max);
      if ($tooBig) {
        $errors[] = ['field' => $fldName, 'label' => $label, 'rule' => 'max', 'max' => $max];
      }
    }

    // max_length
    $maxLen = $cfg['max_length'] ?? null;
    if ($maxLen && mb_strlen((string)$value) > (int)$maxLen) {
      $errors[] = ['field' => $fldName, 'label' => $label, 'rule' => 'max_length', 'max_length' => $maxLen];
    }

    // pattern / regex — use the 'pattern' config key
    $pattern = $cfg['pattern'] ?? null;
    if ($pattern) {
      $delimited = '/' . str_replace('/', '\/', $pattern) . '/u';
      if (@preg_match($delimited, (string)$value) === 0) {
        $errors[] = ['field' => $fldName, 'label' => $label, 'rule' => 'pattern'];
      }
    }

    // no_dupl — uniqueness check in DB (excludes current record on UPDATE)
    if (in_array('no_dupl', $check, true)) {
      $sql    = "SELECT COUNT(*) as c FROM {$tb} WHERE {$fldName} = ?";
      $params = [$value];
      if ($excludeId) {
        $sql    .= ' AND id != ?';
        $params[] = $excludeId;
      }
      $count = (int)($this->db->query($sql, $params)[0]['c'] ?? 0);
      if ($count > 0) {
        $errors[] = ['field' => $fldName, 'label' => $label, 'rule' => 'no_dupl'];
      }
    }

    // valid_wkt — basic prefix check (no geometry library needed)
    if (in_array('valid_wkt', $check, true)) {
      $wktPrefixes = ['POINT', 'LINESTRING', 'POLYGON', 'MULTIPOINT',
                      'MULTILINESTRING', 'MULTIPOLYGON', 'GEOMETRYCOLLECTION'];
      $upper = strtoupper(trim((string)$value));
      $valid = false;
      foreach ($wktPrefixes as $prefix) {
        if (str_starts_with($upper, $prefix)) {
          $valid = true;
          break;
        }
      }
      if (!$valid) {
        $errors[] = ['field' => $fldName, 'label' => $label, 'rule' => 'valid_wkt'];
      }
    }

    return $errors;
  }
}
