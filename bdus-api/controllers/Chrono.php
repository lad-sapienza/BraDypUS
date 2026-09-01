<?php

namespace Bdus\Controllers;

/**
 * @copyright 2007-2025 Julian Bogdani
 * @license AGPL-3.0; see LICENSE
 */

class Chrono extends \Bdus\Controller
{
    /**
     * GET /api/chrono/timeline
     *
     * Returns chrono ranges for all records that have chrono data,
     * grouped by table. Only tables with fuzzy_date enabled are included.
     *
     * Optional query params:
     *   from  (int)    — exclude records whose chrono_to < from
     *   to    (int)    — exclude records whose chrono_from > to
     *   tb[]  (string) — restrict to these table names (default: all fuzzy_date tables)
     */
    public function timeline(): void
    {
        if (!\Auth\Authorization::can('read')) {
            $this->returnJson(['status' => 'error', 'code' => 'not_enough_privilege']);
            return;
        }

        $fromYear  = isset($this->get['from']) && $this->get['from'] !== ''
            ? (int) $this->get['from'] : null;
        $toYear    = isset($this->get['to'])   && $this->get['to']   !== ''
            ? (int) $this->get['to']   : null;

        $tbFilter = $this->get['tb'] ?? null;
        if (is_string($tbFilter) && $tbFilter !== '') {
            $tbFilter = [$tbFilter];
        } elseif (!is_array($tbFilter)) {
            $tbFilter = null;
        }

        $allTables = $this->cfg->get('tables.*.name') ?? [];
        $result    = [];

        foreach ($allTables as $tbName) {
            if (!$this->cfg->get("tables.{$tbName}.fuzzy_date")) {
                continue;
            }

            if ($tbFilter !== null && !in_array($tbName, $tbFilter, true)) {
                continue;
            }

            // Validate table name — config-sourced, but guard anyway
            if (!preg_match('/^[a-zA-Z0-9_]+$/', $tbName)) {
                continue;
            }

            $tbLabel = $this->cfg->get("tables.{$tbName}.label") ?? $tbName;

            $preview      = $this->cfg->get("tables.{$tbName}.preview") ?? [];
            $displayField = $preview[0] ?? 'id';
            // Guard the field name the same way
            if (!preg_match('/^[a-zA-Z0-9_]+$/', $displayField)) {
                $displayField = 'id';
            }

            $table      = $tbName;
            $conditions = ['(chrono_from IS NOT NULL OR chrono_to IS NOT NULL)'];
            $params     = [];

            if ($fromYear !== null) {
                $conditions[] = '(chrono_to IS NULL OR chrono_to >= ?)';
                $params[]     = $fromYear;
            }
            if ($toYear !== null) {
                $conditions[] = '(chrono_from IS NULL OR chrono_from <= ?)';
                $params[]     = $toYear;
            }

            $where      = implode(' AND ', $conditions);
            $labelExpr  = $displayField !== 'id' ? $displayField : 'id';

            $sql = "SELECT id, {$labelExpr} AS display_label,
                           chrono_from, chrono_to,
                           chrono_label, chrono_certainty, chrono_period
                    FROM {$table}
                    WHERE {$where}
                    ORDER BY chrono_from ASC NULLS LAST, id ASC";

            $rows = $this->db->query($sql, $params, 'read');

            if (empty($rows)) {
                continue;
            }

            $records = [];
            foreach ($rows as $row) {
                $records[] = self::buildChronoRecord($row);
            }

            $result[] = [
                'tb_id'    => $tbName,
                'tb_label' => $tbLabel,
                'records'  => $records,
            ];
        }

        $this->returnJson(['status' => 'success', 'tables' => $result]);
    }

