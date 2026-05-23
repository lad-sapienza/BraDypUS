<?php

/**
 * @copyright 2007-2025 Julian Bogdani
 * @license AGPL-3.0; see LICENSE
 */

namespace DB\System\Migrations;

use DB\System\Manage;

/**
 * Moves app-level runtime settings from config.json into the new
 * bdus_cfg_app system table, and removes obsolete files from the
 * project directory.
 *
 * What moves to DB:
 *   config.json: status, maxImageSize  →  bdus_cfg_app.status / max_image_size
 *   welcome.md (or welcome.html)       →  bdus_cfg_app.welcome
 *
 * What stays in config.json (bootstrap only):
 *   definition, db_engine, db_host, db_port, db_name, db_username, db_password
 *
 * What is removed from disk:
 *   welcome.md / welcome.html  (content migrated to DB)
 *   history.log                (unused fossil)
 *   templates/                 (Twig templates replaced by Vue SPA in v5)
 *
 * Idempotency:
 *   If bdus_cfg_app already has a row (id = 1) the migration is skipped.
 */
class M019_AppSettingsToDB
{
    public const NAME = 'M019_app_settings_to_db';

    /** Keys kept in config.json after this migration. */
    private const BOOTSTRAP_KEYS = [
        'definition',
        'db_engine', 'db_host', 'db_port',
        'db_name', 'db_username', 'db_password',
    ];

    public static function run(Manage $manage, ?string $projDir = null): void
    {
        $root = $projDir ?? (defined('PROJ_DIR') ? PROJ_DIR : null);
        if ($root === null) {
            return;
        }
        if (!str_ends_with($root, '/')) {
            $root .= '/';
        }

        $db = $manage->getDb();

        // ── Idempotency check ────────────────────────────────────────────────
        try {
            $existing = $db->query(
                'SELECT id FROM bdus_cfg_app WHERE id = 1',
                [],
                'read'
            );
            if (!empty($existing)) {
                return; // Already migrated.
            }
        } catch (\Throwable) {
            // Table does not exist yet — proceed with migration.
        }

        // ── 1. Create bdus_cfg_app table ─────────────────────────────────────
        $manage->createTable('bdus_cfg_app');

        // ── 2. Read current values from config.json ──────────────────────────
        $cfgFile = null;
        foreach ([
            $root . 'config.json',
            $root . 'cfg/config.json',
            $root . 'cfg/app_data.json',
        ] as $candidate) {
            if (file_exists($candidate)) {
                $cfgFile = $candidate;
                break;
            }
        }

        $cfg    = $cfgFile ? (json_decode(file_get_contents($cfgFile), true) ?: []) : [];
        $status = $cfg['status']       ?? 'on';
        $maxPx  = (int) ($cfg['maxImageSize'] ?? 0);

        // ── 3. Read welcome text from file (if present) ───────────────────────
        $welcome = '';
        foreach (['welcome.md', 'welcome.html'] as $name) {
            $path = $root . $name;
            if (file_exists($path)) {
                $welcome = file_get_contents($path) ?: '';
                break;
            }
        }

        // ── 4. Seed bdus_cfg_app ──────────────────────────────────────────────
        $db->query(
            'INSERT INTO bdus_cfg_app (id, status, max_image_size, welcome) VALUES (?, ?, ?, ?)',
            [1, $status, $maxPx, $welcome],
            'boolean'
        );

        // ── 5. Rewrite config.json with bootstrap fields only ─────────────────
        if ($cfgFile !== null) {
            $bootstrap = array_intersect_key($cfg, array_flip(self::BOOTSTRAP_KEYS));
            file_put_contents($cfgFile, json_encode($bootstrap, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE));
        }

        // ── 6. Remove obsolete files from the project directory ───────────────
        // welcome.md / welcome.html — content is now in bdus_cfg_app.welcome
        foreach (['welcome.md', 'welcome.html'] as $name) {
            @unlink($root . $name);
        }

        // history.log — never used in v5; kept only for legacy compatibility
        @unlink($root . 'history.log');

        // templates/*.twig — server-side Twig rendering replaced by Vue SPA
        $tplDir = $root . 'templates/';
        if (is_dir($tplDir)) {
            foreach (glob($tplDir . '*.twig') ?: [] as $twig) {
                @unlink($twig);
            }
            // Remove the directory itself if now empty
            @rmdir($tplDir);
        }
    }
}
