<?php

/**
 * @copyright 2007-2025 Julian Bogdani
 * @license AGPL-3.0; see LICENSE
 */

namespace DB\System\Migrations;

use DB\System\Manage;

/**
 * Adds OAuth2 identity columns to bdus_users so that external providers
 * (Google, ORCID, …) can be used for authentication without a local password.
 *
 * New columns:
 *   oauth_provider  TEXT  — provider slug: 'google', 'orcid', …  (nullable)
 *   oauth_sub       TEXT  — provider-issued unique subject ID      (nullable)
 *
 * Existing password-based users are unaffected (both columns stay NULL).
 * A partial unique index on (oauth_provider, oauth_sub) prevents duplicate
 * links to the same external identity.
 */
class M022_AddOAuthToUsers
{
    public const NAME = 'M022_add_oauth_to_users';

    public static function run(Manage $manage): void
    {
        $db = $manage->getDb();

        // Guard: bdus_users must exist
        $tables = $db->query(
            "SELECT name FROM sqlite_master WHERE type='table' AND name='bdus_users'",
            [],
            'read'
        ) ?: [];
        if (empty($tables)) {
            return;
        }

        // Check whether columns already exist (idempotent)
        $cols = $db->query('PRAGMA table_info(bdus_users)', [], 'read') ?: [];
        $existing = array_column($cols, 'name');

        foreach (['oauth_provider', 'oauth_sub'] as $col) {
            if (!in_array($col, $existing, true)) {
                $db->query(
                    "ALTER TABLE bdus_users ADD COLUMN {$col} TEXT",
                    [],
                    'boolean'
                );
            }
        }

        // Partial unique index: only enforce uniqueness when oauth_sub is set
        // (SQLite supports WHERE clauses on indexes since 3.8.9)
        $indexes = $db->query(
            "SELECT name FROM sqlite_master WHERE type='index' AND name='users_oauth_sub_idx'",
            [],
            'read'
        ) ?: [];
        if (empty($indexes)) {
            $db->query(
                "CREATE UNIQUE INDEX users_oauth_sub_idx
                    ON bdus_users (oauth_provider, oauth_sub)
                  WHERE oauth_sub IS NOT NULL",
                [],
                'boolean'
            );
        }
    }
}
