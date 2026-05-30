<?php

namespace Bdus\Controllers;

/**
 * @copyright 2007-2025 Julian Bogdani
 * @license AGPL-3.0; see LICENSE
 */

use \DB\System\Manage;

class SavedQueries extends \Bdus\Controller
{
    // ── v5 methods ────────────────────────────────────────────────────────────

    /**
     * GET — lists all queries visible to the current user.
     *
     * Returns all rows where user_id = current user OR is_global = 1.
     * Each row is enriched with:
     *   - tb_label  : human-readable table label from config
     *   - owned_by_me : bool, whether the current user owns the row
     *   - query     : decoded JSON payload (array), or null
     *
     * Response: { status: 'success', queries: [...] }
     */
    public function listQueries(): void
    {
        $sys_manager = new Manage($this->db);
        $rows = $sys_manager->getBySQL(
            'bdus_queries',
            'user_id = ? OR is_global = ?',
            [\Auth\CurrentUser::id(), 1]
        );

        $result = [];
        foreach ($rows as $row) {
            $result[] = $this->enrichRow($row);
        }

        $this->returnJson(['status' => 'success', 'queries' => $result]);
    }

    /**
     * POST { name, tb, query: {...} } — saves a new query for the current user.
     *
     * Response: { status: 'success', code: 'ok_saved_query', query: {...} }
     */
    public function saveQuery(): void
    {
        $name  = $this->post['name']  ?? null;
        $tb    = $this->post['tb']    ?? null;
        $query = $this->post['query'] ?? null;

        if (empty($name)) {
            $this->returnJson(['status' => 'error', 'code' => 'parameter_missing', 'detail' => 'name']);
            return;
        }
        if (empty($tb)) {
            $this->returnJson(['status' => 'error', 'code' => 'parameter_missing', 'detail' => 'tb']);
            return;
        }

        $queryJson = is_array($query) ? json_encode($query) : ($query ?? null);

        try {
            $sys_manager = new Manage($this->db);
            $newId = $sys_manager->addRow('bdus_queries', [
                'user_id'    => \Auth\CurrentUser::id(),
                'created_at' => time(),
                'name'       => $name,
                'tb'         => $tb,
                'query'      => $queryJson,
                'is_global'  => 0,
            ]);

            if (!$newId) {
                $this->returnJson(['status' => 'error', 'code' => 'error_saving_query']);
                return;
            }

            $row = $sys_manager->getById('bdus_queries', $newId);
            $this->returnJson([
                'status' => 'success',
                'code'   => 'ok_saved_query',
                'query'  => $this->enrichRow($row),
            ]);

        } catch (\Throwable $e) {
            $this->log->error($e);
            $this->returnJson(['status' => 'error', 'code' => 'error_saving_query']);
        }
    }

    /**
     * POST { id } — marks a query as globally shared (is_global = 1).
     *
     * Only the owner or a super_admin may share a query.
     *
     * Response: { status: 'success', code: 'ok_sharing_query' }
     */
    public function shareQuery(): void
    {
        $id = isset($this->post['id']) ? (int) $this->post['id'] : null;
        if (!$id) {
            $this->returnJson(['status' => 'error', 'code' => 'parameter_missing', 'detail' => 'id']);
            return;
        }

        $sys_manager = new Manage($this->db);
        $row = $sys_manager->getById('bdus_queries', $id);

        if (empty($row)) {
            $this->returnJson(['status' => 'error', 'code' => 'query_not_found']);
            return;
        }
        if (!$this->assertOwnership($row)) {
            $this->returnJson(['status' => 'error', 'code' => 'query_access_denied']);
            return;
        }

        try {
            $sys_manager->editRow('bdus_queries', $id, ['is_global' => 1]);
            $this->returnJson(['status' => 'success', 'code' => 'ok_sharing_query']);
        } catch (\Throwable $e) {
            $this->log->error($e);
            $this->returnJson(['status' => 'error', 'code' => 'error_sharing_query']);
        }
    }

    /**
     * POST { id } — removes the global share flag (is_global = 0).
     *
     * Only the owner or a super_admin may unshare a query.
     *
     * Response: { status: 'success', code: 'ok_unsharing_query' }
     */
    public function unshareQuery(): void
    {
        $id = isset($this->post['id']) ? (int) $this->post['id'] : null;
        if (!$id) {
            $this->returnJson(['status' => 'error', 'code' => 'parameter_missing', 'detail' => 'id']);
            return;
        }

        $sys_manager = new Manage($this->db);
        $row = $sys_manager->getById('bdus_queries', $id);

        if (empty($row)) {
            $this->returnJson(['status' => 'error', 'code' => 'query_not_found']);
            return;
        }
        if (!$this->assertOwnership($row)) {
            $this->returnJson(['status' => 'error', 'code' => 'query_access_denied']);
            return;
        }

        try {
            $sys_manager->editRow('bdus_queries', $id, ['is_global' => 0]);
            $this->returnJson(['status' => 'success', 'code' => 'ok_unsharing_query']);
        } catch (\Throwable $e) {
            $this->log->error($e);
            $this->returnJson(['status' => 'error', 'code' => 'error_unsharing_query']);
        }
    }

    /**
     * POST { id } — permanently deletes a saved query.
     *
     * Only the owner or a super_admin may delete.
     *
     * Response: { status: 'success', code: 'ok_erasing_query' }
     */
    public function deleteQuery(): void
    {
        $id = isset($this->post['id']) ? (int) $this->post['id'] : null;
        if (!$id) {
            $this->returnJson(['status' => 'error', 'code' => 'parameter_missing', 'detail' => 'id']);
            return;
        }

        $sys_manager = new Manage($this->db);
        $row = $sys_manager->getById('bdus_queries', $id);

        if (empty($row)) {
            $this->returnJson(['status' => 'error', 'code' => 'query_not_found']);
            return;
        }
        if (!$this->assertOwnership($row)) {
            $this->returnJson(['status' => 'error', 'code' => 'query_access_denied']);
            return;
        }

        try {
            $sys_manager->deleteRow('bdus_queries', $id);
            $this->returnJson(['status' => 'success', 'code' => 'ok_erasing_query']);
        } catch (\Throwable $e) {
            $this->log->error($e);
            $this->returnJson(['status' => 'error', 'code' => 'error_erasing_query']);
        }
    }

    // ── Private helpers ───────────────────────────────────────────────────────

    /**
     * Returns true when the current user owns the row OR has super_admin privilege.
     */
    private function assertOwnership(array $row): bool
    {
        return (int) $row['user_id'] === \Auth\CurrentUser::id()
            || \Auth\Authorization::can('super_admin');
    }

    /**
     * Enriches a raw queries row with virtual fields used by the frontend.
     */
    private function enrichRow(array $row): array
    {
        $row['tb_label']    = $this->cfg->get("tables.{$row['tb']}.label") ?? $row['tb'];
        $row['owned_by_me'] = (int) $row['user_id'] === \Auth\CurrentUser::id();
        $row['query']       = !empty($row['query'])
            ? json_decode($row['query'], true)
            : null;
        return $row;
    }

}
