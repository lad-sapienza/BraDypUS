<?php

namespace Bdus\Controllers;

/**
 * @copyright 2007-2025 Julian Bogdani
 * @license AGPL-3.0; see LICENSE
 */

use \DB\System\Manage;

class AssemblageAnalysis extends \Bdus\Controller
{
    // ── CRUD ─────────────────────────────────────────────────────────────────

    /**
     * GET — returns all analyses visible to the current user.
     *
     * Response: { status:'success', analyses: [...enriched rows] }
     */
    public function listAnalyses(): void
    {
        $sys  = new Manage($this->db);
        $rows = $sys->getBySQL(
            'bdus_assemblage_analyses',
            'user_id = ? OR is_global = ?',
            [\Auth\CurrentUser::id(), 1]
        );

        $result = [];
        foreach ($rows as $row) {
            $result[] = $this->enrichRow($row);
        }

        $this->returnJson(['status' => 'success', 'analyses' => $result]);
    }

    /**
     * POST { name, definition } — saves a new analysis for the current user.
     *
     * Response: { status:'success', code:'ok_save_analysis', analysis:{...} }
     */
    public function save(): void
    {
        $name       = $this->post['name']       ?? null;
        $definition = $this->post['definition'] ?? null;

        if (is_string($definition)) {
            $definition = json_decode($definition, true);
        }

        if (empty($name)) {
            $this->returnJson(['status' => 'error', 'code' => 'parameter_missing', 'detail' => 'name']);
            return;
        }
        if (empty($definition['source_tb'] ?? null)) {
            $this->returnJson(['status' => 'error', 'code' => 'parameter_missing', 'detail' => 'definition.source_tb']);
            return;
        }

        try {
            $sys   = new Manage($this->db);
            $newId = $sys->addRow('bdus_assemblage_analyses', [
                'user_id'    => \Auth\CurrentUser::id(),
                'created_at' => time(),
                'name'       => $name,
                'definition' => json_encode($definition),
                'is_global'  => 0,
            ]);

            if (!$newId) {
                $this->returnJson(['status' => 'error', 'code' => 'error_save_analysis']);
                return;
            }

            $row = $sys->getById('bdus_assemblage_analyses', $newId);
            $this->returnJson([
                'status'   => 'success',
                'code'     => 'ok_save_analysis',
                'analysis' => $this->enrichRow($row),
            ]);
        } catch (\Throwable $e) {
            $this->log->error($e);
            $this->returnJson(['status' => 'error', 'code' => 'db_error', 'detail' => $e->getMessage()]);
        }
    }

    /**
     * POST { name?, definition? } — updates an existing analysis.
     *
     * Response: { status:'success', code:'ok_save_analysis', analysis:{...} }
     */
    public function update(): void
    {
        $id = (int) ($this->get['id'] ?? 0) ?: null;
        if (!$id) {
            $this->returnJson(['status' => 'error', 'code' => 'parameter_missing', 'detail' => 'id']);
            return;
        }

        $sys = new Manage($this->db);
        $row = $sys->getById('bdus_assemblage_analyses', $id);

        if (empty($row)) {
            $this->returnJson(['status' => 'error', 'code' => 'analysis_not_found']);
            return;
        }
        if (!$this->assertOwnership($row)) {
            $this->returnJson(['status' => 'error', 'code' => 'analysis_access_denied']);
            return;
        }

        $updates = [];
        if (isset($this->post['name'])) {
            $updates['name'] = $this->post['name'];
        }
        if (isset($this->post['definition'])) {
            $definition = $this->post['definition'];
            if (is_string($definition)) {
                $definition = json_decode($definition, true);
            }
            $updates['definition'] = json_encode($definition);
        }

        if (empty($updates)) {
            $this->returnJson(['status' => 'error', 'code' => 'nothing_to_update']);
            return;
        }

        try {
            $sys->editRow('bdus_assemblage_analyses', $id, $updates);
            $updated = $sys->getById('bdus_assemblage_analyses', $id);
            $this->returnJson([
                'status'   => 'success',
                'code'     => 'ok_save_analysis',
                'analysis' => $this->enrichRow($updated),
            ]);
        } catch (\Throwable $e) {
            $this->log->error($e);
            $this->returnJson(['status' => 'error', 'code' => 'error_save_analysis']);
        }
    }