    /**
     * GET /api/chrono/related/{tb}/{id}
     *
     * Returns chrono ranges of records related to the given record ({tb}/{id}),
     * grouped by source table.
     *
     * Default (no `chrono_density_path` configured on {tb}): a single automatic
     * hop — tables that have a FK pointing directly to {tb} via bdus_cfg_relations,
     * filtered to those with fuzzy_date enabled.
     *
     * Configured (`tables.{tb}.chrono_density_path` set, see Config): a fixed
     * chain of table names, each a direct FK child of the previous one, ending
     * on a table that must have fuzzy_date enabled. Only that last table's
     * records are returned.
     *
     * Each source entry includes a `filter` object ready to be JSON-encoded for
     * DataView's "see related records" link — `{fk_col: {_eq: id}}` for the
     * automatic 1-hop case, `{id: {_in: [...]}}` for a configured multi-hop path
     * (the exact set of leaf-table ids reachable from the root record).
     */
    public function related(): void
    {
        if (!\Auth\Authorization::can('read')) {
            $this->returnJson(['status' => 'error', 'code' => 'not_enough_privilege']);
            return;
        }

        $tb = $this->get['tb'] ?? '';
        $id = (int) ($this->get['id'] ?? 0);

        if (!preg_match('/^[a-zA-Z0-9_]+$/', $tb) || $id <= 0) {
            $this->returnJson(['status' => 'error', 'code' => 'invalid_parameters']);
            return;
        }

        $path = (array) ($this->cfg->get("tables.{$tb}.chrono_density_path") ?? []);
        $path = array_values(array_filter($path, static fn($h) => is_string($h) && $h !== ''));

        if (!empty($path)) {
            $this->returnJson(['status' => 'success', 'sources' => $this->relatedViaPath($tb, $id, $path)]);
            return;
        }

        // Find tables that reference $tb via a FK (from_tb → to_tb)
        $rows = $this->db->query(
            'SELECT from_tb, from_col FROM bdus_cfg_relations WHERE to_tb = ?',
            [$tb],
            'read'
        ) ?: [];

        $result = [];

        foreach ($rows as $rel) {
            $linkedTb = $rel['from_tb'];
            $fkCol    = $rel['from_col'];

            if (!preg_match('/^[a-zA-Z0-9_]+$/', $linkedTb) ||
                !preg_match('/^[a-zA-Z0-9_]+$/', $fkCol)) {
                continue;
            }

            if (!$this->cfg->get("tables.{$linkedTb}.fuzzy_date")) {
                continue;
            }

            $tbLabel = $this->cfg->get("tables.{$linkedTb}.label") ?? $linkedTb;
            $preview      = $this->cfg->get("tables.{$linkedTb}.preview") ?? [];
            $displayField = $preview[0] ?? 'id';
            if (!preg_match('/^[a-zA-Z0-9_]+$/', $displayField)) {
                $displayField = 'id';
            }

            $sql = "SELECT id, {$displayField} AS display_label,
                           chrono_from, chrono_to,
                           chrono_label, chrono_certainty, chrono_period
                    FROM {$linkedTb}
                    WHERE {$fkCol} = ?
                      AND (chrono_from IS NOT NULL OR chrono_to IS NOT NULL)
                    ORDER BY chrono_from ASC NULLS LAST, id ASC";

            $records = [];
            foreach ($this->db->query($sql, [$id], 'read') ?: [] as $row) {
                $records[] = self::buildChronoRecord($row);
            }

            if (empty($records)) {
                continue;
            }

            $result[] = [
                'tb_id'    => $linkedTb,
                'tb_label' => $tbLabel,
                'fk_col'   => $fkCol,
                'records'  => $records,
                'filter'   => [$fkCol => ['_eq' => $id]],
            ];
        }

        $this->returnJson(['status' => 'success', 'sources' => $result]);
    }

