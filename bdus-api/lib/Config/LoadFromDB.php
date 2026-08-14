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
 *       'plugin'   => ['attivita'],       // derived from bdus_cfg_relations
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

        // A plugin table attaches to its parent via a relation row shaped
        // from_tb={plugin}, from_col='id_link', to_tb={parent}, to_col='id'
        // (see Alter::createMinimalTable). The UNIQUE(from_tb, from_col)
        // constraint on bdus_cfg_relations already guarantees one parent per
        // plugin table — no separate plugin_of bookkeeping needed.
        $isPluginByName = [];
        foreach ($tableRows as $row) {
            $isPluginByName[$row['name']] = (bool) ($row['is_plugin'] ?? 0);
        }

        // Pre-load all relations from bdus_cfg_relations.
        // New schema (M026): one row per FK column pair —
        //   from_tb.from_col → to_tb.to_col (semantic direction; from_tb holds the FK).
        // Each row contributes to BOTH the forward-link list of from_tb AND the
        // reverse-link list of to_tb (with my/other swapped) — UNLESS from_tb is a
        // plugin table pointing at its parent via id_link, in which case the row is
        // routed to $pluginParentOf instead of either link list (autodiscovery: the
        // parent loads it as an inline CRUD plugin section, not a read-only link).
        // Rows are pre-grouped by other_tb so buildTable() can merge multi-column FKs.
        $relationsByTable = [];
        $pluginParentOf   = []; // plugin_tb => parent_tb
        try {
            $allRelRows = $db->query(
                'SELECT id, from_tb, from_col, to_tb, to_col
                   FROM bdus_cfg_relations
                  ORDER BY from_tb ASC, to_tb ASC, from_col ASC',
                [],
                'read'
            ) ?: [];
            foreach ($allRelRows as $rel) {
                if (($isPluginByName[$rel['from_tb']] ?? false) && $rel['from_col'] === 'id_link') {
                    $pluginParentOf[$rel['from_tb']] = $rel['to_tb'];
                    continue;
                }

                // Defensive: Config::saveRelation() rejects new relations that touch a
                // plugin table outside its id_link row (see #19), but a row created
                // before that guard existed — or written directly to the DB — would
                // otherwise still surface as a "Linked records" entry the frontend can
                // never open (plugin tables are excluded from GET /api/tables). Skip
                // any relation where either side is a plugin table, beyond the id_link
                // case already routed to $pluginParentOf above.
                if (($isPluginByName[$rel['from_tb']] ?? false) || ($isPluginByName[$rel['to_tb']] ?? false)) {
                    continue;
                }

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

        // Inject plugin lists: one linear pass, appended in table-sort order
        // (the same order plugin_of-scan derivation used to produce), replacing
        // the previous O(n²) scan with an O(n) walk over the already-loaded
        // relations.
        foreach ($result as $name => $tbData) {
            if (!($tbData['is_plugin'] ?? false)) {
                $result[$name]['plugin'] = [];
            }
        }
        foreach ($result as $name => $tbData) {
            if (!($tbData['is_plugin'] ?? false)) {
                continue;
            }
            $parent = $pluginParentOf[$name] ?? null;
            // Relation-derived, replacing the legacy plugin_of scalar column as the
            // source of truth — every reader (JsonFilter, AssemblageAnalysis,
            // DbmlExporter, ConfigTableForm.vue, …) keeps working unchanged since
            // they all read this same 'plugin_of' key via cfg->get(), just now
            // backed by bdus_cfg_relations instead of the DB column.
            $result[$name]['plugin_of'] = $parent;
            if ($parent !== null && isset($result[$parent])) {
                $result[$parent]['plugin'][] = $name;
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
            // 'plugin_of' is set afterward in tables(), derived from
            // bdus_cfg_relations — not read from the (legacy, unused) DB column.
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
