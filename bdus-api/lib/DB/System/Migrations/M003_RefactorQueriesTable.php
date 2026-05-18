<?php

/**
 * @copyright 2007-2025 Julian Bogdani
 * @license AGPL-3.0; see LICENSE
 */

namespace DB\System\Migrations;

use DB\System\Manage;

/**
 * Refactors the queries table for v5.
 *
 * v4 stored search queries as a split (text, vals) pair produced by
 * SafeQuery::encode/decode for URL passing. v5 stores a single structured
 * JSON payload in a new `query` column, and tracks creation time in
 * `created_at` (Unix timestamp) instead of the string `date` column.
 *
 * This migration:
 *  1. Adds `query` column (TEXT, nullable) — idempotent.
 *  2. Adds `created_at` column (INTEGER, nullable) — idempotent.
 *  3. Populates existing rows: where `text` is present and `query` is NULL,
 *     wraps the raw SQL text as a sqlExpert payload and converts `date` to
 *     a Unix timestamp for `created_at`.
 */
class M003_RefactorQueriesTable
{
    public const NAME = 'M003_refactor_queries_table';

    public static function run(Manage $manage): void
    {
        $db     = $manage->getDb();
        $prefix = $manage->getPrefix();

        // 1. Add `query` column (idempotent)
        try {
            $db->query(
                "ALTER TABLE {$prefix}queries ADD COLUMN query TEXT",
                [],
                'boolean'
            );
        } catch (\Throwable $e) {
            // Column already exists — safe to ignore
        }

        // 2. Add `created_at` column (idempotent)
        try {
            $db->query(
                "ALTER TABLE {$prefix}queries ADD COLUMN created_at INTEGER",
                [],
                'boolean'
            );
        } catch (\Throwable $e) {
            // Column already exists — safe to ignore
        }

        // 3. Populate existing rows that have `text` but no `query` yet.
        //    We use a raw SELECT against the old columns (which still exist in
        //    the DB even though the descriptor no longer lists them).
        //    For new apps created after v5 the `text` column never existed —
        //    catch that case and return early (nothing to migrate).
        try {
            $rows = $db->query(
                "SELECT id, text, date FROM {$prefix}queries WHERE query IS NULL AND text IS NOT NULL",
                [],
                'read'
            );
        } catch (\Throwable $e) {
            // `text` column does not exist — this is a fresh v5 app, nothing to migrate.
            return;
        }

        if (!$rows) {
            return;
        }

        foreach ($rows as $row) {
            $queryPayload = json_encode([
                'search_type' => 'sqlExpert',
                'querytext'   => $row['text'] ?? '',
            ]);

            // Best-effort: convert the old string date to a Unix timestamp
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
                "UPDATE {$prefix}queries SET query = ?, created_at = ? WHERE id = ?",
                [$queryPayload, $createdAt, (int) $row['id']],
                'boolean'
            );
        }
    }
}
