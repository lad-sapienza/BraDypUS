<?php

/**
 * @copyright 2007-2025 Julian Bogdani
 * @license AGPL-3.0; see LICENSE
 */

namespace DB\System\Migrations;

use DB\System\Manage;

/**
 * Makes the `creator` column in all non-plugin user data tables nullable
 * and adds a FK constraint to bdus_users ON DELETE SET NULL.
 *
 * Rationale: creator should be nullable so that deleting a user does not
 * orphan or cascade-delete records.  The FK constraint enforces referential
 * integrity and auto-nulls the creator field when the referenced user is
 * removed from bdus_users.
 *
 * SQLite: skipped entirely — SQLite cannot add FK constraints to existing
 * tables without a full table recreation, which is too risky here.
 * New tables created after this migration already use the correct inline FK
 * in createMinimalTable() (Alter\Sqlite).
 *
 * MySQL / PostgreSQL:
 *  1. Null out orphan creator values (IDs not present in bdus_users) to
 *     prevent FK violation when the constraint is added.
 *  2. Drop NOT NULL from the creator column.
 *  3. Add FK constraint  fk_{tb}_creator → bdus_users(id) ON DELETE SET NULL.
 *     The addForeignKey() call is idempotent (hasForeignKey guard inside).
 */
class M032_CreatorFkNullable
{
    public const NAME = 'M032_creator_fk_nullable';

    public static function run(Manage $manage): void
    {
        $db = $manage->getDb();
        $engine = $db->getEngine();

        // SQLite: new tables are already correct via createMinimalTable();
        // cannot safely add FK to existing tables without full recreation.
        if ($engine === 'sqlite') {
            return;
        }

        if (!$manage->tableExists('bdus_cfg_tables') || !$manage->tableExists('bdus_users')) {
            return;
        }

        $rows = $db->query(
            "SELECT name FROM bdus_cfg_tables WHERE COALESCE(is_plugin, 0) = 0",
            [],
            'read'
        );

        if (!$rows) {
            return;
        }

        $alter = $engine === 'mysql'
            ? new \DB\Alter\Mysql($db)
            : new \DB\Alter\Postgres($db);

        foreach ($rows as $row) {
            $tb = $row['name'];

            if (!$manage->tableExists($tb) || !$manage->columnExists($tb, 'creator')) {
                continue;
            }

            if ($engine === 'mysql') {
                // 1. Null out orphaned creator values
                $db->execInTransaction(
                    "UPDATE `{$tb}` SET `creator` = NULL"
                    . " WHERE `creator` IS NOT NULL"
                    . " AND `creator` NOT IN (SELECT `id` FROM `bdus_users`)"
                );
                // 2. Drop NOT NULL from the column
                $db->execInTransaction(
                    "ALTER TABLE `{$tb}` MODIFY `creator` INTEGER NULL"
                );
            } else {
                // 1. Null out orphaned creator values
                $db->execInTransaction(
                    "UPDATE \"{$tb}\" SET \"creator\" = NULL"
                    . " WHERE \"creator\" IS NOT NULL"
                    . " AND \"creator\" NOT IN (SELECT \"id\" FROM \"bdus_users\")"
                );
                // 2. Drop NOT NULL from the column
                $db->execInTransaction(
                    "ALTER TABLE \"{$tb}\" ALTER COLUMN \"creator\" DROP NOT NULL"
                );
            }

            // 3. Add FK (idempotent via hasForeignKey guard inside addForeignKey)
            $alter->addForeignKey($tb, 'creator', 'bdus_users', 'id', 'SET NULL', 'NO ACTION');
        }
    }
}
