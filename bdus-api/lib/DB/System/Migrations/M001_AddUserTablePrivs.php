<?php

/**
 * @copyright 2007-2025 Julian Bogdani
 * @license AGPL-3.0; see LICENSE
 */

namespace DB\System\Migrations;

use DB\System\Manage;

/**
 * Creates the user_table_privs system table.
 *
 * v4 had no per-table privilege storage: the users table held only a single
 * global integer. This migration creates the new table empty — the global
 * privilege continues to act as the fallback via UAC\UAC::can(), so all
 * existing users retain their current effective permissions unchanged.
 */
class M001_AddUserTablePrivs
{
    public const NAME = 'M001_add_user_table_privs';

    public static function run(Manage $manage): void
    {
        // createTable() uses CREATE TABLE IF NOT EXISTS — safe to call multiple times.
        $manage->createTable('user_table_privs');
    }
}
