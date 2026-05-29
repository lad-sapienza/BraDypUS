<?php

/**
 * @copyright 2007-2025 Julian Bogdani
 * @license AGPL-3.0; see LICENSE
 */

use \DB\System\Manage;

class chart_ctrl extends Controller
{
    // ── v5 methods ────────────────────────────────────────────────────────────

    /**
     * POST { definition: { tb, type, ... } }
     *
     * Runs a chart query without saving. Returns formatted result ready for
     * rendering by vue-chartjs.
     *
     * Metric response:  { status, type:'metric', value:42, label:'COUNT of id' }
     * Others response:  { status, type:'bar', labels:[...], data:[...] }
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

        $tb   = $definition['tb']   ?? null;
        $type = $definition['type'] ?? null;

        if (empty($tb)) {
            $this->returnJson(['status' => 'error', 'code' => 'parameter_missing', 'detail' => 'tb']);
            return;
        }

        $allowedTypes = ['metric', 'bar', 'line', 'pie', 'doughnut'];
        if (!in_array($type, $allowedTypes, true)) {
            $this->returnJson(['status' => 'error', 'code' => 'invalid_chart_type']);
            return;
        }

        $allowedFunctions = ['COUNT', 'SUM', 'AVG', 'MIN', 'MAX'];

        // ── Validate fields & function per type ──────────────────────────────
        if ($type === 'metric') {
            $field    = $definition['field']    ?? null;
            $function = strtoupper($definition['function'] ?? '');

            if (empty($field)) {
                $this->returnJson(['status' => 'error', 'code' => 'parameter_missing', 'detail' => 'field']);
                return;
            }
            if (!in_array($function, $allowedFunctions, true)) {
                $this->returnJson(['status' => 'error', 'code' => 'parameter_missing', 'detail' => 'function']);
                return;
            }
            if (!$this->isValidField($tb, $field)) {
                $this->returnJson(['status' => 'error', 'code' => 'invalid_chart_field', 'detail' => $field]);
                return;
            }

        } else {
            $xField    = $definition['x_field']    ?? null;
            $yField    = $definition['y_field']    ?? null;
            $yFunction = strtoupper($definition['y_function'] ?? '');

            if (empty($xField)) {
                $this->returnJson(['status' => 'error', 'code' => 'parameter_missing', 'detail' => 'x_field']);
                return;
            }
            if (empty($yField)) {
                $this->returnJson(['status' => 'error', 'code' => 'parameter_missing', 'detail' => 'y_field']);
                return;
            }
            if (!in_array($yFunction, $allowedFunctions, true)) {
                $this->returnJson(['status' => 'error', 'code' => 'parameter_missing', 'detail' => 'y_function']);
                return;
            }
            if (!$this->isValidField($tb, $xField)) {
                $this->returnJson(['status' => 'error', 'code' => 'invalid_chart_field', 'detail' => $xField]);
                return;
            }
            if (!$this->isValidField($tb, $yField)) {
                $this->returnJson(['status' => 'error', 'code' => 'invalid_chart_field', 'detail' => $yField]);
                return;
            }
        }

        // ── Build WHERE clause from optional filter ───────────────────────────
        // Delegate entirely to QueryFromRequest so all search types (including
        // 'advanced') work without duplication.  The module knows nothing about
        // how the WHERE is constructed — it just asks for the predicate.
        $filter   = $definition['filter'] ?? null;
        $qRequest = ['tb' => $tb, 'type' => 'all'];
        if (!empty($filter)) {
            if (!empty($filter['filter']) && is_array($filter['filter'])) {
                // Directus-style JSON filter (new format)
                $qRequest['type']   = 'filter';
                $qRequest['filter'] = $filter['filter'];
            } elseif (!empty($filter['search_type'])) {
                $qRequest['type'] = $filter['search_type'];
                switch ($filter['search_type']) {
                    case 'sqlExpert':
                        $qRequest['querytext'] = $filter['querytext'] ?? '';
                        $qRequest['join']      = $filter['join']      ?? '';
                        break;
                    case 'advanced':
                        $qRequest['adv'] = $filter['adv'] ?? [];
                        break;
                }
            }
        }
        [$whereClause, $whereValues] = (new \QueryFromRequest($this->db, $this->cfg, $qRequest))
            ->getWhereClause();

        $whereSql = ' WHERE ' . $whereClause; // always non-empty (at least '1=1')

        try {
            if ($type === 'metric') {
                // SELECT {FUNC}({field}) AS value FROM {tb} {WHERE}
                $sql    = "SELECT {$function}({$field}) AS value FROM {$tb}{$whereSql}";
                $rows   = $this->db->query($sql, $whereValues, 'read');
                $value  = $rows[0]['value'] ?? null;

                $this->returnJson([
                    'status' => 'success',
                    'type'   => 'metric',
                    'value'  => is_numeric($value) ? (float) $value : $value,
                    'label'  => $function . ' of ' . $field,
                ]);

            } else {
                // SELECT {x_field} AS label, {FUNC}({y_field}) AS value
                // FROM {tb} {WHERE} GROUP BY {x_field} ORDER BY {x_field}
                $sql  = "SELECT {$xField} AS label, {$yFunction}({$yField}) AS value"
                    . " FROM {$tb}{$whereSql}"
                    . " GROUP BY {$xField}"
                    . " ORDER BY {$xField}";

                $rows   = $this->db->query($sql, $whereValues, 'read');
                $labels = [];
                $data   = [];
                foreach ($rows as $row) {
                    $labels[] = $row['label'] ?? '';
                    $data[]   = is_numeric($row['value']) ? (float) $row['value'] : 0;
                }

                $this->returnJson([
                    'status' => 'success',
                    'type'   => $type,
                    'labels' => $labels,
                    'data'   => $data,
                ]);
            }

        } catch (\Throwable $e) {
            $this->log->error($e);
            $this->returnJson(['status' => 'error', 'code' => 'generic_error', 'detail' => $e->getMessage()]);
        }
    }

    /**
     * GET — returns all charts visible to the current user:
     *   user_id = current OR is_global = 1.
     *
     * Response: { status:'success', charts:[...enriched rows] }
     */
    public function listCharts(): void
    {
        $sys_manager = new Manage($this->db);
        $rows = $sys_manager->getBySQL(
            'bdus_charts',
            'user_id = ? OR is_global = ?',
            [\Auth\CurrentUser::id(), 1]
        );

        $result = [];
        foreach ($rows as $row) {
            $result[] = $this->enrichRow($row);
        }

        $this->returnJson(['status' => 'success', 'charts' => $result]);
    }

