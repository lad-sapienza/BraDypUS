<?php

/**
 * @copyright 2007-2025 Julian Bogdani
 * @license AGPL-3.0; see LICENSE
 */

namespace DB\System\Migrations;

use DB\System\Manage;

/**
 * Introduces the dedicated file_links junction table.
 *
 * Previously, file↔record relationships were stored in the generic
 * {prefix}userlinks table in a bidirectional layout (the file could appear
 * as tb_one or tb_two). This caused complex OR joins and mixed file links
 * with record↔record links in the same table.
 *
 * This migration:
 *  1. Creates {prefix}file_links with a clean unidirectional schema.
 *  2. Migrates all existing file rows from {prefix}userlinks (both directions).
 *  3. Removes the migrated rows from {prefix}userlinks so that table only
 *     contains record↔record links going forward.
 */
class M002_CreateFileLinks
{
    public const NAME = 'M002_create_file_links';

    public static function run(Manage $manage): void
    {
        $db     = $manage->getDb();
        $prefix = $manage->getPrefix();

        // 1. Create the new table (IF NOT EXISTS — idempotent)
        $manage->createTable('file_links');

        // 2. Migrate rows where {prefix}files is tb_one
        $db->query(
            "INSERT INTO {$prefix}file_links (file_id, table_name, record_id, sort)
             SELECT id_one, tb_two, id_two, sort
             FROM   {$prefix}userlinks
             WHERE  tb_one = ?",
            ["{$prefix}files"]
        );

        // 3. Migrate rows where {prefix}files is tb_two
        $db->query(
            "INSERT INTO {$prefix}file_links (file_id, table_name, record_id, sort)
             SELECT id_two, tb_one, id_one, sort
             FROM   {$prefix}userlinks
             WHERE  tb_two = ?",
            ["{$prefix}files"]
        );

        // 4. Remove the migrated rows from userlinks
        $db->query(
            "DELETE FROM {$prefix}userlinks WHERE tb_one = ? OR tb_two = ?",
            ["{$prefix}files", "{$prefix}files"]
        );
    }
}
