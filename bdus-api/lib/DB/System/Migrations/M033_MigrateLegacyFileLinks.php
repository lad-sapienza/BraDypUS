<?php

/**
 * @copyright 2007-2025 Julian Bogdani
 * @license AGPL-3.0; see LICENSE
 */

namespace DB\System\Migrations;

use DB\System\Manage;

/**
 * Migrates residual file↔record links stored under the legacy bare table name.
 *
 * Background
 * ----------
 * M002_CreateFileLinks moved file links from bdus_userlinks to bdus_file_links,
 * but only matched rows whose tb_one / tb_two equalled 'bdus_files' (the name
 * the table had already been given by maybeAddBdusPrefix at that point).
 *
 * Apps that went through the prefix-rename path in a different order may still
 * have rows with tb_one = 'files' or tb_two = 'files' (the pre-rename name).
 * Those rows were invisible to M002 and therefore remained in bdus_userlinks,
 * incorrectly appearing as record↔record manual links.
 *
 * This migration:
 *  1. Copies any remaining 'files' rows from bdus_userlinks → bdus_file_links.
 *  2. Deletes those rows from bdus_userlinks.
 *
 * Idempotent: if no such rows exist, both queries are no-ops.
 */
class M033_MigrateLegacyFileLinks
{
    public const NAME = 'M033_migrate_legacy_file_links';

    public static function run(Manage $manage): void
    {
        $db = $manage->getDb();

        // Rows where the file is in the tb_one position.
        $db->query(
            "INSERT INTO bdus_file_links (file_id, table_name, record_id, sort)
             SELECT id_one, tb_two, id_two, sort
             FROM   bdus_userlinks
             WHERE  tb_one = 'files'"
        );

        // Rows where the file is in the tb_two position.
        $db->query(
            "INSERT INTO bdus_file_links (file_id, table_name, record_id, sort)
             SELECT id_two, tb_one, id_one, sort
             FROM   bdus_userlinks
             WHERE  tb_two = 'files'"
        );

        // Remove the migrated rows.
        $db->query(
            "DELETE FROM bdus_userlinks WHERE tb_one = 'files' OR tb_two = 'files'"
        );
    }
}
