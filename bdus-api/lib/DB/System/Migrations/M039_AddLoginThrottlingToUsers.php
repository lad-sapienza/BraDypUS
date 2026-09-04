<?php

/**
 * @copyright 2007-2025 Julian Bogdani
 * @license AGPL-3.0; see LICENSE
 */

namespace DB\System\Migrations;

use DB\System\Manage;

/**
 * Adds failed_login_count and locked_until to bdus_users for anti-brute-force
 * throttling on login, so installs without a configurable reverse proxy
 * (limit_req) aren't left with no protection at all — see Login::authenticate().
 *
 * Both start empty/zero so existing accounts are unaffected until their next
 * failed login attempt.
 */
class M039_AddLoginThrottlingToUsers
{
    public const NAME = 'M039_add_login_throttling_to_users';

    public static function run(Manage $manage): void
    {
        if (!$manage->tableExists('bdus_users')) {
            return;
        }

        if (!$manage->columnExists('bdus_users', 'failed_login_count')) {
            $manage->getDb()->query(
                'ALTER TABLE bdus_users ADD COLUMN failed_login_count INTEGER NOT NULL DEFAULT 0',
                [],
                'boolean'
            );
        }

        if (!$manage->columnExists('bdus_users', 'locked_until')) {
            $manage->getDb()->query(
                'ALTER TABLE bdus_users ADD COLUMN locked_until INTEGER NOT NULL DEFAULT 0',
                [],
                'boolean'
            );
        }
    }
}
