<?php

/**
 * @copyright 2007-2026 Julian Bogdani
 * @license AGPL-3.0; see LICENSE
 */

namespace DB\System\Migrations;

use DB\System\Manage;

/**
 * Default groups that stand for the data side of the built-in privilege levels:
 * Reader (read everything), Writer (read, create, update and delete everything)
 * and Self writer (read and create everything, update and delete only the
 * records they created). Each rule applies to every table (`*`), new tables
 * included.
 *
 * Nobody is assigned to them and the levels are untouched, so nothing changes
 * until an admin puts a user in one of them (typically a user at level Enter).
 * Admin and super admin stay levels: they also gate configuration, users,
 * backups and lock bypass, which are not data rules.
 *
 * They are system groups (`bdus_groups.is_system = 1`): they cannot be
 * renamed, changed or deleted, not even by a super admin; only their members
 * can be chosen. A group that already has one of these names is left alone.
 */
class M050_AddDefaultGroups
{
    public const NAME = 'M050_add_default_groups';

    /** name => [description, rules [action, filter|null]] */
    public const GROUPS = [
        'Reader' => [
            'Default group: reads every table (the data side of level Reader).',
            [['read', null]],
        ],
        'Writer' => [
            'Default group: reads, creates, updates and deletes in every table (the data side of level Writer).',
            [['read', null], ['create', null], ['update', null], ['delete', null]],
        ],
        'Self writer' => [
            'Default group: reads and creates in every table, updates and deletes only the records they created (the data side of level Self writer).',
            [
                ['read', null], ['create', null],
                ['update', ['creator' => ['_eq' => '$CURRENT_USER']]],
                ['delete', ['creator' => ['_eq' => '$CURRENT_USER']]],
            ],
        ],
    ];

    public static function run(Manage $manage): void
    {
        if (!$manage->tableExists('bdus_groups') || !$manage->tableExists('bdus_group_rules')) {
            return;
        }
        $db = $manage->getDb();
        if (!$manage->columnExists('bdus_groups', 'is_system')) {
            $db->exec('ALTER TABLE bdus_groups ADD COLUMN is_system INTEGER');   // M049 normally did
        }

        foreach (self::GROUPS as $name => [$description, $rules]) {
            if (!empty($db->query('SELECT id FROM bdus_groups WHERE name = ?', [$name], 'read'))) {
                continue;
            }
            $id = (int) $db->query(
                'INSERT INTO bdus_groups (name, description, is_system) VALUES (?, ?, 1)',
                [$name, $description], 'id'
            );
            foreach ($rules as [$action, $filter]) {
                $db->query(
                    'INSERT INTO bdus_group_rules (group_id, table_name, action, filter) VALUES (?, ?, ?, ?)',
                    [$id, '*', $action, $filter === null ? null : json_encode($filter)], 'boolean'
                );
            }
        }
    }
}
