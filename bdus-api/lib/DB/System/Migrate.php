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
use DB\System\Migrations\M007_RepairFileLinks;
use DB\System\Migrations\M008_AddBdusPrefix;
use DB\System\Migrations\M009_AddVersionOperation;
use DB\System\Migrations\M010_FixVersionsSchema;
use DB\System\Migrations\M011_ConfigToDb;
use DB\System\Migrations\M012_AddCfgTablesExtra;
use DB\System\Migrations\M013_CreateCfgRelations;
use DB\System\Migrations\M014_GeofaceConfigToDb;
use DB\System\Migrations\M015_DeleteCfgJsonFiles;
use DB\System\Migrations\M016_RenameAppDataJson;
use DB\System\Migrations\M017_CleanupCfgDir;
use DB\System\Migrations\M018_MoveConfigToRoot;
use DB\System\Migrations\M019_AppSettingsToDB;
use DB\System\Migrations\M020_DeduplicateRelations;
use DB\System\Migrations\M021_FixPluginOf;
use DB\System\Migrations\M022_AddOAuthToUsers;
use DB\System\Migrations\M023_ZoteroTables;
use DB\System\Migrations\M024_DropLegacyColumns;
use DB\System\Migrations\M025_AddColorToCfgApp;
use DB\System\Migrations\M026_RefactorCfgRelations;
use DB\System\Migrations\M027_CreateCfgIndexes;
use DB\System\Migrations\M028_AddTokenVersionToUsers;
use DB\System\Migrations\M029_AddLabelToUserlinks;
use Monolog\Logger;

