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
 *  1. Copies any remaining 'files' rows from bdus_userlinks → bdus_file_links,
 *     skipping links whose file no longer exists (see below).
 *  2. Deletes all those 'files' rows from bdus_userlinks (migrated or orphaned).
 *
 * Orphaned links: v4 ran SQLite without FK enforcement, so bdus_userlinks
 * accumulated 'files' rows pointing at a file id that was later deleted from
 * bdus_files. bdus_file_links.file_id carries a FK to bdus_files.id, so copying
 * such a row raises a FOREIGN KEY constraint violation and aborts the whole
 * major upgrade. Those rows are dead references (no file to attach) and are
 * dropped rather than migrated.
 *
 * Idempotent: if no such rows exist, all three queries are no-ops.
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
             SELECT u.id_one, u.tb_two, u.id_two, u.sort
             FROM   bdus_userlinks u
             WHERE  u.tb_one = 'files'
               AND  EXISTS (SELECT 1 FROM bdus_files f WHERE f.id = u.id_one)"
        );

        // Rows where the file is in the tb_two position.
        $db->query(
            "INSERT INTO bdus_file_links (file_id, table_name, record_id, sort)
             SELECT u.id_two, u.tb_one, u.id_one, u.sort
             FROM   bdus_userlinks u
             WHERE  u.tb_two = 'files'
               AND  EXISTS (SELECT 1 FROM bdus_files f WHERE f.id = u.id_two)"
        );

        // Remove the migrated rows — plus any orphaned 'files' rows skipped
        // above: they are not valid record↔record links either.
        $db->query(
            "DELETE FROM bdus_userlinks WHERE tb_one = 'files' OR tb_two = 'files'"
        );
    }
}
