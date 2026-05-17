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
        [$whereClause, $whereValues] = $this->buildWhereFromFilter(
            $definition['filter'] ?? null,
            $tb
        );

        $whereSql = $whereClause ? ' WHERE ' . $whereClause : '';

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
        $sys_manager = new Manage($this->db, $this->prefix);
        $rows = $sys_manager->getBySQL(
            'charts',
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
            $sys_manager = new Manage($this->db, $this->prefix);
            $newId = $sys_manager->addRow('charts', [
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

            $row = $sys_manager->getById('charts', $newId);
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
        $id = isset($this->post['id']) ? (int) $this->post['id'] : null;
        if (!$id) {
            $this->returnJson(['status' => 'error', 'code' => 'parameter_missing', 'detail' => 'id']);
            return;
        }

        $sys_manager = new Manage($this->db, $this->prefix);
        $row = $sys_manager->getById('charts', $id);

        if (empty($row)) {
            $this->returnJson(['status' => 'error', 'code' => 'chart_not_found']);
            return;
        }
        if (!$this->assertOwnership($row)) {
            $this->returnJson(['status' => 'error', 'code' => 'chart_access_denied']);
            return;
        }

        try {
            $sys_manager->editRow('charts', $id, ['is_global' => 1]);
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
        $id = isset($this->post['id']) ? (int) $this->post['id'] : null;
        if (!$id) {
            $this->returnJson(['status' => 'error', 'code' => 'parameter_missing', 'detail' => 'id']);
            return;
        }

        $sys_manager = new Manage($this->db, $this->prefix);
        $row = $sys_manager->getById('charts', $id);

        if (empty($row)) {
            $this->returnJson(['status' => 'error', 'code' => 'chart_not_found']);
            return;
        }
        if (!$this->assertOwnership($row)) {
            $this->returnJson(['status' => 'error', 'code' => 'chart_access_denied']);
            return;
        }

        try {
            $sys_manager->editRow('charts', $id, ['is_global' => 0]);
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
        $id = isset($this->post['id']) ? (int) $this->post['id'] : null;
        if (!$id) {
            $this->returnJson(['status' => 'error', 'code' => 'parameter_missing', 'detail' => 'id']);
            return;
        }

        $sys_manager = new Manage($this->db, $this->prefix);
        $row = $sys_manager->getById('charts', $id);

        if (empty($row)) {
            $this->returnJson(['status' => 'error', 'code' => 'chart_not_found']);
            return;
        }
        if (!$this->assertOwnership($row)) {
            $this->returnJson(['status' => 'error', 'code' => 'chart_access_denied']);
            return;
        }

        try {
            $sys_manager->deleteRow('charts', $id);
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

    /**
     * Builds a parameterised WHERE clause from an optional filter payload.
     * Same pattern as geoface_ctrl::getGeoJson().
     *
     * @param array|null $filter  { search_type, where?, querytext?, adv? }
     * @param string     $tb      Current table name (for ShortSQL prefix extraction)
     * @return array  [$whereClause (string), $whereValues (array)]
     */
    private function buildWhereFromFilter(?array $filter, string $tb): array
    {
        if (empty($filter) || empty($filter['search_type'])) {
            return ['', []];
        }

        $searchType = $filter['search_type'];

        switch ($searchType) {
            case 'shortSql':
                $whereStr = trim($filter['where'] ?? '');
                if ($whereStr === '' || $whereStr === '1') {
                    return ['', []];
                }
                $dblUnder = strpos($tb, '__');
                $tbPrefix = ($dblUnder !== false) ? substr($tb, 0, $dblUnder + 2) : '';
                $parser = new \SQL\ShortSql\ParseShortSql($tbPrefix, $this->cfg);
                $parser->parseAll('@' . $tb . '~?' . $whereStr, true);
                [$whereClause, $whereValues] = $parser->getSql(true);
                return [$whereClause, $whereValues];

            case 'sqlExpert':
                $querytext = trim($filter['querytext'] ?? '');
                if ($querytext === '') {
                    return ['', []];
                }
                return ['(' . $querytext . ')', []];

            case 'advanced':
                // TODO: implement advanced search WHERE building
                return ['', []];

            default:
                return ['', []];
        }
    }

    // ── Deprecated v4 methods ─────────────────────────────────────────────────

    /**
     * @deprecated v5 — replaced by getData()
     */
    public function show_chart_builder()
    {
        $tb = $this->get['tb'];
        $obj_encoded = $this->get['obj_encoded'];

        $this->render('chart', 'show_chart_builder', [
            'tb' => $tb,
            'obj_encoded' => $obj_encoded,
            'fields' => $this->cfg->get("tables.$tb.fields.*.label")
        ]);
    }

    /**
     * @deprecated v5 — replaced by getData()
     */
    public function show_row()
    {
        $this->render('chart', 'show_row', [
            'remove' => $this->get['remove'],
            'flds' => $this->cfg->get("tables.{$this->get['tb']}.fields.*.label"),
        ]);
    }

    /**
     * @deprecated v5 — replaced by getData()
     */
    public function process_chart_data()
    {
        $post = $this->post;

        if (!empty($post['series'])) {
            $group_by = ' GROUP BY ' . $post['series'] . ' ';
        }

        foreach ($post['bar_fld'] as $bar_id => $bar_arr) {
            $bar[] = ' ' . $post['bar_function'][$bar_id] . '(' . implode(') + ' . $post['bar_function'][$bar_id] . '(', $bar_arr) . ') '
                . ($post['bar_name'][$bar_id] ? ' AS ' . $post['bar_name'][$bar_id] . '' : '');
        }

        $sql = 'SELECT ' .
            ($post['series'] ? $post['series'] . ' as series_name, ' : '') .
            implode(', ', $bar) .
            ' FROM ' . $post['tb'] . ' WHERE ' .
            ($post['query'] ? ' ' . base64_decode($post['query']) . ' ' : ' 1=1 ');

        if ($group_by && preg_match('/ORDER BY/', $sql)) {
            $sql = preg_replace('/(.+)ORDER BY(.+)/', '$1 ' . $group_by . ' ORDER BY $2', $sql);
        } else if ($group_by) {
            $sql .= ' ' . $group_by;
        }

        $formatted_data = $this->formatResult($this->db->query($sql));

        $this->render('chart', 'display_chart', [
            'encoded_query' => base64_encode($sql),
            'data'     => $formatted_data['data'],
            'series'   => $formatted_data['series'],
            'ticks'    => $formatted_data['ticks']
        ]);
    }

    /**
     * @deprecated v5 — replaced by saveChart()
     */
    public function save_chart_as()
    {
        $post = $this->post;

        try {
            if (!$post['query_text']) {
                throw new \Exception('No query text to save!');
            }
            if (!$post['name']) {
                $post['name'] = uniqid('chart_');
            }

            $sys_manager = new Manage($this->db, $this->prefix);
            $res = $sys_manager->addRow('charts', [
                'user_id' => \Auth\CurrentUser::id(),
                'name'    => $post['name'],
                'sqltext' => QueryFromRequest::makeSafeStatement(base64_decode($post['query_text'])),
                'date'    => (new \DateTime())->format('Y-m-d H:i:s'),
            ]);

            if ($res) {
                $this->response('ok_save_chart');
            } else {
                throw new \Exception('Save chart query returned false');
            }
        } catch (\Throwable $e) {
            $this->log->error($e);
            $this->response('error_save_chart', 'error');
        }
    }

    /**
     * @deprecated v5 — replaced by deleteChart()
     */
    public function delete_chart()
    {
        $id = $this->get['id'];

        $sys_manager = new Manage($this->db, $this->prefix);
        $res = $sys_manager->deleteRow('charts', $id);

        if ($res) {
            $this->response('ok_chart_erase', 'success');
        } else {
            $this->response('error_chart_erase', 'error');
        }
    }

    /**
     * @deprecated v5 — replaced by getData()
     */
    public function display_chart()
    {
        try {
            $id = $this->get['id'];

            $sys_manager = new Manage($this->db, $this->prefix);
            $chart = $sys_manager->getById('charts', $id);

            $data = $this->db->query($chart['sqltext']);

            $formatted_data = $this->formatResult($data);

            $this->render('chart', 'display_chart', [
                'encoded_query' => base64_encode($chart['sqltext']),
                'data'    => $formatted_data['data'],
                'series'  => $formatted_data['series'],
                'ticks'   => $formatted_data['ticks']
            ]);
        } catch (\Throwable $th) {
            echo \utils::message(\tr::get('chart_id_missing', [$this->get['id']]), 'error', true);
        }
    }

    /**
     * @deprecated v5 — replaced by listCharts()
     */
    public function show_all_charts()
    {
        $sys_manager = new Manage($this->db, $this->prefix);
        $all_charts = $sys_manager->getBySQL('charts', '1=1');

        $this->render('chart', 'show_all_charts', [
            'all_charts' => $all_charts,
            'can_admin' => \utils::canUser('admin'),
        ]);
    }

    /**
     * @deprecated v5 — replaced by getData()
     */
    public function edit_form()
    {
        $id = $this->get['id'];

        $sys_manager = new Manage($this->db, $this->prefix);
        $chart = $sys_manager->getById('charts', $id);

        $this->render('chart', 'edit_form', [
            'chart' => $chart
        ]);
    }

    /**
     * @deprecated v5 — replaced by saveChart()
     */
    public function update_chart()
    {
        $id   = $this->post['id'];
        $name = $this->post['name'];
        $text = $this->post['text'];

        try {
            $sys_manager = new Manage($this->db, $this->prefix);
            $res = $sys_manager->editRow('charts', $id, [
                'name'    => $name,
                'sqltext' => QueryFromRequest::makeSafeStatement($text)
            ]);

            if ($res) {
                $this->response('ok_update_chart');
            } else {
                throw new \Exception('Update query returned false');
            }
        } catch (\Throwable $e) {
            $this->log->error($e);
            $this->response('error_update_chart', 'error');
        }
    }

    /**
     * @deprecated v5 — replaced by getData()
     */
    private function formatResult(array $data): array
    {
        $row = 0;
        $out = [];
        foreach ($data as $id => $one_series) {

            if ($one_series['series_name'] === '') {
                $one_series['series_name'] = \tr::get('no_value');
            }

            $out['series'][$row] = ['label' => $one_series['series_name']];

            unset($one_series['series_name']);

            $column = 0;

            foreach ($one_series as $label => $value) {
                $out['data'][$row][$column] = (int) $value;
                $out['ticks'][$column] = $label;
                $column++;
            }

            $row++;
        }

        return $out;
    }
}
