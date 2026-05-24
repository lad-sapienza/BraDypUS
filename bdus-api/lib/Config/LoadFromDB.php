<?php

/**
 * @copyright 2007-2025 Julian Bogdani
 * @license AGPL-3.0; see LICENSE
 */

declare(strict_types=1);

namespace Config;

use DB\DBInterface;

/**
 * Loads the tables/fields configuration from the database (bdus_cfg_tables +
 * bdus_cfg_fields) and returns the same in-memory structure that Config\Load
 * builds from JSON files, so Config\Config is storage-agnostic.
 *
 * Output shape (mirrors Load::all()['tables']):
 *
 *   [
 *     'us' => [
 *       'name'     => 'us',
 *       'label'    => 'Unità stratigrafiche',
 *       'order'    => 'sigla',
 *       'id_field' => 'id',
 *       'preview'  => ['sigla', 'descrizione'],
 *       'plugin'   => ['attivita'],       // derived from plugin_of
 *       'link'     => [...],
 *       'fields'   => [
 *         'sigla' => ['name' => 'sigla', 'label' => '…', 'type' => 'text', …],
 *         …
 *       ],
 *     ],
 *     …
 *   ]
 */
class LoadFromDB
{
    /** Columns explicitly stored in bdus_cfg_fields; remainder lives in extra JSON. */
    private const FIELD_COLUMNS = ['name', 'label', 'type', 'db_type', 'sort'];

    /**
     * Returns true when bdus_cfg_tables exists and contains at least one row.
     * Used by Config to decide which loader to use.
     */
    public static function isAvailable(DBInterface $db): bool
    {
        try {
            $rows = $db->query(
                "SELECT name FROM sqlite_master WHERE type='table' AND name='bdus_cfg_tables'",
                [],
                'read'
            );
            if (empty($rows)) return false;

            $cnt = $db->query('SELECT COUNT(*) AS cnt FROM bdus_cfg_tables', [], 'read');
            return ($cnt[0]['cnt'] ?? 0) > 0;
        } catch (\Throwable $e) {
            return false;
        }
    }

    /**
     * Loads all tables and their fields from the database.
     *
     * @return array  Keyed by table name; each value matches Load::getTables() format.
     */
    public static function tables(DBInterface $db): array
    {
        $tableRows = $db->query(
            'SELECT * FROM bdus_cfg_tables ORDER BY sort ASC, id ASC',
            [],
            'read'
        ) ?: [];

        // Pre-load all fields indexed by table_name for efficient lookup.
        $allFieldRows = $db->query(
            'SELECT * FROM bdus_cfg_fields ORDER BY table_name ASC, sort ASC, id ASC',
            [],
            'read'
        ) ?: [];

        $fieldsByTable = [];
        foreach ($allFieldRows as $fld) {
            $fieldsByTable[$fld['table_name']][] = $fld;
        }

        // Pre-load all relations from bdus_cfg_relations (post-M013).
        // After M020 each relationship is stored exactly once (from_tb → to_tb).
        // We therefore also collect rows in the REVERSE direction (to_tb = X)
        // and mark them so buildTable() can auto-invert the fld mapping.
        // Fall back gracefully if the table does not yet exist.
        $relationsByTable = [];
        try {
            $allRelRows = $db->query(
                'SELECT * FROM bdus_cfg_relations ORDER BY sort ASC, id ASC',
                [],
                'read'
            ) ?: [];
            foreach ($allRelRows as $rel) {
                // Forward direction: use as-is.
                $relationsByTable[$rel['from_tb']][] = $rel + ['_inverted' => false];
                // Reverse direction: fld will be inverted in buildTable().
                $relationsByTable[$rel['to_tb']][]   = $rel + ['_inverted' => true];
            }
        } catch (\Throwable $e) {
            // bdus_cfg_relations does not exist yet — M013 pending. Ignore.
        }

        $result = [];
        foreach ($tableRows as $row) {
            $name = $row['name'];
            $result[$name] = self::buildTable(
                $row,
                $fieldsByTable[$name] ?? [],
                $relationsByTable[$name] ?? []
            );
        }

        // Inject plugin lists: for each main table, collect plugin table names.
        foreach ($result as $name => $tbData) {
            if (!($tbData['is_plugin'] ?? false)) {
                $plugins = [];
                foreach ($result as $otherName => $otherData) {
                    if (($otherData['is_plugin'] ?? false) && ($otherData['plugin_of'] ?? null) === $name) {
                        $plugins[] = $otherName;
                    }
                }
                $result[$name]['plugin'] = $plugins;
            }
        }

        return $result;
    }

    // ── Private helpers ───────────────────────────────────────────────────────

    private static function buildTable(array $row, array $fieldRows, array $relRows = []): array
    {
        // Build forward-link array from bdus_cfg_relations rows (post-M013).
        // After M020, each relation is stored once; the reverse direction is
        // synthesised here by swapping my↔other in every fld pair.
        // If no relation rows exist, fall back to the legacy `links` JSON blob
        // in bdus_cfg_tables (pre-M013 apps that have not yet been migrated).
        if (!empty($relRows)) {
            $links = [];
            foreach ($relRows as $rel) {
                $fldArr = $rel['fld'] ? (json_decode($rel['fld'], true) ?: []) : [];

                if ($rel['_inverted'] ?? false) {
                    // Reverse direction: swap my↔other in every field pair
                    // so getLinks() queries the correct columns from each side.
                    $fldArr = array_map(
                        static fn($p) => ['my' => $p['other'] ?? '', 'other' => $p['my'] ?? ''],
                        $fldArr
                    );
                    $otherTb = $rel['from_tb']; // the "other" table is the original from_tb
                } else {
                    $otherTb = $rel['to_tb'];
                }

                $links[] = ['other_tb' => $otherTb, 'fld' => $fldArr];
            }
        } else {
            $links = $row['links'] ? json_decode($row['links'], true) : [];
        }

        $tb = [
            'name'      => $row['name'],
            'label'     => $row['label'] ?? null,
            'order'     => $row['order_field'] ?? null,   // JSON key is 'order'
            'id_field'  => $row['id_field'] ?? 'id',
            'preview'   => $row['preview']
                ? json_decode($row['preview'], true)
                : [],
            'is_plugin' => ($row['is_plugin'] ?? 0) ? '1' : '0',
            'plugin_of' => $row['plugin_of'] ?? null,
            'link'      => $links,
            'backlink'  => $row['backlinks']
                ? json_decode($row['backlinks'], true)
                : [],
            'fields'    => self::buildFields($fieldRows),
        ];

        // Merge extra JSON attributes back in (e.g. 'rs', future properties).
        if (!empty($row['extra'])) {
            $extra = json_decode($row['extra'], true) ?: [];
            $tb = array_merge($extra, $tb); // explicit columns take precedence
        }

        return $tb;
    }

    private static function buildFields(array $fieldRows): array
    {
        $fields = [];
        foreach ($fieldRows as $fld) {
            $name = $fld['name'];

            // Start with the explicitly stored columns.
            $built = [
                'name'    => $name,
                'label'   => $fld['label']   ?? null,
                'type'    => $fld['type']     ?? 'text',
                'db_type' => $fld['db_type']  ?? null,
                'sort'    => (int)($fld['sort'] ?? 0),
            ];

            // Merge extra JSON attributes back in.
            if (!empty($fld['extra'])) {
                $extra = json_decode($fld['extra'], true) ?: [];
                $built = array_merge($built, $extra);
            }

            $fields[$name] = $built;
        }
        return $fields;
    }
}
