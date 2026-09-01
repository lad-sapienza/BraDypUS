<?php

/**
 * @copyright 2007-2025 Julian Bogdani
 * @license AGPL-3.0; see LICENSE
 */

namespace DB\System\Migrations;

use DB\System\Manage;

/**
 * Refactors the charts table for v5.
 *
 * v4 stored chart definitions as raw SQL strings in `sqltext` and a
 * human-readable date in `date`. v5 stores a structured JSON payload
 * in `definition`, a Unix timestamp in `created_at`, and tracks
 * whether the chart is globally shared with `is_global`.
 *
 * This migration:
 *  1. Adds `definition` column (TEXT, nullable) — idempotent.
 *  2. Adds `created_at` column (INTEGER, nullable) — idempotent.
 *  3. Adds `is_global` column (INTEGER, nullable) — idempotent.
 *  4. Populates `created_at` from existing `date` column (best-effort strtotime).
 *     Old SQL charts stored in `sqltext` are NOT convertible to the new
 *     structured format — `definition` is left NULL for existing rows.
 */
class M004_RefactorChartsTable
{
    public const NAME = 'M004_refactor_charts_table';

    public static function run(Manage $manage): void
    {
        $db = $manage->getDb();

        // 1. Add `definition` column (idempotent)
        try {
            $db->query(
                "ALTER TABLE bdus_charts ADD COLUMN definition TEXT",
                [],
                'boolean'
            );
        } catch (\Throwable $e) {
            // Column already exists — safe to ignore
        }

        // 2. Add `created_at` column (idempotent)
        try {
            $db->query(
                "ALTER TABLE bdus_charts ADD COLUMN created_at INTEGER",
                [],
                'boolean'
            );
        } catch (\Throwable $e) {
            // Column already exists — safe to ignore
        }

        // 3. Add `is_global` column (idempotent)
        try {
            $db->query(
                "ALTER TABLE bdus_charts ADD COLUMN is_global INTEGER",
                [],
                'boolean'
            );
        } catch (\Throwable $e) {
            // Column already exists — safe to ignore
        }

        // 4. Populate `created_at` from the existing `date` column (best-effort).
        //    Old SQL charts are NOT converted — `definition` stays NULL.
        //    For new apps created after v5 the `date` column never existed —
        //    catch that case and return early (nothing to migrate).
        try {
            $rows = $db->query(
                "SELECT id, date FROM bdus_charts WHERE created_at IS NULL",
                [],
                'read'
            );
        } catch (\Throwable $e) {
            // `date` column does not exist — fresh v5 app, nothing to migrate.
            return;
        }

        if (!$rows) {
            return;
        }

        foreach ($rows as $row) {
            $createdAt = null;
            if (!empty($row['date'])) {
                $ts = strtotime($row['date']);
                if ($ts !== false) {
                    $createdAt = $ts;
                }
            }
            if ($createdAt === null) {
                $createdAt = time();
            }

            $db->query(
                "UPDATE bdus_charts SET created_at = ? WHERE id = ?",
                [$createdAt, (int) $row['id']],
                'boolean'
            );
        }
    }
}
