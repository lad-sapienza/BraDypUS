<?php

/**
 * @copyright 2007-2025 Julian Bogdani
 * @license AGPL-3.0; see LICENSE
 */

namespace DB\System;

use DB\DBInterface;
use DB\System\Manage;
use DB\System\Migrations\M001_AddUserTablePrivs;

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
    ];

    /**
     * Runs all pending migrations.
     * Called once after successful login.
     *
     * @param DBInterface $db
     * @param string $prefix  Application table prefix (e.g. "paths__")
     */
    public static function run(DBInterface $db, string $prefix): void
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
        foreach (self::ALL_MIGRATIONS as $class) {
            if (in_array($class::NAME, $applied, true)) {
                continue;
            }

            $class::run($manage);

            $db->query(
                "INSERT INTO {$prefix}migrations (name, applied_at) VALUES (?, ?)",
                [$class::NAME, time()],
                'boolean'
            );
        }
    }
}
