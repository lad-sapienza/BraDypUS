<?php

/**
 * @copyright 2007-2025 Julian Bogdani
 * @license AGPL-3.0; see LICENSE
 */

namespace DB\System\Migrations;

use DB\System\Manage;

/**
 * Repairs file_links for apps that were migrated from v4 with a table prefix.
 *
 * M002_CreateFileLinks searched userlinks for rows where tb_one or tb_two
 * equalled '{prefix}files'. On legacy apps the prefix-stripping of userlinks
 * data happened AFTER M002 ran, so M002 found nothing and file_links was left
 * empty while the file-related rows remained stuck in userlinks.
 *
 * This migration:
 *  1. Strips any residual APP__ prefix from userlinks.tb_one / tb_two data
 *     (defensive; maybeRemovePrefix already does this, but M007 runs later).
 *  2. Moves remaining file↔record rows from userlinks into file_links.
 *  3. Removes those rows from userlinks.
 *
 * Idempotent:
 *  - On clean apps M002 already moved the rows → userlinks has no 'files'
 *    rows → steps 2-3 are no-ops.
 *  - On affected apps (e.g. paths) the rows are still in userlinks with
 *    the prefix already stripped by step 1 or maybeRemovePrefix → they get
 *    moved correctly.
 */
class M007_RepairFileLinks
{
    public const NAME = 'M007_repair_file_links';

    public static function run(Manage $manage): void
    {
        $db     = $manage->getDb();
        $prefix = $manage->getPrefix();

        // 1. Defensive prefix strip: detect any APP__ prefix still in the data
        //    (maybeRemovePrefix should have done this, but guard against edge cases).
        $sample = $db->query(
            "SELECT tb_one FROM userlinks WHERE INSTR(tb_one, '__') > 0 LIMIT 1",
            [],
            'read'
        );
        if (!empty($sample)) {
            $raw     = $sample[0]['tb_one'];
            $pos     = strpos($raw, '__');
            $oldPfx  = substr($raw, 0, $pos + 2); // e.g. "paths__"

            $db->query(
                "UPDATE userlinks
                 SET    tb_one = REPLACE(tb_one, ?, ''),
                        tb_two = REPLACE(tb_two, ?, '')
                 WHERE  tb_one LIKE ? OR tb_two LIKE ?",
                [$oldPfx, $oldPfx, $oldPfx . '%', $oldPfx . '%'],
                'boolean'
            );
        }

        // 2. Ensure file_links exists (idempotent).
        $manage->createTable('file_links');

        // 3. Migrate rows where 'files' is tb_one.
        $db->query(
            "INSERT INTO {$prefix}file_links (file_id, table_name, record_id, sort)
             SELECT id_one, tb_two, id_two, sort
             FROM   {$prefix}userlinks
             WHERE  tb_one = 'files'",
            [],
            'boolean'
        );

        // 4. Migrate rows where 'files' is tb_two.
        $db->query(
            "INSERT INTO {$prefix}file_links (file_id, table_name, record_id, sort)
             SELECT id_two, tb_one, id_one, sort
             FROM   {$prefix}userlinks
             WHERE  tb_two = 'files'",
            [],
            'boolean'
        );

        // 5. Remove the migrated rows from userlinks.
        $db->query(
            "DELETE FROM {$prefix}userlinks WHERE tb_one = 'files' OR tb_two = 'files'",
            [],
            'boolean'
        );
    }
}
