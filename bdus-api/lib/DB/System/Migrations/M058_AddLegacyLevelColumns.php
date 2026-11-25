<?php

/**
 * @copyright 2007-2026 Julian Bogdani
 * @license AGPL-3.0; see LICENSE
 */

namespace DB\System\Migrations;

use DB\System\Manage;

/**
 * Remembers where a user or an API key came from when an admin moves it from a
 * data level (Writer 20, Self writer 25, Reader 30) to level Enter plus the
 * equivalent system group, so the move can be undone:
 *
 *  - bdus_users.legacy_privilege    the level before the move (NULL = never moved)
 *  - bdus_users.legacy_group_id     the group the move added to the user, or NULL
 *                                   when the user already belonged to it (restoring
 *                                   then leaves the membership alone)
 *  - bdus_api_keys.legacy_privilege the level before the move (NULL = never moved)
 *
 * Purely additive and nullable: nothing changes until an admin moves someone.
 */
class M058_AddLegacyLevelColumns
{
    public const NAME = 'M058_add_legacy_level_columns';

    public static function run(Manage $manage): void
    {
        $db = $manage->getDb();
        if ($manage->tableExists('bdus_users')) {
            foreach (['legacy_privilege', 'legacy_group_id'] as $col) {
                if (!$manage->columnExists('bdus_users', $col)) {
                    $db->exec("ALTER TABLE bdus_users ADD COLUMN {$col} INTEGER");
                }
            }
        }
        if ($manage->tableExists('bdus_api_keys') && !$manage->columnExists('bdus_api_keys', 'legacy_privilege')) {
            $db->exec('ALTER TABLE bdus_api_keys ADD COLUMN legacy_privilege INTEGER');
        }
    }
}
