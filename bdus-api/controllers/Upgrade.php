<?php

/**
 * @copyright 2007-2025 Julian Bogdani
 * @license AGPL-3.0; see LICENSE
 */

namespace Bdus\Controllers;

use DB\System\Migrate;

/**
 * Upgrade assistant — handles both major (pre-login) and minor (post-login)
 * schema upgrades.
 *
 * Major upgrade (v4 → v5):
 *   - Detected by App::start() which sets BDUS_MAJOR_UPGRADE = true.
 *   - Dispatcher blocks all routes except these endpoints and Login::listApps.
 *   - No JWT is required: auth is done directly against bdus_users.
 *   - Only superadmins (privilege = 1) may trigger the upgrade.
 *
 * Minor upgrade (v5.x → v5.y):
 *   - Detected after a successful login when an admin user has pending migrations.
 *   - Login::auth() returns the token plus an 'upgrade' payload.
 *   - The frontend shows a confirmation screen; this endpoint runs the migrations.
 */
class Upgrade extends \Bdus\Controller
{
    /**
     * Returns the current upgrade state for the selected app.
     *
     * No authentication required.
     * GET /api/upgrade/status?app=<name>
     *
     * Response:
     *   { status: 'success', type: 'major' | 'minor' | null, pending?: string[] }
     */
    public function status(): void
    {
        if (!$this->db) {
            $this->returnJson(['status' => 'success', 'type' => null]);
            return;
        }

        if (defined('BDUS_MAJOR_UPGRADE') && BDUS_MAJOR_UPGRADE) {
            $this->returnJson(['status' => 'success', 'type' => 'major']);
            return;
        }

        $pending = Migrate::listPending($this->db);
        if (!empty($pending)) {
            $affectsFiles = !empty(array_intersect($pending, Migrate::FILE_AFFECTING_MIGRATIONS));
            $this->returnJson([
                'status'        => 'success',
                'type'          => 'minor',
                'pending'       => $pending,
                'affects_files' => $affectsFiles,
            ]);
            return;
        }

        $this->returnJson(['status' => 'success', 'type' => null]);
    }

    /**
     * Authenticate a superadmin and run major migrations.
     *
     * No JWT required. Only available when BDUS_MAJOR_UPGRADE is true
     * (enforced by the Dispatcher gate). Only SQLite is supported.
     *
     * POST /api/upgrade/major
     * Body: { email: string, password: string }
     *
     * Response:
     *   { status: 'success', code: 'upgrade_complete' }
     *   { status: 'error',   code: 'superadmin_auth_failed' | 'upgrade_failed' | … }
     */
    public function runMajor(): void
    {
        if (!defined('BDUS_MAJOR_UPGRADE') || !BDUS_MAJOR_UPGRADE) {
            $this->returnJson(['status' => 'error', 'code' => 'no_major_upgrade_needed']);
            return;
        }

        if (!$this->db || $this->db->getEngine() !== 'sqlite') {
            $this->returnJson(['status' => 'error', 'code' => 'major_upgrade_sqlite_only']);
            return;
        }

        $email    = trim($this->post['email'] ?? '');
        $password = $this->post['password'] ?? '';

        if (!filter_var($email, FILTER_VALIDATE_EMAIL) || empty($password)) {
            $this->returnJson(['status' => 'error', 'code' => 'email_password_needed']);
            return;
        }

        // Direct credential check against bdus_users — no JWT involved.
        // Only superadmins (privilege = 1) may authorise a major upgrade.
        $rows = $this->db->query(
            "SELECT id, password FROM bdus_users WHERE email = ? AND privilege = 1 LIMIT 1",
            [$email],
            'read'
        );
        $user = $rows[0] ?? null;

        if (!$user || !\Auth\Password::verify($password, $user['password'])) {
            $this->returnJson(['status' => 'error', 'code' => 'superadmin_auth_failed']);
            return;
        }

        try {
            Migrate::run($this->db, $this->log);
            $this->log?->info("Major upgrade completed by user {$user['id']}");
            $this->returnJson(['status' => 'success', 'code' => 'upgrade_complete']);
        } catch (\Throwable $e) {
            $this->log?->error("Major upgrade failed: " . $e->getMessage());
            $this->returnJson(['status' => 'error', 'code' => 'upgrade_failed']);
        }
    }

    /**
     * Run pending minor migrations.
     *
     * Requires a valid JWT with admin privilege (≤ 10).
     * Called after the admin confirms the upgrade on the frontend.
     *
     * POST /api/upgrade/minor
     *
     * Response:
     *   { status: 'success', code: 'upgrade_complete', applied: string[] }
     *   { status: 'error',   code: 'upgrade_failed' }
     */
    public function runMinor(): void
    {
        if (!\Auth\Authorization::can('admin')) {
            $this->returnJson(['status' => 'error', 'code' => 'not_enough_privilege']);
            return;
        }

        $pending = Migrate::listPending($this->db);

        try {
            Migrate::run($this->db, $this->log);
            $this->log?->info("Minor upgrade applied by user " . \Auth\CurrentUser::id());
            $this->returnJson(['status' => 'success', 'code' => 'upgrade_complete', 'applied' => $pending]);
        } catch (\Throwable $e) {
            $this->log?->error("Minor upgrade failed: " . $e->getMessage());
            $this->returnJson(['status' => 'error', 'code' => 'upgrade_failed']);
        }
    }
}
