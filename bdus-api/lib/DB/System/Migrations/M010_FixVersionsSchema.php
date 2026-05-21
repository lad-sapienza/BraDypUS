<?php

/**
 * @copyright 2007-2025 Julian Bogdani
 * @license AGPL-3.0; see LICENSE
 */

namespace DB\System\Migrations;

use DB\System\Manage;

/**
 * Rebuilds bdus_versions with the canonical schema.
 *
 * Pre-existing `bdus_versions` tables may have:
 *   - editsql TEXT NOT NULL  (prevents INSERT from saveSnapshot which omits it)
 *   - no operation column    (also handled by M009, but may have been skipped)
 *   - indices using the old APP__ or bare prefix
 *
 * SQLite does not support ALTER COLUMN / DROP NOT NULL, so we use the
 * standard "create-copy-rename" approach.
 *
 * Idempotency: if the column editsql is already nullable and the operation
 * column exists, the migration still runs but is harmless (data is preserved).
 */
class M010_FixVersionsSchema
{
    public const NAME = 'M010_fix_versions_schema';

    public static function run(Manage $manage): void
    {
        $db = $manage->getDb();

        // Only needed for SQLite; other engines should be handled by DBAs.
        if ($db->getEngine() !== 'sqlite') {
            return;
        }

        // Check the table exists — if not, createTable() will build it fresh
        // with the correct schema and there is nothing to migrate.
        $exists = $db->query(
            "SELECT COUNT(*) AS cnt FROM sqlite_master WHERE type='table' AND name='bdus_versions'",
            [], 'read'
        );
        if ((int)($exists[0]['cnt'] ?? 0) === 0) {
            $manage->createTable('bdus_versions');
            return;
        }

        // Inspect the live editsql column: if NOT NULL, we need to rebuild.
        $info = $db->query("PRAGMA table_info(bdus_versions)", [], 'read') ?: [];
        $needsRebuild = false;
        $hasOperation = false;
        foreach ($info as $col) {
            if ($col['name'] === 'editsql' && (int)$col['notnull'] === 1) {
                $needsRebuild = true;
            }
            if ($col['name'] === 'operation') {
                $hasOperation = true;
            }
        }

        // Also rebuild if operation column is missing (M009 may have failed silently).
        if (!$hasOperation) {
            $needsRebuild = true;
        }

        if (!$needsRebuild) {
            return;
        }

        // Create a temporary table with the correct schema.
        $db->exec('DROP TABLE IF EXISTS "bdus_versions_m010_tmp"');
        $db->exec('
            CREATE TABLE "bdus_versions_m010_tmp" (
                id         INTEGER PRIMARY KEY AUTOINCREMENT,
                userid     INTEGER NOT NULL,
                time       INTEGER NOT NULL,
                tb         TEXT    NOT NULL,
                rowid      INTEGER NOT NULL,
                content    TEXT    NOT NULL,
                editsql    TEXT,
                editvalues TEXT,
                operation  TEXT    NOT NULL DEFAULT \'update\'
            )
        ');

        // Copy existing data; fill missing operation with the default value.
        // Build the SELECT dynamically so we handle both cases:
        //   a) operation column exists (added by M009 or already there)
        //   b) operation column is absent (M009 failed silently earlier)
        $operationExpr = $hasOperation
            ? "COALESCE(NULLIF(CAST(operation AS TEXT), ''), 'update')"
            : "'update'";

        $db->exec("
            INSERT INTO \"bdus_versions_m010_tmp\"
                        (id, userid, time, tb, rowid, content, editsql, editvalues, operation)
            SELECT       id, userid, time, tb, rowid, content,
                         NULLIF(editsql, ''),
                         NULLIF(editvalues, ''),
                         {$operationExpr}
            FROM bdus_versions
        ");

        // Swap tables.
        $db->exec('DROP TABLE "bdus_versions"');
        $db->exec('ALTER TABLE "bdus_versions_m010_tmp" RENAME TO "bdus_versions"');

        // Recreate indexes.
        $db->exec('CREATE INDEX IF NOT EXISTS ver_record_idx ON bdus_versions (tb, rowid)');
        $db->exec('CREATE INDEX IF NOT EXISTS ver_time_idx   ON bdus_versions (time)');
    }
}
