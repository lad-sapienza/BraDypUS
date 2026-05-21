<?php

/**
 * @copyright 2007-2025 Julian Bogdani
 * @license AGPL-3.0; see LICENSE
 */

namespace DB\System\Migrations;

use DB\System\Manage;

/**
 * Renames all system tables from their unprefixed names to the bdus_* namespace.
 *
 * Starting from v5.x, all BraDypUS system tables carry a bdus_ prefix so they
 * are visually distinct from user data tables and cannot accidentally collide
 * with a user-defined table called "users", "files", etc.
 *
 * This migration handles existing databases (created before the rename).
 * For databases created after this migration was introduced, the tables are
 * already named bdus_* at creation time, so every rename below is a no-op.
 *
 * Safety: each rename is guarded — it only runs when the old name exists AND
 * the new name does NOT exist. On failure the error is logged and the next
 * table is attempted; a single rename failure does not abort the migration.
 *
 * Note: bdus_migrations itself is NOT in this list. It is renamed in the
 * Migrate::run() pre-flight (before any migration loop) to avoid the
 * bootstrapping paradox of tracking a migration that renames its own table.
 */
class M008_AddBdusPrefix
{
    public const NAME = 'M008_add_bdus_prefix';

    /** System tables to rename (bare name → bdus_ name). migrations excluded (pre-flight). */
    private const TABLES = [
        'api_keys'        => 'bdus_api_keys',
        'charts'          => 'bdus_charts',
        'file_links'      => 'bdus_file_links',
        'files'           => 'bdus_files',
        'geodata'         => 'bdus_geodata',
        'log'             => 'bdus_log',
        'queries'         => 'bdus_queries',
        'rs'              => 'bdus_rs',
        'userlinks'       => 'bdus_userlinks',
        'users'           => 'bdus_users',
        'user_table_privs'=> 'bdus_user_table_privs',
        'versions'        => 'bdus_versions',
        'vocabularies'    => 'bdus_vocabularies',
    ];

    public static function run(Manage $manage): void
    {
        $db = $manage->getDb();

        if ($db->getEngine() !== 'sqlite') {
            // Only SQLite supported for auto-rename; DBA must handle other engines manually.
            return;
        }

        foreach (self::TABLES as $old => $new) {
            // Skip if old name doesn't exist (fresh app — already named bdus_*).
            $oldExists = $db->query(
                "SELECT COUNT(*) AS cnt FROM sqlite_master WHERE type='table' AND name=?",
                [$old], 'read'
            );
            if (!$oldExists || (int)($oldExists[0]['cnt'] ?? 0) === 0) {
                continue;
            }

            // Skip if new name already exists (partial migration or name collision).
            $newExists = $db->query(
                "SELECT COUNT(*) AS cnt FROM sqlite_master WHERE type='table' AND name=?",
                [$new], 'read'
            );
            if ((int)($newExists[0]['cnt'] ?? 0) > 0) {
                continue;
            }

            try {
                $db->exec("ALTER TABLE \"{$old}\" RENAME TO \"{$new}\"");
            } catch (\Throwable $e) {
                // Log and continue — don't abort other renames.
                error_log("M008: failed to rename {$old} → {$new}: " . $e->getMessage());
            }
        }
    }
}
