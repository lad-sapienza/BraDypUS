<?php

/**
 * @copyright 2007-2025 Julian Bogdani
 * @license AGPL-3.0; see LICENSE
 */

namespace DB\System\Migrations;

use DB\System\Manage;

/**
 * Adds a `privilege` column to the api_keys system table.
 *
 * The column stores an integer privilege level using the same scale as UAC:
 *   10 = admin, 25 = edit (create/update/delete), 30 = read (default).
 *
 * Existing keys get privilege=30 (read-only) via the DEFAULT clause — a safe,
 * conservative upgrade that never silently grants write access to old keys.
 */
class M006_AddApiKeyPrivilege
{
    public const NAME = 'M006_add_api_key_privilege';

    public static function run(Manage $manage): void
    {
        $db     = $manage->getDb();
        $prefix = $manage->getPrefix();
        $table  = $prefix . 'api_keys';

        // ALTER TABLE ... ADD COLUMN is supported by SQLite ≥ 3.1, MySQL, and PostgreSQL.
        // The DEFAULT value ensures all existing rows are immediately valid.
        //
        // Idempotency note: api_keys.json was updated to include `privilege` at the
        // same time as this migration was written. Databases created after that update
        // will have the column already (M005 reads the JSON), so we catch the
        // "duplicate column" error and treat it as a no-op.
        try {
            $db->query(
                "ALTER TABLE {$table} ADD COLUMN privilege INTEGER DEFAULT 30",
                [],
                'boolean'
            );
        } catch (\Throwable $e) {
            // Column already exists — nothing to do.
        }
    }
}
