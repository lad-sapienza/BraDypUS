<?php

/**
 * @copyright 2007-2025 Julian Bogdani
 * @license AGPL-3.0; see LICENSE
 */

namespace DB\System\Migrations;

use DB\System\Manage;

/**
 * Adds an `operation` column to the bdus_versions table.
 *
 * The column records what kind of write triggered the snapshot:
 *   'update'  — a normal field edit saved through the record editor
 *   'delete'  — a pre-delete snapshot (enables deleted-record recovery)
 *   'restore' — the snapshot taken before a rollback (audit trail for undo)
 *
 * Existing rows get DEFAULT 'update', which is the only operation that
 * existed before this migration was introduced.
 *
 * Idempotency: if the column already exists (e.g. on a fresh DB built after
 * versions.json was updated) the ALTER TABLE throws an error that is caught
 * and silently ignored.
 */
class M009_AddVersionOperation
{
    public const NAME = 'M009_add_version_operation';

    public static function run(Manage $manage): void
    {
        $db = $manage->getDb();

        try {
            $db->query(
                "ALTER TABLE bdus_versions ADD COLUMN operation TEXT NOT NULL DEFAULT 'update'",
                [],
                'boolean'
            );
        } catch (\Throwable $e) {
            // Column already exists — nothing to do.
        }
    }
}