/**
 * Schema migration runner.
 *
 * Migrations are applied at login time (once per session) and are idempotent:
 * each migration is tracked by name in the bdus_migrations table and is
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
    public const ALL_MIGRATIONS = [
        M001_AddUserTablePrivs::class,
        M002_CreateFileLinks::class,
        M003_RefactorQueriesTable::class,
        M004_RefactorChartsTable::class,
        M005_CreateApiKeys::class,
        M006_AddApiKeyPrivilege::class,
        M007_RepairFileLinks::class,
        M008_AddBdusPrefix::class,
        M009_AddVersionOperation::class,
        M010_FixVersionsSchema::class,
        M011_ConfigToDb::class,
        M012_AddCfgTablesExtra::class,
        M013_CreateCfgRelations::class,
        M014_GeofaceConfigToDb::class,
        M015_DeleteCfgJsonFiles::class,
        M016_RenameAppDataJson::class,
        M017_CleanupCfgDir::class,
        M018_MoveConfigToRoot::class,
        M019_AppSettingsToDB::class,
        M020_DeduplicateRelations::class,
        M021_FixPluginOf::class,
        M022_AddOAuthToUsers::class,
        M023_ZoteroTables::class,
        M024_DropLegacyColumns::class,
        M025_AddColorToCfgApp::class,
        M026_RefactorCfgRelations::class,
        M027_CreateCfgIndexes::class,
        M028_AddTokenVersionToUsers::class,
        M029_AddLabelToUserlinks::class,
    ];

    /**
     * One-time upgrade: rename all bare system tables to bdus_* names.
     *
     * Must run from App::start() BEFORE routing so that login (and every other
     * request) can find bdus_users, bdus_charts, etc. from the very first boot
     * after a code upgrade.  M008 does the same work inside the migration loop
     * but that runs only after a successful login — too late for auth itself.
     *
     * Idempotent: each rename is guarded by checking the old name exists and
     * the new name does not. Safe to call on every request.
     *
     * @param DBInterface $db
     * @param Logger|null $log
     */
    public static function maybeAddBdusPrefix(DBInterface $db, Logger $log = null): void
    {
        if ($db->getEngine() !== 'sqlite') {
            return; // Only SQLite supported; other engines need manual DBA rename.
        }

        // System tables to rename (bare → bdus_). 'migrations' is handled first.
        $tables = [
            'migrations'       => 'bdus_migrations',
            'api_keys'         => 'bdus_api_keys',
            'charts'           => 'bdus_charts',
            'file_links'       => 'bdus_file_links',
            'files'            => 'bdus_files',
            'geodata'          => 'bdus_geodata',
            'log'              => 'bdus_log',
            'queries'          => 'bdus_queries',
            'rs'               => 'bdus_rs',
            'userlinks'        => 'bdus_userlinks',
            'users'            => 'bdus_users',
            'user_table_privs' => 'bdus_user_table_privs',
            'versions'         => 'bdus_versions',
            'vocabularies'     => 'bdus_vocabularies',
        ];

        foreach ($tables as $old => $new) {
            $oldExists = $db->query(
                "SELECT COUNT(*) AS cnt FROM sqlite_master WHERE type='table' AND name=?",
                [$old], 'read'
            );
            if ((int)($oldExists[0]['cnt'] ?? 0) === 0) {
                continue; // Already renamed or never existed under bare name.
            }
            $newExists = $db->query(
                "SELECT COUNT(*) AS cnt FROM sqlite_master WHERE type='table' AND name=?",
                [$new], 'read'
            );
            if ((int)($newExists[0]['cnt'] ?? 0) > 0) {
                continue; // Already migrated.
            }
            try {
                $db->exec("ALTER TABLE \"{$old}\" RENAME TO \"{$new}\"");
                $log?->info("Migrate: renamed {$old} → {$new}");
            } catch (\Throwable $e) {
                $log?->error("maybeAddBdusPrefix: failed to rename {$old}: " . $e->getMessage());
            }
        }
    }

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

        $oldPrefix    = APP . '__';
        $driver       = $db->getEngine();

        // ── 1. Strip prefix from tables.json (config side) ───────────────────
        // This check is independent of the DB: if tables.json still references
        // prefixed table names (e.g. after a manual DB rename), fix it now.
        // This runs on every boot but is a no-op once the prefix is gone.
        self::maybeRemovePrefixFromConfig($oldPrefix, $log);

        // ── 1b. Strip prefix from per-table field config files ───────────────
        // Individual table JSON files (e.g. manuscripts.json) may contain
        // id_from_tb and get_values_from_tb properties that reference other
        // tables by their prefixed names. Fix them before Config loads.
        self::maybeRemovePrefixFromFieldConfigs($oldPrefix, $log);

        // ── 1c. Strip prefix from userlinks data ─────────────────────────────
        // The userlinks table stores table names as text values in tb_one/tb_two.
        // These must be updated before any migration (especially M002) reads them.
        // Idempotent: if the data is already clean, the UPDATE affects 0 rows.
        self::maybeRemovePrefixFromUserlinks($db, $oldPrefix, $log);

        // ── 1d. Strip prefix from table_link / tb data columns ───────────────
        // Plugin tables store the parent table name in a table_link column.
        // System tables (versions, geodata, rs) store it in a tb column.
        // None of these are touched by the DB rename above, so the rows still
        // reference the old prefixed name after the rename.
        self::maybeRemovePrefixFromTableLinks($db, $oldPrefix, $log);

        // ── 2. Rename DB tables (SQLite only) ────────────────────────────────
        // Only SQLite is supported for this auto-migration (all apps use SQLite).
        // On other engines the DBA should run the rename manually.
        if ($driver !== 'sqlite') {
            $log?->warning("maybeRemovePrefix: auto-rename not supported for engine {$driver}; please rename tables manually.");
            return;
        }

        // Check whether any legacy-prefix tables still exist in the DB.
        $prefixed = $db->query(
            "SELECT name FROM sqlite_master WHERE type='table' AND name LIKE ?",
            [$oldPrefix . '%'],
            'read'
        ) ?: [];

        if (empty($prefixed)) {
            return; // Already migrated or fresh install.
        }

        $log?->info("Migrate: found legacy prefix tables (prefix={$oldPrefix}); renaming…");

        foreach ($prefixed as $row) {
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
    }

    /**
     * Strips the legacy APP__ prefix from all table names and references inside
     * tables.json (name, plugin[], link[].other_tb, backlinks[]).
     *
     * Idempotent: once the prefix is gone the loop simply finds nothing to strip.
     */
    /**
     * Known system table names (bare form, without any prefix).
     * Used by both maybeRemovePrefixFromConfig and maybeCleanSystemTablesFromConfig.
     */
    private const SYSTEM_TABLE_NAMES = [
        'files', 'file_links', 'userlinks', 'users', 'user_table_privs',
        'geodata', 'rs', 'versions', 'log', 'vocabularies',
        'queries', 'charts', 'api_keys', 'migrations',
    ];

    private static function maybeRemovePrefixFromConfig(string $oldPrefix, Logger $log = null): void
    {
        $cfgPath = PROJ_DIR . 'cfg/tables.json';
        if (!file_exists($cfgPath)) {
            return;
        }

        $data = json_decode(file_get_contents($cfgPath), true);
        if (!$data || !isset($data['tables'])) {
            return;
        }

        $strip = fn(string $s) => str_starts_with($s, $oldPrefix)
            ? substr($s, strlen($oldPrefix))
            : $s;

        // Check whether prefix stripping or system-table cleanup is needed.
        $needsPrefix = false;
        foreach ($data['tables'] as $tb) {
            if (str_starts_with($tb['name'] ?? '', $oldPrefix)) {
                $needsPrefix = true;
                break;
            }
        }

        // Always check for system table contamination — it may survive a partial
        // migration that already stripped the prefix but didn't clean the list.
        $systemNames = array_merge(
            self::SYSTEM_TABLE_NAMES,
            array_map(fn($n) => 'bdus_' . $n, self::SYSTEM_TABLE_NAMES)
        );
        $hasSystemEntries = false;
        foreach ($data['tables'] as $tb) {
            $stripped = $strip($tb['name'] ?? '');
            if (in_array($stripped, $systemNames, true) || in_array($tb['name'] ?? '', $systemNames, true)) {
                $hasSystemEntries = true;
                break;
            }
        }

        if (!$needsPrefix && !$hasSystemEntries) {
            return; // Nothing to do.
        }

        if ($needsPrefix) {
            array_walk($data['tables'], function (&$tb) use ($oldPrefix, $strip) {
                $tb['name'] = $strip($tb['name'] ?? '');

                if (isset($tb['plugin']) && is_array($tb['plugin'])) {
                    $tb['plugin'] = array_map($strip, $tb['plugin']);
                }
                if (isset($tb['link']) && is_array($tb['link'])) {
                    foreach ($tb['link'] as &$link) {
                        if (isset($link['other_tb'])) {
                            $link['other_tb'] = $strip($link['other_tb']);
                        }
                    }
                    unset($link);
                }
                if (isset($tb['backlinks']) && is_array($tb['backlinks'])) {
                    $tb['backlinks'] = array_map(
                        fn($bl) => str_replace($oldPrefix, '', $bl),
                        $tb['backlinks']
                    );
                }
            });
            $log?->info("Migrate: updated tables.json (prefix '{$oldPrefix}' stripped)");
        }

        // Remove system table entries — always, not only when prefix was stripped.
        // v4 apps listed system tables in tables.json as regular tables; v5 does not.
        $before = count($data['tables']);
        $data['tables'] = array_values(array_filter(
            $data['tables'],
            fn($tb) => !in_array($tb['name'] ?? '', $systemNames, true)
        ));
        $removed = $before - count($data['tables']);
        if ($removed > 0) {
            $log?->info("Migrate: removed {$removed} system table(s) from tables.json");
        }

        file_put_contents(
            $cfgPath,
            json_encode(['tables' => $data['tables']], JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE)
        );
    }

    /**
     * Strips the legacy APP__ prefix from table-name references inside
     * per-table field config files (everything in cfg/ except tables.json
     * and config.json).
     *
     * Properties fixed:
     *  - id_from_tb            plain table name  → strip prefix
     *  - get_values_from_tb    "table:field"     → strip prefix from table part
     *
     * Idempotent: once the prefix is gone the loop finds nothing to change.
     */
    private static function maybeRemovePrefixFromFieldConfigs(
        string $oldPrefix,
        Logger $log = null
    ): void {
        $cfgDir = PROJ_DIR . 'cfg/';
        if (!is_dir($cfgDir)) {
            return;
        }

        $skip = ['tables.json', 'config.json'];

        foreach (glob($cfgDir . '*.json') as $path) {
            $fname = basename($path);
            if (in_array($fname, $skip, true)) {
                continue;
            }

            $raw = file_get_contents($path);
            if ($raw === false || strpos($raw, $oldPrefix) === false) {
                continue; // Fast path: prefix not present.
            }

            $data = json_decode($raw, true);
            if (!is_array($data)) {
                continue;
            }

            $dirty = false;
            foreach ($data as &$fld) {
                if (!is_array($fld)) {
                    continue;
                }
                // id_from_tb: plain table name
                if (isset($fld['id_from_tb']) && str_starts_with($fld['id_from_tb'], $oldPrefix)) {
                    $fld['id_from_tb'] = substr($fld['id_from_tb'], strlen($oldPrefix));
                    $dirty = true;
                }
                // get_values_from_tb: "table:field" — strip prefix only from table part
                if (isset($fld['get_values_from_tb']) && str_starts_with($fld['get_values_from_tb'], $oldPrefix)) {
                    $fld['get_values_from_tb'] = substr($fld['get_values_from_tb'], strlen($oldPrefix));
                    $dirty = true;
                }
            }
            unset($fld);

            if ($dirty) {
                file_put_contents($path, json_encode($data, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE));
                $log?->info("Migrate: stripped prefix from field config {$fname}");
            }
        }
    }

    /**
     * Strips the legacy APP__ prefix from the tb_one and tb_two data columns
     * of the userlinks table, if it exists.
     *
     * This must run before M002_CreateFileLinks so that migration can correctly
     * identify and move file↔record rows. Idempotent: if the prefix is already
     * absent the UPDATE affects 0 rows.
     */
    private static function maybeRemovePrefixFromUserlinks(
        DBInterface $db,
        string $oldPrefix,
        Logger $log = null
    ): void {
        if ($db->getEngine() !== 'sqlite') {
            return; // prefix-stripping applies only to legacy SQLite apps
        }

        // Check whether the userlinks table exists at all (bare name — pre-M008).
        $exists = $db->query(
            "SELECT COUNT(*) AS cnt FROM sqlite_master WHERE type='table' AND name='userlinks'",
            [],
            'read'
        );
        if (!$exists || (int)($exists[0]['cnt'] ?? 0) === 0) {
            return;
        }

        // REPLACE() in SQLite replaces all occurrences — safe to run unconditionally.
        $db->query(
            "UPDATE userlinks SET tb_one = REPLACE(tb_one, ?, ''), tb_two = REPLACE(tb_two, ?, '')
             WHERE  tb_one LIKE ? OR tb_two LIKE ?",
            [$oldPrefix, $oldPrefix, $oldPrefix . '%', $oldPrefix . '%'],
            'boolean'
        );
        $log?->info("Migrate: stripped prefix '{$oldPrefix}' from userlinks.tb_one / tb_two");
    }

    /**
     * Strips the legacy APP__ prefix from table_link / tb data columns across
     * all plugin tables and system tables.
     *
     * Plugin tables: any user table that has a `table_link` TEXT column stores
     * the parent table name there (e.g. "APP__manuscripts" → "manuscripts").
     *
     * System tables that store a table name in a `tb` column:
     *   versions / bdus_versions, rs / bdus_rs
     * System tables that store it in a `table_link` column:
     *   geodata / bdus_geodata
     *
     * All UPDATEs use REPLACE() which is a no-op when the prefix is absent,
     * making this method fully idempotent.
     */
    private static function maybeRemovePrefixFromTableLinks(
        DBInterface $db,
        string $oldPrefix,
        Logger $log = null
    ): void {
        if ($db->getEngine() !== 'sqlite') {
            return;
        }

        // ── Plugin tables: scan every non-system table for a table_link column ──
        $allTables = $db->query(
            "SELECT name FROM sqlite_master WHERE type='table' AND name NOT LIKE 'sqlite_%'",
            [], 'read'
        ) ?: [];

        // System-table name variants (bare and bdus_ prefixed).
        $systemTables = [
            'migrations', 'bdus_migrations',
            'users',      'bdus_users',
            'user_table_privs', 'bdus_user_table_privs',
            'files',      'bdus_files',
            'file_links', 'bdus_file_links',
            'userlinks',  'bdus_userlinks',
            'versions',   'bdus_versions',
            'geodata',    'bdus_geodata',
            'rs',         'bdus_rs',
            'log',        'bdus_log',
            'vocabularies', 'bdus_vocabularies',
            'queries',    'bdus_queries',
            'charts',     'bdus_charts',
            'api_keys',   'bdus_api_keys',
        ];

        foreach ($allTables as $row) {
            $tbl = $row['name'];
            // Skip known system tables (bare, bdus_, and legacy-prefixed variants).
            $unprefixed = str_starts_with($tbl, $oldPrefix)
                ? substr($tbl, strlen($oldPrefix))
                : $tbl;
            if (
                in_array($tbl, $systemTables, true) ||
                str_starts_with($tbl, 'bdus_') ||
                in_array($unprefixed, ['versions', 'geodata', 'rs', 'log', 'vocabularies',
                    'files', 'file_links', 'userlinks', 'users', 'user_table_privs',
                    'migrations', 'queries', 'charts', 'api_keys'], true)
            ) {
                continue;
            }

            // Check if this table has a table_link column.
            $cols = $db->query("PRAGMA table_info(\"{$tbl}\")", [], 'read') ?: [];
            $hasTableLink = false;
            foreach ($cols as $col) {
                if ($col['name'] === 'table_link') {
                    $hasTableLink = true;
                    break;
                }
            }

            if (!$hasTableLink) {
                continue;
            }

            $db->query(
                "UPDATE \"{$tbl}\" SET table_link = REPLACE(table_link, ?, '')
                  WHERE table_link LIKE ?",
                [$oldPrefix, $oldPrefix . '%'],
                'boolean'
            );
        }

        // ── System tables: tb column (versions / bdus_versions, rs / bdus_rs) ──
        foreach (['versions', 'bdus_versions', 'rs', 'bdus_rs'] as $tbl) {
            $exists = $db->query(
                "SELECT COUNT(*) AS cnt FROM sqlite_master WHERE type='table' AND name=?",
                [$tbl], 'read'
            );
            if ((int)($exists[0]['cnt'] ?? 0) === 0) {
                continue;
            }
            $db->query(
                "UPDATE \"{$tbl}\" SET tb = REPLACE(tb, ?, '') WHERE tb LIKE ?",
                [$oldPrefix, $oldPrefix . '%'],
                'boolean'
            );
        }

        // ── System tables: table_link column (geodata / bdus_geodata) ────────
        foreach (['geodata', 'bdus_geodata'] as $tbl) {
            $exists = $db->query(
                "SELECT COUNT(*) AS cnt FROM sqlite_master WHERE type='table' AND name=?",
                [$tbl], 'read'
            );
            if ((int)($exists[0]['cnt'] ?? 0) === 0) {
                continue;
            }
            $db->query(
                "UPDATE \"{$tbl}\" SET table_link = REPLACE(table_link, ?, '')
                  WHERE table_link LIKE ?",
                [$oldPrefix, $oldPrefix . '%'],
                'boolean'
            );
        }

        $log?->info("Migrate: stripped prefix '{$oldPrefix}' from table_link / tb data columns");
    }

    /**
     * Runs all pending migrations.
     * Called once after successful login.
     *
     * @param DBInterface $db
     */
    public static function run(DBInterface $db, Logger $log = null): void
    {
        // Pre-flight: if this is an existing app still using the legacy APP__ prefix,
        // rename all tables and update config before the normal migration loop starts.
        self::maybeRemovePrefix($db, $log);

        // Pre-flight: rename bare 'migrations' table to 'bdus_migrations' if needed.
        // This runs before the migration loop so the tracking table is always bdus_*.
        if ($db->getEngine() === 'sqlite') {
            $bare = $db->query(
                "SELECT COUNT(*) AS cnt FROM sqlite_master WHERE type='table' AND name='migrations'",
                [], 'read'
            );
            $has_bdus = $db->query(
                "SELECT COUNT(*) AS cnt FROM sqlite_master WHERE type='table' AND name='bdus_migrations'",
                [], 'read'
            );
            if ((int)($bare[0]['cnt'] ?? 0) > 0 && (int)($has_bdus[0]['cnt'] ?? 0) === 0) {
                $db->exec('ALTER TABLE "migrations" RENAME TO "bdus_migrations"');
                $log?->info('Migrate: renamed migrations → bdus_migrations');
            }
        }

        $manage = new Manage($db);

        // Bootstrap: ensure the migrations tracking table exists.
        // This is the only table created outside the normal migration flow.
        $manage->createTable('bdus_migrations');

        // Load already-applied migration names.
        $applied = $db->query(
            "SELECT name FROM bdus_migrations",
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
                "INSERT INTO bdus_migrations (name, applied_at) VALUES (?, ?)",
                [$name, time()],
                'boolean'
            );

            $log?->info("DB migration: $name applied successfully");
        }

        if ($pending === 0) {
            $log?->debug("DB migrations: schema up to date");
        }
    }
}