    /**
     * POST { name, definition: {...} } — saves a new chart for the current user.
     *
     * Response: { status:'success', code:'ok_save_chart', chart:{...} }
     */
    public function saveChart(): void
    {
        if (!\utils::canUser('edit')) {
            $this->returnJson(['status' => 'error', 'code' => 'not_enough_privilege']);
            return;
        }

        $name       = $this->post['name']       ?? null;
        $definition = $this->post['definition'] ?? null;

        if (is_string($definition)) {
            $definition = json_decode($definition, true);
        }

        if (empty($name)) {
            $this->returnJson(['status' => 'error', 'code' => 'parameter_missing', 'detail' => 'name']);
            return;
        }
        if (empty($definition['tb'] ?? null)) {
            $this->returnJson(['status' => 'error', 'code' => 'parameter_missing', 'detail' => 'definition.tb']);
            return;
        }

        try {
            $sys_manager = new Manage($this->db);
            $newId = $sys_manager->addRow('bdus_charts', [
                'user_id'    => \Auth\CurrentUser::id(),
                'created_at' => time(),
                'name'       => $name,
                'definition' => json_encode($definition),
                'is_global'  => 0,
            ]);

            if (!$newId) {
                $this->returnJson(['status' => 'error', 'code' => 'error_save_chart']);
                return;
            }

            $row = $sys_manager->getById('bdus_charts', $newId);
            $this->returnJson([
                'status' => 'success',
                'code'   => 'ok_save_chart',
                'chart'  => $this->enrichRow($row),
            ]);

        } catch (\Throwable $e) {
            $this->log->error($e);
            $this->returnJson(['status' => 'error', 'code' => 'error_save_chart']);
        }
    }

