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
    ];

    /**
     * Runs all pending migrations.
     * Called once after successful login.
     *
     * @param DBInterface $db
     * @param string $prefix  Application table prefix (e.g. "paths__")
     */
    public static function run(DBInterface $db, string $prefix, Logger $log = null): void
    {
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
