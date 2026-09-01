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

        if (!$manage->tableExists('bdus_users')) {
            return;
        }

        foreach (['oauth_provider', 'oauth_sub'] as $col) {
            if (!$manage->columnExists('bdus_users', $col)) {
                $db->query("ALTER TABLE bdus_users ADD COLUMN {$col} TEXT", [], 'boolean');
            }
        }

        if (!$manage->indexExistsPublic('bdus_users', 'users_oauth_sub_idx')) {
            if ($db->getEngine() === 'mysql') {
                // MySQL does not support partial indexes (WHERE clause).
                // A regular UNIQUE index already allows multiple NULLs (NULL != NULL in SQL).
                // TEXT columns also need prefix lengths in MySQL.
                $db->query(
                    "CREATE UNIQUE INDEX users_oauth_sub_idx
                        ON bdus_users (oauth_provider(100), oauth_sub(191))",
                    [], 'boolean'
                );
            } else {
                // SQLite and PostgreSQL support partial indexes.
                $db->query(
                    "CREATE UNIQUE INDEX users_oauth_sub_idx
                        ON bdus_users (oauth_provider, oauth_sub)
                      WHERE oauth_sub IS NOT NULL",
                    [], 'boolean'
                );
            }
        }
    }
}
