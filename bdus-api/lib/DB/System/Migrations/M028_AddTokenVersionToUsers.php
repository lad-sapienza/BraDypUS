<?php

/**
 * @copyright 2007-2025 Julian Bogdani
 * @license AGPL-3.0; see LICENSE
 */

namespace DB\System\Migrations;

use DB\System\Manage;

/**
 * Adds token_version to bdus_users for per-user JWT invalidation.
 *
 * Each time an admin changes a user's privilege level (or explicitly
 * revokes a session), token_version is incremented.  JwtManager embeds
 * the current token_version as the 'tkv' claim when issuing tokens;
 * Dispatcher rejects any token whose tkv no longer matches the DB value.
 *
 * Starts at DEFAULT 0 so that existing sessions (legacy tokens that carry
 * no tkv claim are also assumed to be 0) continue to work after the
 * migration.  Privilege changes or explicit revokes bump the counter to 1,
 * which invalidates those legacy tokens along with any newer ones.
 */
class M028_AddTokenVersionToUsers
{
    public const NAME = 'M028_add_token_version_to_users';

    public static function run(Manage $manage): void
    {
        if (!$manage->tableExists('bdus_users')) {
            return;
        }

        if (!$manage->columnExists('bdus_users', 'token_version')) {
            $manage->getDb()->query(
                'ALTER TABLE bdus_users ADD COLUMN token_version INTEGER NOT NULL DEFAULT 0',
                [],
                'boolean'
            );
        }
    }
}
