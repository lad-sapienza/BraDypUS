<?php

/**
 * @copyright 2007-2025 Julian Bogdani
 * @license AGPL-3.0; see LICENSE
 */

namespace DB\System\Migrations;

use DB\System\Manage;

/**
 * Drops and recreates bdus_versions with the canonical v5 schema.
 *
 * The old schema (editsql TEXT NOT NULL, no operation column) is incompatible
 * with the new snapshot system and the existing rows are not restorable anyway,
 * so we simply drop the table and start fresh.
 */
class M010_FixVersionsSchema
{
    public const NAME = 'M010_fix_versions_schema';

    public static function run(Manage $manage): void
    {
        $db = $manage->getDb();

        // Check whether the table already has the correct schema:
        // operation column present AND editsql nullable.
        $info = $db->query("PRAGMA table_info(bdus_versions)", [], 'read') ?: [];

        $hasOperation  = false;
        $editsqlNonNull = false;
        foreach ($info as $col) {
            if ($col['name'] === 'operation') {
                $hasOperation = true;
            }
            if ($col['name'] === 'editsql' && (int)$col['notnull'] === 1) {
                $editsqlNonNull = true;
            }
        }

        // Table is already correct — nothing to do.
        if ($hasOperation && !$editsqlNonNull) {
            return;
        }

        // Drop and recreate with the canonical schema.
        $db->exec('DROP TABLE IF EXISTS "bdus_versions"');
        $manage->createTable('bdus_versions');
    }
}
