<?php

/**
 * @copyright 2007-2026 Julian Bogdani
 * @license AGPL-3.0; see LICENSE
 */

namespace DB\System\Migrations;

use DB\System\Manage;

/**
 * Adds reset_token_hash/reset_token_expires to bdus_users for the
 * self-service "forgot password" flow — see Login::requestPasswordReset()/
 * confirmPasswordReset(). Only the SHA-256 hash of the token is stored
 * (never the raw token), and both columns start empty/zero so existing
 * accounts are unaffected until they request a reset.
 */
class M040_AddPasswordResetToUsers
{
    public const NAME = 'M040_add_password_reset_to_users';

    public static function run(Manage $manage): void
    {
        if (!$manage->tableExists('bdus_users')) {
            return;
        }

        if (!$manage->columnExists('bdus_users', 'reset_token_hash')) {
            $manage->getDb()->query(
                "ALTER TABLE bdus_users ADD COLUMN reset_token_hash TEXT NOT NULL DEFAULT ''",
                [],
                'boolean'
            );
        }

        if (!$manage->columnExists('bdus_users', 'reset_token_expires')) {
            $manage->getDb()->query(
                'ALTER TABLE bdus_users ADD COLUMN reset_token_expires INTEGER NOT NULL DEFAULT 0',
                [],
                'boolean'
            );
        }
    }
}