    /**
     * POST { id } — sets is_global = 1.
     *
     * Response: { status:'success', code:'ok_share_analysis' }
     */
    public function share(): void
    {
        $id = (int) ($this->post['id'] ?? $this->get['id'] ?? 0) ?: null;
        if (!$id) {
            $this->returnJson(['status' => 'error', 'code' => 'parameter_missing', 'detail' => 'id']);
            return;
        }

        $sys = new Manage($this->db);
        $row = $sys->getById('bdus_assemblage_analyses', $id);

        if (empty($row)) {
            $this->returnJson(['status' => 'error', 'code' => 'analysis_not_found']);
            return;
        }
        if (!$this->assertOwnership($row)) {
            $this->returnJson(['status' => 'error', 'code' => 'analysis_access_denied']);
            return;
        }

        try {
            $sys->editRow('bdus_assemblage_analyses', $id, ['is_global' => 1]);
            $this->returnJson(['status' => 'success', 'code' => 'ok_share_analysis']);
        } catch (\Throwable $e) {
            $this->log->error($e);
            $this->returnJson(['status' => 'error', 'code' => 'generic_error']);
        }
    }

    /**
     * POST { id } — sets is_global = 0.
     *
     * Response: { status:'success', code:'ok_unshare_analysis' }
     */
    public function unshare(): void
    {
        $id = (int) ($this->post['id'] ?? $this->get['id'] ?? 0) ?: null;
        if (!$id) {
            $this->returnJson(['status' => 'error', 'code' => 'parameter_missing', 'detail' => 'id']);
            return;
        }

        $sys = new Manage($this->db);
        $row = $sys->getById('bdus_assemblage_analyses', $id);

        if (empty($row)) {
            $this->returnJson(['status' => 'error', 'code' => 'analysis_not_found']);
            return;
        }
        if (!$this->assertOwnership($row)) {
            $this->returnJson(['status' => 'error', 'code' => 'analysis_access_denied']);
            return;
        }

        try {
            $sys->editRow('bdus_assemblage_analyses', $id, ['is_global' => 0]);
            $this->returnJson(['status' => 'success', 'code' => 'ok_unshare_analysis']);
        } catch (\Throwable $e) {
            $this->log->error($e);
            $this->returnJson(['status' => 'error', 'code' => 'generic_error']);
        }
    }

    /**
     * DELETE { id } — permanently deletes an analysis.
     *
     * Response: { status:'success', code:'ok_delete_analysis' }
     */
    public function delete(): void
    {
        $id = (int) ($this->post['id'] ?? $this->get['id'] ?? 0) ?: null;
        if (!$id) {
            $this->returnJson(['status' => 'error', 'code' => 'parameter_missing', 'detail' => 'id']);
            return;
        }

        $sys = new Manage($this->db);
        $row = $sys->getById('bdus_assemblage_analyses', $id);

        if (empty($row)) {
            $this->returnJson(['status' => 'error', 'code' => 'analysis_not_found']);
            return;
        }
        if (!$this->assertOwnership($row)) {
            $this->returnJson(['status' => 'error', 'code' => 'analysis_access_denied']);
            return;
        }

        try {
            $sys->deleteRow('bdus_assemblage_analyses', $id);
            $this->returnJson(['status' => 'success', 'code' => 'ok_delete_analysis']);
        } catch (\Throwable $e) {
            $this->log->error($e);
            $this->returnJson(['status' => 'error', 'code' => 'error_delete_analysis']);
        }
    }

    // ── Schema / wizard helpers ───────────────────────────────────────────────

    /**
     * GET — returns all user tables and plugin tables available as data sources.
     *
     * Response: {
     *   status:'success',
     *   tables: [{name, label}],
     *   plugins: [{name, label, parent_tb, parent_label}]
     * }
     */
    public function getSources(): void
    {
        $allTables = $this->cfg->get('tables.*.label') ?: [];
        $tables    = [];
        $plugins   = [];

        foreach ($allTables as $name => $label) {
            if (str_starts_with($name, 'bdus_')) {
                continue;
            }
            $isPlugin = $this->cfg->get("tables.{$name}.is_plugin");
            if ($isPlugin) {
                $parentTb  = $this->cfg->get("tables.{$name}.plugin_of") ?: '';
                $plugins[] = [
                    'name'         => $name,
                    'label'        => ($label ?: $name),
                    'parent_tb'    => $parentTb,
                    'parent_label' => $parentTb ? ($this->cfg->get("tables.{$parentTb}.label") ?: $parentTb) : '',
                ];
            } else {
                $tables[] = [
                    'name'  => $name,
                    'label' => ($label ?: $name),
                ];
            }
        }

        $this->returnJson(['status' => 'success', 'tables' => $tables, 'plugins' => $plugins]);
    }

