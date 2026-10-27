<?php

/**
 * @copyright 2007-2026 Julian Bogdani
 * @license AGPL-3.0; see LICENSE
 */

namespace DB\System\Migrations;

use DB\System\Manage;

/**
 * Groups & permissions (#78): creates bdus_groups, bdus_user_groups,
 * bdus_group_rules and bdus_table_locks, adds bdus_api_keys.group_id, and drops
 * bdus_user_table_privs.
 *
 * The dropped table held per-user, per-table privilege overrides that no code
 * ever applied (the UAC object injected into controllers was never consulted),
 * so no access decision changes. Groups and locks are empty after this
 * migration: with no rules, access is decided by the global tiers exactly as
 * before. See bdus-docs/dev/groups-permissions-design.md.
 */
class M046_AddGroupsAndPermissions
{
    public const NAME = 'M046_add_groups_and_permissions';

    public static function run(Manage $manage): void
    {
        // Order matters: groups before the tables that reference them.
        foreach (['bdus_groups', 'bdus_table_locks', 'bdus_user_groups', 'bdus_group_rules'] as $table) {
            $manage->createTable($table);
        }

        if ($manage->tableExists('bdus_api_keys') && !$manage->columnExists('bdus_api_keys', 'group_id')) {
            $manage->getDb()->exec("ALTER TABLE bdus_api_keys ADD COLUMN group_id INTEGER");
        }

        if ($manage->tableExists('bdus_user_table_privs')) {
            $manage->getDb()->exec("DROP TABLE bdus_user_table_privs");
        }
    }
}