    /**
     * POST { id } — sets is_global = 1 for a chart.
     *
     * Only the owner or a super_admin may share.
     *
     * Response: { status:'success', code:'ok_sharing_chart' }
     */
    public function shareChart(): void
    {
        $id = (int) ($this->post['id'] ?? $this->get['id'] ?? 0) ?: null;
        if (!$id) {
            $this->returnJson(['status' => 'error', 'code' => 'parameter_missing', 'detail' => 'id']);
            return;
        }

        $sys_manager = new Manage($this->db);
        $row = $sys_manager->getById('bdus_charts', $id);

        if (empty($row)) {
            $this->returnJson(['status' => 'error', 'code' => 'chart_not_found']);
            return;
        }
        if (!$this->assertOwnership($row)) {
            $this->returnJson(['status' => 'error', 'code' => 'chart_access_denied']);
            return;
        }

        try {
            $sys_manager->editRow('bdus_charts', $id, ['is_global' => 1]);
            $this->returnJson(['status' => 'success', 'code' => 'ok_sharing_chart']);
        } catch (\Throwable $e) {
            $this->log->error($e);
            $this->returnJson(['status' => 'error', 'code' => 'error_sharing_chart']);
        }
    }

    /**
     * POST { id } — sets is_global = 0 for a chart.
     *
     * Only the owner or a super_admin may unshare.
     *
     * Response: { status:'success', code:'ok_unsharing_chart' }
     */
    public function unshareChart(): void
    {
        $id = (int) ($this->post['id'] ?? $this->get['id'] ?? 0) ?: null;
        if (!$id) {
            $this->returnJson(['status' => 'error', 'code' => 'parameter_missing', 'detail' => 'id']);
            return;
        }

        $sys_manager = new Manage($this->db);
        $row = $sys_manager->getById('bdus_charts', $id);

        if (empty($row)) {
            $this->returnJson(['status' => 'error', 'code' => 'chart_not_found']);
            return;
        }
        if (!$this->assertOwnership($row)) {
            $this->returnJson(['status' => 'error', 'code' => 'chart_access_denied']);
            return;
        }

        try {
            $sys_manager->editRow('bdus_charts', $id, ['is_global' => 0]);
            $this->returnJson(['status' => 'success', 'code' => 'ok_unsharing_chart']);
        } catch (\Throwable $e) {
            $this->log->error($e);
            $this->returnJson(['status' => 'error', 'code' => 'error_unsharing_chart']);
        }
    }

    /**
     * POST { id } — permanently deletes a chart.
     *
     * Only the owner or a super_admin may delete.
     *
     * Response: { status:'success', code:'ok_chart_erase' }
     */
    public function deleteChart(): void
    {
        $id = (int) ($this->post['id'] ?? $this->get['id'] ?? 0) ?: null;
        if (!$id) {
            $this->returnJson(['status' => 'error', 'code' => 'parameter_missing', 'detail' => 'id']);
            return;
        }

        $sys_manager = new Manage($this->db);
        $row = $sys_manager->getById('bdus_charts', $id);

        if (empty($row)) {
            $this->returnJson(['status' => 'error', 'code' => 'chart_not_found']);
            return;
        }
        if (!$this->assertOwnership($row)) {
            $this->returnJson(['status' => 'error', 'code' => 'chart_access_denied']);
            return;
        }

        try {
            $sys_manager->deleteRow('bdus_charts', $id);
            $this->returnJson(['status' => 'success', 'code' => 'ok_chart_erase']);
        } catch (\Throwable $e) {
            $this->log->error($e);
            $this->returnJson(['status' => 'error', 'code' => 'error_chart_erase']);
        }
    }

    // ── Private helpers ───────────────────────────────────────────────────────

    /**
     * Returns true when the current user owns the row OR has super_admin privilege.
     */
    private function assertOwnership(array $row): bool
    {
        return (int) $row['user_id'] === \Auth\CurrentUser::id()
            || \utils::canUser('super_admin');
    }

    /**
     * Enriches a raw charts row with virtual fields used by the frontend.
     */
    private function enrichRow(array $row): array
    {
        $definition = null;
        if (!empty($row['definition'])) {
            $definition = json_decode($row['definition'], true);
        }

        $tb       = $definition['tb'] ?? null;
        $tbLabel  = $tb ? ($this->cfg->get("tables.{$tb}.label") ?? $tb) : null;

        $row['definition']  = $definition;
        $row['tb_label']    = $tbLabel;
        $row['owned_by_me'] = (int) $row['user_id'] === \Auth\CurrentUser::id();
        return $row;
    }

    /**
     * Validates that $field is allowed for $tb.
     * `id` is always valid. Other fields must appear in config.
     */
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

}