    /**
     * Walks a configured `chrono_density_path` hop by hop, resolving each FK
     * column from bdus_cfg_relations, collecting the leaf-table ids reachable
     * from $rootId. Returns a one-element `sources` array (or empty when the
     * chain is broken, a hop yields no rows, or the leaf table has no chrono
     * data) — same shape as the automatic branch of related().
     *
     * @param string   $rootTb Root table name (already validated by the caller)
     * @param int      $rootId Root record id
     * @param string[] $path   Ordered chain of table names
     * @return array<int, array<string, mixed>>
     */
    private function relatedViaPath(string $rootTb, int $rootId, array $path): array
    {
        $ids      = [$rootId];
        $parentTb = $rootTb;

        foreach ($path as $hopTb) {
            if (empty($ids) || !preg_match('/^[a-zA-Z0-9_]+$/', $hopTb)) {
                return [];
            }

            $rel = $this->db->query(
                'SELECT from_col FROM bdus_cfg_relations WHERE from_tb = ? AND to_tb = ?',
                [$hopTb, $parentTb],
                'read'
            ) ?: [];
            $fkCol = $rel[0]['from_col'] ?? null;

            if (!$fkCol || !preg_match('/^[a-zA-Z0-9_]+$/', $fkCol)) {
                return [];
            }

            $placeholders = implode(', ', array_fill(0, count($ids), '?'));
            $rows = $this->db->query(
                "SELECT id FROM {$hopTb} WHERE {$fkCol} IN ({$placeholders})",
                $ids,
                'read'
            ) ?: [];
            $ids      = array_map(static fn($r) => (int) $r['id'], $rows);
            $parentTb = $hopTb;
        }

        if (empty($ids)) {
            return [];
        }

        $leafTb = end($path);
        if (!$this->cfg->get("tables.{$leafTb}.fuzzy_date")) {
            return [];
        }

        $tbLabel      = $this->cfg->get("tables.{$leafTb}.label") ?? $leafTb;
        $preview      = $this->cfg->get("tables.{$leafTb}.preview") ?? [];
        $displayField = $preview[0] ?? 'id';
        if (!preg_match('/^[a-zA-Z0-9_]+$/', $displayField)) {
            $displayField = 'id';
        }

        $placeholders = implode(', ', array_fill(0, count($ids), '?'));
        $sql = "SELECT id, {$displayField} AS display_label,
                       chrono_from, chrono_to,
                       chrono_label, chrono_certainty, chrono_period
                FROM {$leafTb}
                WHERE id IN ({$placeholders})
                  AND (chrono_from IS NOT NULL OR chrono_to IS NOT NULL)
                ORDER BY chrono_from ASC NULLS LAST, id ASC";

        $records = [];
        foreach ($this->db->query($sql, $ids, 'read') ?: [] as $row) {
            $records[] = self::buildChronoRecord($row);
        }

        if (empty($records)) {
            return [];
        }

        return [[
            'tb_id'    => $leafTb,
            'tb_label' => $tbLabel,
            'records'  => $records,
            'filter'   => ['id' => ['_in' => $ids]],
        ]];
    }

    /**
     * Normalises a raw chrono row (id, display_label, chrono_* columns) into
     * the public record shape shared by timeline(), related(), and
     * relatedViaPath(). Legacy string certainty values are mapped to integers.
     *
     * @param array<string, mixed> $row
     * @return array<string, mixed>
     */
    private static function buildChronoRecord(array $row): array
    {
        $certainty = $row['chrono_certainty'];
        if (!is_numeric($certainty)) {
            $certainty = match (strtolower((string) $certainty)) {
                'certa', 'certain'      => 1,
                'probabile', 'probable' => 2,
                'incerta', 'uncertain',
                'possible', 'possibile' => 3,
                default                 => 1,
            };
        }

        return [
            'id'           => (int) $row['id'],
            'label'        => (string) ($row['display_label'] ?? $row['id']),
            'from'         => $row['chrono_from'] !== null ? (int) $row['chrono_from'] : null,
            'to'           => $row['chrono_to']   !== null ? (int) $row['chrono_to']   : null,
            'chrono_label' => $row['chrono_label'] ?? null,
            'certainty'    => (int) $certainty,
            'period'       => $row['chrono_period'] ?? null,
        ];
    }
}
