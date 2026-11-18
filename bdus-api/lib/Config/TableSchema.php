<?php

/**
 * @copyright 2007-2025 Julian Bogdani
 * @license AGPL-3.0; see LICENSE
 */

namespace Config;

/**
 * The schema of a table as clients need it: its fields (type, label, help,
 * checks, source of the allowed values), its plugin tables and the features
 * switched on for it. Built from the configuration only; no access control
 * (the caller decides whether the principal may see the table).
 */
class TableSchema
{
  public function __construct(private readonly Config $cfg) {}

  /**
   * Builds { fields: [...], plugins: { tb: { tb_id, label, fields } } }
   * for the given table.
   */
  public function describe(string $tb): array
  {
    $plugins = [];
    foreach ($this->cfg->get("tables.{$tb}.plugin") ?: [] as $plg) {
      $plugins[$plg] = [
        'tb_id'  => $plg,
        'label'  => $this->cfg->get("tables.{$plg}.label") ?: $plg,
        'fields'      => $this->fields($plg),
      ];
    }

    return [
      'fields'      => $this->fields($tb),
      'plugins'     => $plugins,
      'rs'             => (bool) $this->cfg->get("tables.{$tb}.rs"),
      'id_field'       => $this->cfg->get("tables.{$tb}.id_field")    ?? 'id',
      'has_geodata'    => (bool) $this->cfg->get("tables.{$tb}.geodata"),
      'has_zotero'     => (bool) $this->cfg->get("tables.{$tb}.zotero"),
      'has_fuzzy_date'  => (bool) $this->cfg->get("tables.{$tb}.fuzzy_date"),
      'has_osteology'   => (bool) $this->cfg->get("tables.{$tb}.osteology"),
      'has_pleiades'    => (bool) $this->cfg->get("tables.{$tb}.pleiades"),
    ];
  }

  /**
   * Returns an array of field-schema objects for a single table.
   */
  public function fields(string $tb): array
  {
    $result = [];
    foreach ($this->cfg->get("tables.{$tb}.fields") ?: [] as $fld) {
      // Normalize check to a clean array of tokens.
      // Old configs use a space-separated string (e.g. "required int no_dupl");
      // new configs use an array. Both 'required' and 'not_empty' mean mandatory.
      $check = $fld['check'] ?? [];
      if (is_string($check)) {
        $check = array_values(array_filter(array_map('trim', explode(' ', $check))));
      } else {
        $check = array_values((array)$check);
      }

      $schema = [
        'name'          => $fld['name'],
        'label'         => $fld['label'] ?? $fld['name'],
        'type'          => $fld['type'] ?? 'text',
        'readonly'      => !empty($fld['readonly']) || !empty($fld['disabled']),
        'hide'          => !empty($fld['hide']),
        'help'          => $fld['help'] ?? null,
        // 'required' is normalized from either the 'required' or 'not_empty' check token
        'required'      => in_array('required', $check, true) || in_array('not_empty', $check, true),
        'check'         => $check,   // full token list for frontend validation
        'min'           => $fld['min'] ?? null,
        'max'           => $fld['max'] ?? null,
        'max_length'    => $fld['max_length'] ?? null,
        'pattern'       => $fld['pattern'] ?? null,
        'def_value'     => $fld['def_value'] ?? null,
        'direction'     => $fld['direction'] ?? null,
        'widget'        => $fld['widget'] ?? null,
        'options_source' => null,
      ];

      if (!empty($fld['get_values_from_tb'])) {
        $schema['options_source'] = ['type' => 'db',         'ref' => $fld['get_values_from_tb']];
      } elseif (!empty($fld['id_from_tb'])) {
        $schema['options_source'] = ['type' => 'id_from_tb', 'ref' => $fld['id_from_tb']];
      } elseif (!empty($fld['vocabulary_set'])) {
        $schema['options_source'] = ['type' => 'vocabulary',  'set' => $fld['vocabulary_set']];
      } elseif (!empty($fld['dic'])) {
        $schema['options_source'] = ['type' => 'static', 'items' => (array)$fld['dic']];
      }

      // Strip of buttons under a long_text / md editor (see Config\CharPalette).
      if (in_array($schema['type'], ['long_text', 'md'], true)) {
        $palette = CharPalette::normalize($fld['char_palette'] ?? null);
        if ($palette) {
          $schema['char_palette'] = $palette;
        }
      }

      $result[] = $schema;
    }

    return $result;
  }
}
