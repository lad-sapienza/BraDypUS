<?php

/**
 * @copyright 2007-2025 Julian Bogdani
 * @license AGPL-3.0; see LICENSE
 */

namespace DB\System\Migrations;

use DB\System\Manage;

/**
 * Creates bdus_zotero_libs and bdus_zotero_links for the Zotero integration.
 *
 * bdus_zotero_libs  — one row per configured Zotero library (user or group).
 * bdus_zotero_links — junction table linking BraDypUS records to Zotero items,
 *                     including a local citation cache and sync metadata.
 */
class M023_ZoteroTables
{
    public const NAME = 'M023_zotero_tables';

    public static function run(Manage $manage): void
    {
        $manage->createTable('bdus_zotero_libs');
        $manage->createTable('bdus_zotero_links');
    }
}
