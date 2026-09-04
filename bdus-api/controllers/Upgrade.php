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

        // Migration record directory — the run log and the verification report
        // land here so the admin can archive them alongside the v4 backup.
        // Best-effort: if it cannot be created the upgrade still proceeds, only
        // the persisted log is skipped.
        $ts        = date('Ymd-His');
        $recordRel = 'migrations/v4v5/' . $ts . '/';
        $recordDir = PROJ_DIR . $recordRel;
        if (!is_dir($recordDir) && !@mkdir($recordDir, 0775, true) && !is_dir($recordDir)) {
            $this->log?->warning("Upgrade: could not create {$recordDir} — the run log will not be persisted");
            $recordDir = null;
        }

        // Tee everything the migration logs (prefix renames, dropped-view DDL,
        // each M0xx, the snapshot path, the verification summary) into
        // migrations/v4v5/<ts>/upgrade.log.
        $teeHandler = null;
        if ($recordDir !== null && $this->log) {
            try {
                $teeHandler = new \Monolog\Handler\StreamHandler($recordDir . 'upgrade.log', \Monolog\Logger::DEBUG);
                $this->log->pushHandler($teeHandler);
            } catch (\Throwable $e) {
                $teeHandler = null;
            }
        }

        try {
            // Rollback snapshot BEFORE mutating anything. If it cannot be
            // written we abort rather than upgrade with no way back.
            try {
                $snapshot = \DB\System\Snapshot::takeProjectSnapshot(PROJ_DIR);
                $this->log?->info("Pre-upgrade snapshot: {$snapshot['archive']}");
            } catch (\Throwable $e) {
                $this->log?->error("Pre-upgrade snapshot failed: " . $e->getMessage());
                $this->returnJson(['status' => 'error', 'code' => 'snapshot_failed']);
                return;
            }

            try {
                Migrate::run($this->db, $this->log);
                $this->log?->info("Major upgrade completed by user {$user['id']}");
            } catch (\Throwable $e) {
                $this->log?->error("Major upgrade failed: " . $e->getMessage());
                $this->returnJson([
                    'status'   => 'error',
                    'code'     => 'upgrade_failed',
                    'snapshot' => basename($snapshot['archive']),
                ]);
                return;
            }

            // Post-upgrade verification is advisory: it never turns a completed
            // upgrade back into a failure (the migrations already committed and
            // cannot be rolled back automatically — the admin decides, snapshot
            // in hand).
            $verify = null;
            try {
                $verify = $this->verifyAfterUpgrade($snapshot['sqlite'], $recordDir, $recordRel, $ts);
            } catch (\Throwable $e) {
                $this->log?->error("Post-upgrade verification could not run: " . $e->getMessage());
            }

            $this->returnJson([
                'status'     => 'success',
                'code'       => 'upgrade_complete',
                'snapshot'   => basename($snapshot['archive']),
                'record_dir' => $recordDir !== null ? $recordRel : null,
                'log'        => ($recordDir !== null && $teeHandler !== null) ? $recordRel . 'upgrade.log' : null,
                'verify'     => $verify,
            ]);
        } finally {
            if ($teeHandler !== null && $this->log) {
                try { $this->log->popHandler(); } catch (\Throwable $e) {}
            }
        }
    }

    /**
     * Runs DB\Verify\MigrationVerifier against the freshly-upgraded app, writes
     * the full report to migrations/v4v5/<ts>/verify.json (or
     * backups/verify-<ts>.json if the record dir is unavailable), and returns a
     * compact summary for the client. Returns null if it could not run.
     *
     * @param string|null $baselinePath  pre-upgrade SQLite copy, if the
     *                                    snapshot managed to make one
     * @param string|null $recordDir     absolute migrations/v4v5/<ts>/ dir, or null
     * @param string      $recordRel     the same, relative to PROJ_DIR (trailing /)
     * @param string      $ts            timestamp used for the fallback filename
     */
    private function verifyAfterUpgrade(?string $baselinePath, ?string $recordDir, string $recordRel, string $ts): ?array
    {
        if (!defined('PROJ_DIR') || !$this->db) {
            return null;
        }

        $baseline = null;
        if ($baselinePath && is_file($baselinePath)) {
            try {
                $baseline = new \DB\DB('baseline_v4', ['db_engine' => 'sqlite', 'db_path' => $baselinePath]);
            } catch (\Throwable $e) {
                $this->log?->warning("verify: could not open baseline — " . $e->getMessage());
            }
        }

        $cfg = null;
        try {
            $cfg = new \Config\Config(new \Adbar\Dot(), PROJ_DIR, $this->db);
        } catch (\Throwable $e) {
            $this->log?->debug("verify: config load skipped — " . $e->getMessage());
        }

        $report = (new \DB\Verify\MigrationVerifier($this->db, [
            'baseline' => $baseline,
            'cfg'      => $cfg,
            'projDir'  => PROJ_DIR,
        ]))->run();

        [$absPath, $relPath] = $recordDir !== null
            ? [$recordDir . 'verify.json', $recordRel . 'verify.json']
            : [PROJ_DIR . 'backups/verify-' . $ts . '.json', 'backups/verify-' . $ts . '.json'];
        @file_put_contents(
            $absPath,
            json_encode($report, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES)
        );
        $this->log?->info('Post-upgrade verification: ' . json_encode($report['summary']));

        $failed = [];
        $warnings = [];
        foreach ($report['checks'] as $c) {
            if ($c['status'] === 'fail') {
                $failed[] = $c['title'];
            } elseif ($c['status'] === 'warn') {
                $warnings[] = $c['title'];
            }
        }

        return [
            'summary'  => $report['summary'],
            'failed'   => $failed,
            'warnings' => $warnings,
            'report'   => $relPath,
        ];
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
