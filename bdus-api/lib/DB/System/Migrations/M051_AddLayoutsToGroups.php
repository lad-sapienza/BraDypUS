<?php

/**
 * @copyright 2007-2026 Julian Bogdani
 * @license AGPL-3.0; see LICENSE
 */

namespace DB\System\Migrations;

use DB\System\Manage;

/**
 * Record layouts per group. Adds `bdus_groups.layouts`: a JSON object
 * {table: [template, ...]} naming the templates (record layouts) the members of
 * the group must use on each table. See Template\Assign.
 *
 * Purely additive: NULL means "no layout assigned", i.e. the behaviour of
 * every earlier version (the user picks any template, or none).
 */
class M051_AddLayoutsToGroups
{
    public const NAME = 'M051_add_layouts_to_groups';

    public static function run(Manage $manage): void
    {
        if ($manage->tableExists('bdus_groups') && !$manage->columnExists('bdus_groups', 'layouts')) {
            $manage->getDb()->exec("ALTER TABLE bdus_groups ADD COLUMN layouts TEXT");
        }
    }
}
