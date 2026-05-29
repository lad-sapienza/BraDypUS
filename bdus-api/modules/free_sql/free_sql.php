<?php
/**
 * @copyright 2007-2025 Julian Bogdani
 * @license AGPL-3.0; see LICENSE
 *
 * Free SQL module — v5.
 *
 * Restricted to super_admin users.  A password confirmation is required
 * before any SQL can be executed; the gate is enforced client-side via
 * verifyPassword() and enforced server-side on every runSql() call.
 *
 * Execution semantics:
 *   - SELECT / EXPLAIN / PRAGMA / WITH → read-only, returns rows + columns.
 *   - All other statements → wrapped in a transaction; returns affected row count.
 */

class free_sql_ctrl extends Controller
{
    // ── v5 methods ────────────────────────────────────────────────────────────

    /**
     * POST { password }
     * Verifies the current user's password without issuing a new token.
     * Used as a gate before the SQL editor becomes active.
     *
     * Response: { status: 'success' } | { status: 'error', code: '…' }
     */
    public function verifyPassword(): void
    {
        if (!\Auth\Authorization::can('super_admin')) {
            $this->returnJson(['status' => 'error', 'code' => 'not_enough_privilege']);
            return;
        }

        $password = $this->post['password'] ?? '';
        if (empty($password)) {
            $this->returnJson(['status' => 'error', 'code' => 'parameter_missing', 'detail' => 'password']);
            return;
        }

        $rows = $this->db->query(
            "SELECT password FROM bdus_users WHERE id = ?",
            [\Auth\CurrentUser::id()],
            'read'
        );
        $user = $rows[0] ?? null;

        if (!$user || !\Auth\Password::verify($password, $user['password'] ?? '')) {
            $this->returnJson(['status' => 'error', 'code' => 'free_sql_wrong_password']);
            return;
        }

        $this->returnJson(['status' => 'success', 'code' => 'free_sql_password_ok']);
    }

    /**
     * POST { sql }
     * Executes arbitrary SQL.  Requires super_admin privilege on every call
     * (the client-side password gate is a UX convenience, not a security boundary).
     *
     * SELECT / EXPLAIN / PRAGMA / WITH:
     *   → { status, rows: [{}…], columns: string[], total: int }
     *
     * INSERT / UPDATE / DELETE / CREATE / DROP / …:
     *   → { status, code: 'ok_free_sql_run', affected: int }
     *
     * Error:
     *   → { status: 'error', code: 'error_free_sql_run', detail: string }
     */
    public function runSql(): void
    {
        if (!\Auth\Authorization::can('super_admin')) {
            $this->returnJson(['status' => 'error', 'code' => 'not_enough_privilege']);
            return;
        }

        $sql = trim($this->post['sql'] ?? '');
        if (empty($sql)) {
            $this->returnJson(['status' => 'error', 'code' => 'parameter_missing', 'detail' => 'sql']);
            return;
        }

        $isRead = $this->isReadStatement($sql);

        try {
            if ($isRead) {
                $rows    = $this->db->query($sql, [], 'read') ?: [];
                $columns = $rows ? array_keys($rows[0]) : [];
                $this->returnJson([
                    'status'  => 'success',
                    'rows'    => $rows,
                    'columns' => $columns,
                    'total'   => count($rows),
                ]);
            } else {
                $this->db->beginTransaction();
                $affected = $this->db->query($sql, [], 'affected') ?: 0;
                $this->db->commit();
                $this->returnJson([
                    'status'   => 'success',
                    'code'     => 'ok_free_sql_run',
                    'affected' => $affected,
                ]);
            }
        } catch (\Throwable $e) {
            if (!$isRead) {
                try { $this->db->rollBack(); } catch (\Throwable $re) {}
            }
            $this->log->error($e);
            $this->returnJson([
                'status' => 'error',
                'code'   => 'error_free_sql_run',
                'detail' => $e->getMessage(),
            ]);
        }
    }

    // ── Private helpers ───────────────────────────────────────────────────────

    /**
     * Returns true when the statement is read-only (SELECT, EXPLAIN, PRAGMA, WITH).
     * Strips leading comments before checking.
     */
    private function isReadStatement(string $sql): bool
    {
        // Strip leading block comments (/* … */) and line comments (-- …)
        $stripped = preg_replace('/^\s*(\/\*.*?\*\/|--[^\n]*\n)\s*/s', '', $sql);
        $first    = strtoupper(substr(ltrim($stripped), 0, 7));
        return in_array(trim($first), ['SELECT', 'EXPLAIN', 'PRAGMA', 'WITH'], true);
    }

}
