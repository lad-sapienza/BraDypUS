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
 * M002_CreateFileLinks searched bdus_userlinks for rows where tb_one or tb_two
 * equalled 'bdus_files'. On legacy apps the prefix-stripping of userlinks
 * data happened AFTER M002 ran, so M002 found nothing and bdus_file_links was
 * left empty while the file-related rows remained stuck in bdus_userlinks.
 *
 * This migration:
 *  1. Strips any residual APP__ prefix from bdus_userlinks.tb_one / tb_two data
 *     (defensive; maybeRemovePrefix already does this, but M007 runs later).
 *  2. Moves remaining file↔record rows from bdus_userlinks into bdus_file_links.
 *  3. Removes those rows from bdus_userlinks.
 *
 * Idempotent:
 *  - On clean apps M002 already moved the rows → bdus_userlinks has no 'bdus_files'
 *    rows → steps 2-3 are no-ops.
 *  - On affected apps (e.g. paths) the rows are still in bdus_userlinks with
 *    the prefix already stripped by step 1 or maybeRemovePrefix → they get
 *    moved correctly.
 */
class M007_RepairFileLinks
{
    public const NAME = 'M007_repair_file_links';

    public static function run(Manage $manage): void
    {
        $db = $manage->getDb();

        // 1. Defensive prefix strip: detect any APP__ prefix still in the data.
        //    INSTR() is SQLite/MySQL-only; PG installs are always new (no old prefix data).
        if ($db->getEngine() === 'sqlite') {
            $sample = $db->query(
                "SELECT tb_one FROM bdus_userlinks WHERE INSTR(tb_one, '__') > 0 LIMIT 1",
                [],
                'read'
            );
            if (!empty($sample)) {
                $raw     = $sample[0]['tb_one'];
                $pos     = strpos($raw, '__');
                $oldPfx  = substr($raw, 0, $pos + 2); // e.g. "paths__"

                $db->query(
                    "UPDATE bdus_userlinks
                     SET    tb_one = REPLACE(tb_one, ?, ''),
                            tb_two = REPLACE(tb_two, ?, '')
                     WHERE  tb_one LIKE ? OR tb_two LIKE ?",
                    [$oldPfx, $oldPfx, $oldPfx . '%', $oldPfx . '%'],
                    'boolean'
                );
            }
        }

        // 2. Ensure bdus_file_links exists (idempotent).
        $manage->createTable('bdus_file_links');

        // 3. Migrate rows where 'bdus_files' is tb_one.
        $db->query(
            "INSERT INTO bdus_file_links (file_id, table_name, record_id, sort)
             SELECT id_one, tb_two, id_two, sort
             FROM   bdus_userlinks
             WHERE  tb_one = 'bdus_files'",
            [],
            'boolean'
        );

        // 4. Migrate rows where 'bdus_files' is tb_two.
        $db->query(
            "INSERT INTO bdus_file_links (file_id, table_name, record_id, sort)
             SELECT id_two, tb_one, id_one, sort
             FROM   bdus_userlinks
             WHERE  tb_two = 'bdus_files'",
            [],
            'boolean'
        );

        // 5. Remove the migrated rows from bdus_userlinks.
        $db->query(
            "DELETE FROM bdus_userlinks WHERE tb_one = 'bdus_files' OR tb_two = 'bdus_files'",
            [],
            'boolean'
        );
    }
}
