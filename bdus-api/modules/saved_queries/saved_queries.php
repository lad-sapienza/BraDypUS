<?php
/**
 * @copyright 2007-2025 Julian Bogdani
 * @license AGPL-3.0; see LICENSE
 */

use \DB\System\Manage;

class saved_queries_ctrl extends Controller
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
        $sys_manager = new Manage($this->db, $this->prefix);
        $rows = $sys_manager->getBySQL(
            'queries',
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
            $sys_manager = new Manage($this->db, $this->prefix);
            $newId = $sys_manager->addRow('queries', [
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

            $row = $sys_manager->getById('queries', $newId);
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

        $sys_manager = new Manage($this->db, $this->prefix);
        $row = $sys_manager->getById('queries', $id);

        if (empty($row)) {
            $this->returnJson(['status' => 'error', 'code' => 'query_not_found']);
            return;
        }
        if (!$this->assertOwnership($row)) {
            $this->returnJson(['status' => 'error', 'code' => 'query_access_denied']);
            return;
        }

        try {
            $sys_manager->editRow('queries', $id, ['is_global' => 1]);
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

        $sys_manager = new Manage($this->db, $this->prefix);
        $row = $sys_manager->getById('queries', $id);

        if (empty($row)) {
            $this->returnJson(['status' => 'error', 'code' => 'query_not_found']);
            return;
        }
        if (!$this->assertOwnership($row)) {
            $this->returnJson(['status' => 'error', 'code' => 'query_access_denied']);
            return;
        }

        try {
            $sys_manager->editRow('queries', $id, ['is_global' => 0]);
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

        $sys_manager = new Manage($this->db, $this->prefix);
        $row = $sys_manager->getById('queries', $id);

        if (empty($row)) {
            $this->returnJson(['status' => 'error', 'code' => 'query_not_found']);
            return;
        }
        if (!$this->assertOwnership($row)) {
            $this->returnJson(['status' => 'error', 'code' => 'query_access_denied']);
            return;
        }

        try {
            $sys_manager->deleteRow('queries', $id);
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
            || \utils::canUser('super_admin');
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

    // ── Deprecated v4 methods ─────────────────────────────────────────────────

    /**
     * @deprecated v5 — replaced by listQueries()
     */
    public function getById()
    {
        $id = $this->get['id'];

        $sys_manager = new Manage($this->db, $this->prefix);
        $res = $sys_manager->getById('queries', $id);

        if (!empty($res)) {
            echo json_encode([
                'status' => 'success',
                'tb'     => $res['tb'],
            ]);
        } else {
            echo json_encode(['status' => 'error']);
        }
    }

    /**
     * @deprecated v5 — replaced by listQueries()
     */
    public function showAll()
    {
        $sys_manager = new Manage($this->db, $this->prefix);
        $res = $sys_manager->getBySQL('queries', "user_id = ? OR is_global = ?", [\Auth\CurrentUser::id(), 1]);

        foreach ($res as &$q) {
            $q['tb_label']    = $this->cfg->get("tables.{$q['tb']}.label");
            $q['owned_by_me'] = \Auth\CurrentUser::id() === (int) $q['user_id'];
        }

        $this->render('saved_queries', 'showAll', [
            'saved_queries' => $res
        ]);
    }

    /**
     * @deprecated v5 — replaced by shareQuery()
     */
    public function shareQueryLegacy()
    {
        $id = $this->get['id'];

        $msg = [
            'status' => 'error',
            'text'   => \tr::get('error_sharing_query'),
        ];

        try {
            $sys_manager = new Manage($this->db, $this->prefix);
            $res = $sys_manager->editRow('queries', $id, ['is_global' => 1]);

            if ($res) {
                $msg = [
                    'status' => 'success',
                    'text'   => \tr::get('ok_sharing_query'),
                ];
            }
        } catch (\DB\DBException $e) {
            // Already logged
        } catch (\Throwable $e) {
            $this->log->error($e);
        }

        $this->returnJson($msg);
    }

    /**
     * @deprecated v5 — replaced by unshareQuery()
     */
    public function unShareQueryLegacy()
    {
        $id = $this->get['id'];
        $msg = [
            'status' => 'error',
            'text'   => \tr::get('error_unsharing_query'),
        ];

        try {
            $sys_manager = new Manage($this->db, $this->prefix);
            $res = $sys_manager->editRow('queries', $id, ['is_global' => 0]);

            if ($res) {
                $msg = [
                    'status' => 'success',
                    'text'   => \tr::get('ok_unsharing_query'),
                ];
            }
        } catch (\DB\DBException $e) {
            // do nothing
        } catch (\Throwable $e) {
            $this->log->error($e);
        }

        $this->returnJson($msg);
    }

    /**
     * @deprecated v5 — replaced by deleteQuery()
     */
    public function deleteQueryLegacy()
    {
        $id = $this->get['id'];
        $msg = [
            'status' => 'error',
            'text'   => \tr::get('error_erasing_query'),
        ];

        try {
            $sys_manager = new Manage($this->db, $this->prefix);
            $res = $sys_manager->deleteRow('queries', $id);

            if ($res) {
                $msg = [
                    'status' => 'success',
                    'text'   => \tr::get('ok_erasing_query'),
                ];
            }
        } catch (\DB\DBException $e) {
        } catch (\Throwable $e) {
            $this->log->error($e);
        }

        $this->returnJson($msg);
    }

    /**
     * @deprecated v5 — replaced by saveQuery()
     */
    public function saveQueryLegacy()
    {
        $tb           = $this->get['tb'];
        $name         = $this->get['name'];
        $query_object = $this->post['query_object'];
        $msg = [
            'status' => 'error',
            'text'   => \tr::get('error_saving_query'),
        ];

        try {
            $sys_manager = new Manage($this->db, $this->prefix);
            list($text, $values) = \SQL\SafeQuery::decode($query_object);
            $res = $sys_manager->addRow('queries', [
                'user_id'    => \Auth\CurrentUser::id(),
                'created_at' => time(),
                'name'       => $name,
                'tb'         => $tb,
                'is_global'  => 0,
            ]);

            if ($res) {
                $msg = [
                    'status' => 'success',
                    'text'   => \tr::get('ok_saving_query'),
                ];
            }
        } catch (\DB\DBException $e) {
            // already logged
        } catch (\Throwable $e) {
            $this->log->error($e);
        }

        $this->returnJson($msg);
    }
}