    /**
     * GET ?tb=X — returns field metadata for a table (for wizard selectors).
     *
     * Response: {
     *   status:'success', tb, label,
     *   fields: [{name, label, type, is_numeric, is_fk, fk_tb?, fk_pk?, fk_label?}],
     *   plugins: [{name, label, fields:[...]}]
     * }
     */
    public function getTableMeta(): void
    {
        $tb = $this->get['tb'] ?? null;

        if (empty($tb)) {
            $this->returnJson(['status' => 'error', 'code' => 'parameter_missing', 'detail' => 'tb']);
            return;
        }
        if (!$this->isValidUserTable($tb)) {
            $this->returnJson(['status' => 'error', 'code' => 'invalid_table', 'detail' => $tb]);
            return;
        }

        $fields = [];
        foreach ((array) $this->cfg->get("tables.{$tb}.fields") as $f) {
            $fields[] = $this->buildFieldMeta($f);
        }

        $plugins = [];
        foreach ((array) $this->cfg->get("tables.{$tb}.plugin") as $plg) {
            $plgFields = [];
            foreach ((array) $this->cfg->get("tables.{$plg}.fields") as $f) {
                $plgFields[] = $this->buildFieldMeta($f);
            }
            $plugins[] = [
                'name'   => $plg,
                'label'  => ($this->cfg->get("tables.{$plg}.label") ?: $plg),
                'fields' => $plgFields,
            ];
        }

        $this->returnJson([
            'status'  => 'success',
            'tb'      => $tb,
            'label'   => ($this->cfg->get("tables.{$tb}.label") ?: $tb),
            'fields'  => $fields,
            'plugins' => $plugins,
        ]);
    }

    // ── Pivot engine ──────────────────────────────────────────────────────────

