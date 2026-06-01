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
            // Directly count rows — works on all engines.
            // If bdus_cfg_tables doesn't exist the query throws → caught → false.
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

        // Pre-load all relations from bdus_cfg_relations.
        // New schema (M026): one row per FK column pair —
        //   from_tb.from_col → to_tb.to_col (semantic direction; from_tb holds the FK).
        // Each row contributes to BOTH the forward-link list of from_tb AND the
        // reverse-link list of to_tb (with my/other swapped).
        // Rows are pre-grouped by other_tb so buildTable() can merge multi-column FKs.
        $relationsByTable = [];
        try {
            $allRelRows = $db->query(
                'SELECT id, from_tb, from_col, to_tb, to_col
                   FROM bdus_cfg_relations
                  ORDER BY from_tb ASC, to_tb ASC, from_col ASC',
                [],
                'read'
            ) ?: [];
            foreach ($allRelRows as $rel) {
                // Forward: current table holds the FK column.
                $relationsByTable[$rel['from_tb']][] = [
                    'other_tb' => $rel['to_tb'],
                    'my'       => $rel['from_col'],
                    'other'    => $rel['to_col'],
                ];
                // Reverse: current table is the referenced side.
                if ($rel['from_tb'] !== $rel['to_tb']) {
                    $relationsByTable[$rel['to_tb']][] = [
                        'other_tb' => $rel['from_tb'],
                        'my'       => $rel['to_col'],
                        'other'    => $rel['from_col'],
                    ];
                }
            }
        } catch (\Throwable $e) {
            // bdus_cfg_relations does not exist yet — M013/M026 pending. Ignore.
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
        // Build forward-link array from pre-flattened relation rows.
        // Each $rel already has 'other_tb', 'my', 'other' set by LoadFromDB::tables().
        // Rows with the same other_tb are grouped into one link entry (multi-column FK).
        // If no relation rows exist, fall back to the legacy `links` JSON blob
        // in bdus_cfg_tables (pre-M026 apps that have not yet been migrated).
        if (!empty($relRows)) {
            $grouped = [];
            foreach ($relRows as $rel) {
                $grouped[$rel['other_tb']][] = ['my' => $rel['my'], 'other' => $rel['other']];
            }
            $links = [];
            foreach ($grouped as $otherTb => $pairs) {
                $links[] = ['other_tb' => $otherTb, 'fld' => $pairs];
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
