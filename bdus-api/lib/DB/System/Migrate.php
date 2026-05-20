<?php

/**
 * @copyright 2007-2025 Julian Bogdani
 * @license AGPL-3.0; see LICENSE
 */

namespace DB\System;

use DB\DBInterface;
use DB\System\Manage;
use DB\System\Migrations\M001_AddUserTablePrivs;
use DB\System\Migrations\M002_CreateFileLinks;
use DB\System\Migrations\M003_RefactorQueriesTable;
use DB\System\Migrations\M004_RefactorChartsTable;
use DB\System\Migrations\M005_CreateApiKeys;
use DB\System\Migrations\M006_AddApiKeyPrivilege;
use Monolog\Logger;

/**
 * Schema migration runner.
 *
 * Migrations are applied at login time (once per session) and are idempotent:
 * each migration is tracked by name in the {prefix}migrations table and is
 * never executed twice.
 *
 * ## Adding a new migration
 * 1. Create a class in lib/DB/System/Migrations/ following the M00N_* naming.
 * 2. Add a public const NAME and a static run(Manage $manage) method.
 * 3. Register the class in ALL_MIGRATIONS below (order matters).
 *
 * ## Engine compatibility
 * All DDL is delegated to Manage::createTable(), which already handles
 * SQLite / MySQL / PostgreSQL differences. Data migrations should use
 * the DB class so the same abstraction is in place.
 */
class Migrate
{
    /**
     * Ordered list of all migrations.
     * New migrations are appended at the end — never reorder.
     */
    private const ALL_MIGRATIONS = [
        M001_AddUserTablePrivs::class,
        M002_CreateFileLinks::class,
        M003_RefactorQueriesTable::class,
        M004_RefactorChartsTable::class,
        M005_CreateApiKeys::class,
        M006_AddApiKeyPrivilege::class,
    ];

    /**
     * One-time upgrade: if tables are still named with the legacy APP__ prefix,
     * rename them all (user tables + system tables) and update tables.json.
     *
     * This is called from App::start() BEFORE Config::__construct() reads
     * tables.json, so legacy apps can boot without crashing.
     * It is also called internally at the start of run() before the migration
     * bootstrap, so the migrations tracking table itself is renamed first.
     *
     * Safe to call multiple times: if old-prefix tables are not found, it is a no-op.
     */
    public static function maybeRemovePrefix(DBInterface $db, Logger $log = null): void
    {
        if (!defined('APP') || !defined('PROJ_DIR')) {
            return;
        }

        $oldPrefix        = APP . '__';
        $oldMigrTable     = $oldPrefix . 'migrations';
        $driver           = $db->getEngine();

        // Only SQLite is supported for this auto-migration (the user confirmed all apps are SQLite).
        // On other engines the DBA should run the rename manually.
        if ($driver !== 'sqlite') {
            $log?->warning("maybeRemovePrefix: auto-rename not supported for engine {$driver}; please rename tables manually.");
            return;
        }

        // Check whether the old migrations table still exists.
        $exists = $db->query(
            "SELECT COUNT(*) AS cnt FROM sqlite_master WHERE type='table' AND name=?",
            [$oldMigrTable],
            'read'
        );
        if (!$exists || (int)($exists[0]['cnt'] ?? 0) === 0) {
            return; // Already migrated or fresh install.
        }

        $log?->info("Migrate: found legacy prefix tables (prefix={$oldPrefix}); renaming…");

        // Fetch every table that starts with the old prefix.
        $tables = $db->query(
            "SELECT name FROM sqlite_master WHERE type='table' AND name LIKE ?",
            [$oldPrefix . '%'],
            'read'
        ) ?: [];

        foreach ($tables as $row) {
            $oldName = $row['name'];
            $newName = substr($oldName, strlen($oldPrefix));

            // Skip if destination already exists (shouldn't happen, but be safe).
            $destExists = $db->query(
                "SELECT COUNT(*) AS cnt FROM sqlite_master WHERE type='table' AND name=?",
                [$newName],
                'read'
            );
            if ((int)($destExists[0]['cnt'] ?? 0) > 0) {
                $log?->warning("maybeRemovePrefix: skipping rename {$oldName} → {$newName} (destination exists)");
                continue;
            }

            try {
                $db->exec("ALTER TABLE \"{$oldName}\" RENAME TO \"{$newName}\"");
                $log?->info("Migrate: renamed {$oldName} → {$newName}");
            } catch (\Throwable $e) {
                $log?->error("maybeRemovePrefix: failed to rename {$oldName}: " . $e->getMessage());
            }
        }

        // Update tables.json: strip prefix from all table names and any nested references.
        $cfgPath = PROJ_DIR . 'cfg/tables.json';
        if (file_exists($cfgPath)) {
            $data = json_decode(file_get_contents($cfgPath), true);
            if ($data && isset($data['tables'])) {
                array_walk($data['tables'], function (&$tb) use ($oldPrefix) {
                    // Table name
                    if (str_starts_with($tb['name'], $oldPrefix)) {
                        $tb['name'] = substr($tb['name'], strlen($oldPrefix));
                    }
                    // Plugin references
                    if (isset($tb['plugin']) && is_array($tb['plugin'])) {
                        $tb['plugin'] = array_map(
                            fn($p) => str_starts_with($p, $oldPrefix) ? substr($p, strlen($oldPrefix)) : $p,
                            $tb['plugin']
                        );
                    }
                    // Link other_tb references
                    if (isset($tb['link']) && is_array($tb['link'])) {
                        foreach ($tb['link'] as &$link) {
                            if (isset($link['other_tb']) && str_starts_with($link['other_tb'], $oldPrefix)) {
                                $link['other_tb'] = substr($link['other_tb'], strlen($oldPrefix));
                            }
                        }
                        unset($link);
                    }
                });
                file_put_contents(
                    $cfgPath,
                    json_encode(['tables' => $data['tables']], JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE)
                );
                $log?->info("Migrate: updated tables.json (prefix stripped)");
            }
        }
    }

    /**
     * Runs all pending migrations.
     * Called once after successful login.
     *
     * @param DBInterface $db
     * @param string $prefix  Kept for backward compatibility; ignored (always '' after v5 prefix removal).
     */
    public static function run(DBInterface $db, string $prefix, Logger $log = null): void
    {
        // Pre-flight: if this is an existing app still using the legacy APP__ prefix,
        // rename all tables and update config before the normal migration loop starts.
        self::maybeRemovePrefix($db, $log);

        $manage = new Manage($db, $prefix);

        // Bootstrap: ensure the migrations tracking table exists.
        // This is the only table created outside the normal migration flow.
        $manage->createTable('migrations');

        // Load already-applied migration names.
        $applied = $db->query(
            "SELECT name FROM {$prefix}migrations",
            [],
            'read'
        );
        $applied = $applied ? array_column($applied, 'name') : [];

        // Run each pending migration in order.
        $pending = 0;
        foreach (self::ALL_MIGRATIONS as $class) {
            if (in_array($class::NAME, $applied, true)) {
                continue;
            }

            $pending++;
            $name = $class::NAME;
            $log?->info("DB migration: applying $name");

            try {
                $class::run($manage);
            } catch (\Throwable $e) {
                $log?->error("DB migration failed: $name — " . $e->getMessage());
                throw $e;
            }

            $db->query(
                "INSERT INTO {$prefix}migrations (name, applied_at) VALUES (?, ?)",
                [$name, time()],
                'boolean'
            );

            $log?->info("DB migration: $name applied successfully");
        }

        if ($pending === 0) {
            $log?->debug("DB migrations: schema up to date ($prefix)");
        }
    }
}