    /**
     * POST { definition } — executes the pivot query and returns the result.
     *
     * Definition schema:
     * {
     *   source_tb:    string,               // source table
     *   char_field:   string,               // pivot column axis
     *   group_path:   [{local_field, join_tb, join_pk}],  // FK traversal chain
     *   group_field:  string,               // pivot row axis (in last table of path)
     *   measure:      'count'|'sum'|'count_distinct',
     *   measure_field: string|null,         // required for sum and count_distinct
     *   filters:      [{field, op, value}], // simple filter list
     * }
     *
     * Response: { status:'success', chars:[...], groups:[...], data:{group:{char:val}} }
     */
    public function getData(): void
    {
        $definition = $this->post['definition'] ?? null;
        if (is_string($definition)) {
            $definition = json_decode($definition, true);
        }
        if (!is_array($definition)) {
            $this->returnJson(['status' => 'error', 'code' => 'parameter_missing', 'detail' => 'definition']);
            return;
        }

        $sourceTb     = $definition['source_tb']     ?? null;
        $charField    = $definition['char_field']     ?? null;
        $groupPath    = $definition['group_path']     ?? [];
        $groupField   = $definition['group_field']    ?? null;
        $measure      = $definition['measure']        ?? 'count';
        $measureField = $definition['measure_field']  ?? null;
        $filters      = $definition['filters']        ?? [];

        // ── Validate ──────────────────────────────────────────────────────────
        if (empty($sourceTb)) {
            $this->returnJson(['status' => 'error', 'code' => 'parameter_missing', 'detail' => 'source_tb']);
            return;
        }
        if (!$this->isValidUserTable($sourceTb)) {
            $this->returnJson(['status' => 'error', 'code' => 'invalid_table', 'detail' => $sourceTb]);
            return;
        }
        if (empty($charField)) {
            $this->returnJson(['status' => 'error', 'code' => 'parameter_missing', 'detail' => 'char_field']);
            return;
        }
        if (!$this->isValidField($sourceTb, $charField)) {
            $this->returnJson(['status' => 'error', 'code' => 'invalid_field', 'detail' => $charField]);
            return;
        }
        if (empty($groupField)) {
            $this->returnJson(['status' => 'error', 'code' => 'parameter_missing', 'detail' => 'group_field']);
            return;
        }

        $allowedMeasures = ['count', 'sum', 'count_distinct'];
        if (!in_array($measure, $allowedMeasures, true)) {
            $this->returnJson(['status' => 'error', 'code' => 'invalid_measure']);
            return;
        }
        if (in_array($measure, ['sum', 'count_distinct'], true) && empty($measureField)) {
            $this->returnJson(['status' => 'error', 'code' => 'parameter_missing', 'detail' => 'measure_field']);
            return;
        }
        if (!empty($measureField) && !$this->isValidField($sourceTb, $measureField)) {
            $this->returnJson(['status' => 'error', 'code' => 'invalid_field', 'detail' => $measureField]);
            return;
        }

        // ── Build JOIN chain ──────────────────────────────────────────────────
        if (!is_array($groupPath)) {
            $groupPath = [];
        }
        if (count($groupPath) > 4) {
            $this->returnJson(['status' => 'error', 'code' => 'too_many_join_steps']);
            return;
        }

        $joins          = '';
        $currentRefName = $sourceTb; // source table is referenced by name (no alias)
        $currentTbName  = $sourceTb;

        foreach ($groupPath as $i => $step) {
            $localField = $step['local_field'] ?? null;
            $joinTb     = $step['join_tb']     ?? null;
            $joinPk     = $step['join_pk']     ?? null;

            if (empty($localField) || empty($joinTb) || empty($joinPk)) {
                $this->returnJson(['status' => 'error', 'code' => 'invalid_group_path_step', 'detail' => $i]);
                return;
            }
            if (!$this->isValidUserTable($joinTb)) {
                $this->returnJson(['status' => 'error', 'code' => 'invalid_table', 'detail' => $joinTb]);
                return;
            }
            if (!$this->isValidField($currentTbName, $localField)) {
                $this->returnJson(['status' => 'error', 'code' => 'invalid_field', 'detail' => $localField]);
                return;
            }

            $alias          = 't' . ($i + 1);
            $joins         .= " JOIN {$joinTb} AS {$alias} ON {$currentRefName}.{$localField} = {$alias}.{$joinPk}";
            $currentRefName = $alias;
            $currentTbName  = $joinTb;
        }

        if (!$this->isValidField($currentTbName, $groupField)) {
            $this->returnJson(['status' => 'error', 'code' => 'invalid_field', 'detail' => $groupField]);
            return;
        }

        // ── Display label (human PK / preview field) ─────────────────────────
        // When group_field is itself an FK pointing to another table (e.g.
        // source=reperti, group_field=us_provenienza_id → us table), we want
        // the preview field of that referenced table, not of the current table.
        // Otherwise (group_path traversal or plain field) use current table's preview.
        $displayField = null;
        $displayExpr  = null;
        $displayJoin  = '';

        $groupFieldCfg = null;
        foreach (($this->cfg->get("tables.{$currentTbName}.fields") ?? []) as $f) {
            if (($f['name'] ?? null) === $groupField) { $groupFieldCfg = $f; break; }
        }
        $groupFkTb = $groupFieldCfg['id_from_tb'] ?? null;

        if ($groupFkTb && $this->isValidUserTable($groupFkTb)) {
            // group_field is an FK → label from the referenced table.
            // FK columns always store the numeric autoincrement id, so join on 'id',
            // not on id_field (which is the semantic/human PK like 'us', 'nome', etc.).
            $fkPreview = $this->cfg->get("tables.{$groupFkTb}.preview") ?? [];
            if (!empty($fkPreview) && $this->isValidField($groupFkTb, $fkPreview[0])) {
                $lblAlias    = '_lbl';
                $displayField = $fkPreview[0];
                $displayJoin  = " LEFT JOIN {$groupFkTb} AS {$lblAlias} ON {$currentRefName}.{$groupField} = {$lblAlias}.id";
                $displayExpr  = "{$lblAlias}.{$displayField}";
            }
        } else {
            // group_field is a plain field (or joined via group_path) → label from current table
            $previewCfg = $this->cfg->get("tables.{$currentTbName}.preview") ?? [];
            if (!empty($previewCfg)) {
                $candidate = $previewCfg[0];
                if ($candidate !== $groupField && $this->isValidField($currentTbName, $candidate)) {
                    $displayField = $candidate;
                    $displayRef   = empty($groupPath) ? $sourceTb : $currentRefName;
                    $displayExpr  = "{$displayRef}.{$displayField}";
                }
            }
        }

        // ── Build SQL ─────────────────────────────────────────────────────────
        $measureExpr = match($measure) {
            'sum'            => "SUM({$sourceTb}.{$measureField})",
            'count_distinct' => "COUNT(DISTINCT {$sourceTb}.{$measureField})",
            default          => 'COUNT(*)',
        };

        // Convert simple filter list to JsonFilter format
        $jsonFilter = [];
        if (is_array($filters)) {
            foreach ($filters as $f) {
                if (empty($f['field']) || empty($f['op'])) {
                    continue;
                }
                $jsonFilter[$f['field']] = [$f['op'] => $f['value'] ?? ''];
            }
        }
        $qRequest = [
            'tb'   => $sourceTb,
            'type' => empty($jsonFilter) ? 'all' : 'filter',
        ];
        if (!empty($jsonFilter)) {
            $qRequest['filter'] = $jsonFilter;
        }
        [$whereClause, $whereValues] = (new \SQL\QueryFromRequest($this->db, $this->cfg, $qRequest))
            ->getWhereClause();

        $charExpr  = "{$sourceTb}.{$charField}";
        $groupExpr = empty($groupPath)
            ? "{$sourceTb}.{$groupField}"
            : "{$currentRefName}.{$groupField}";

        $selectExtra = $displayExpr ? ", MIN({$displayExpr}) AS display_val" : '';
        $sql = "SELECT {$charExpr} AS char_val, {$groupExpr} AS group_val, {$measureExpr} AS measure_val{$selectExtra}"
             . " FROM {$sourceTb}{$joins}{$displayJoin}"
             . " WHERE {$whereClause}"
             . " GROUP BY {$charExpr}, {$groupExpr}"
             . " ORDER BY {$groupExpr}, {$charExpr}";

        try {
            $rows = $this->db->query($sql, $whereValues, 'read');
        } catch (\Throwable $e) {
            $this->log->error($e);
            $this->returnJson(['status' => 'error', 'code' => 'query_error', 'detail' => $e->getMessage()]);
            return;
        }

        // ── Pivot in PHP ──────────────────────────────────────────────────────
        $chars        = [];
        $groups       = [];
        $data         = [];
        $group_labels = [];

        foreach ($rows as $row) {
            $char  = (string) ($row['char_val']  ?? '');
            $group = (string) ($row['group_val'] ?? '');
            $val   = is_numeric($row['measure_val']) ? (float) $row['measure_val'] : 0;

            if (!in_array($char, $chars, true)) {
                $chars[] = $char;
            }
            if (!in_array($group, $groups, true)) {
                $groups[] = $group;
            }
            $data[$group][$char] = $val;
            if ($displayField && !isset($group_labels[$group])) {
                $group_labels[$group] = (string) ($row['display_val'] ?? '');
            }
        }

        $this->returnJson([
            'status'       => 'success',
            'chars'        => $chars,
            'groups'       => $groups,
            'data'         => $data,
            'group_labels' => $group_labels,
            'group_tb'     => $currentTbName,
            'group_field'  => $groupField,
        ]);
    }

    // ── Private helpers ───────────────────────────────────────────────────────

    private function assertOwnership(array $row): bool
    {
        return (int) $row['user_id'] === \Auth\CurrentUser::id()
            || \Auth\Authorization::can('super_admin');
    }

    private function enrichRow(array $row): array
    {
        $definition = null;
        if (!empty($row['definition'])) {
            $definition = json_decode($row['definition'], true);
        }
        $row['definition']  = $definition;
        $row['owned_by_me'] = (int) $row['user_id'] === \Auth\CurrentUser::id();
        return $row;
    }

    private function isValidUserTable(string $tb): bool
    {
        if (str_starts_with($tb, 'bdus_')) {
            return false;
        }
        $allTables = $this->cfg->get('tables.*.label') ?: [];
        return array_key_exists($tb, $allTables);
    }

    private function isValidField(string $tb, string $field): bool
    {
        if ($field === 'id') {
            return true;
        }
        $fields = $this->cfg->get("tables.{$tb}.fields") ?: [];
        foreach ($fields as $fld) {
            if (($fld['name'] ?? null) === $field) {
                return true;
            }
        }
        return false;
    }

    private function buildFieldMeta(array $f): array
    {
        $numericTypes = ['integer', 'float', 'double', 'numeric', 'int', 'number'];
        $type         = $f['type'] ?? 'text';
        $isNumeric    = in_array(strtolower($type), $numericTypes, true);
        $isFk         = !empty($f['id_from_tb']);

        $meta = [
            'name'       => $f['name'],
            'label'      => $f['label'] ?? $f['name'],
            'type'       => $type,
            'is_numeric' => $isNumeric,
            'is_fk'      => $isFk,
        ];

        if ($isFk) {
            $refTb            = $f['id_from_tb'];
            $meta['fk_tb']    = $refTb;
            $meta['fk_pk']    = $this->cfg->get("tables.{$refTb}.id_field") ?: 'id';
            $meta['fk_label'] = $this->cfg->get("tables.{$refTb}.label") ?: $refTb;
        }

        return $meta;
    }
}
