<?php

/**
 * @copyright 2007-2026 Julian Bogdani
 * @license AGPL-3.0; see LICENSE
 */

namespace DB\System\Migrations;

use Config\ToDB;
use DB\System\Manage;

/**
 * Adds a nullable `attribution` column to bdus_files (credit / licence line
 * for a file, e.g. "© Jane Doe, CC BY 4.0") and registers its field config in
 * bdus_cfg_fields for existing apps (new apps get both from the structure
 * file and CreateApp).
 *
 * Purely additive: no existing row or behaviour changes. The field-config row
 * is inserted only when missing, so a label edited by the user is never
 * overwritten.
 */
class M047_AddAttributionToFiles
{
    public const NAME = 'M047_add_attribution_to_files';

    public static function run(Manage $manage): void
    {
        if (!$manage->tableExists('bdus_files')) {
            return;
        }

        if (!$manage->columnExists('bdus_files', 'attribution')) {
            $manage->getDb()->exec("ALTER TABLE bdus_files ADD COLUMN attribution TEXT");
        }

        $db = $manage->getDb();
        if (!$manage->tableExists('bdus_cfg_fields')) {
            return;
        }
        $existing = $db->query(
            'SELECT id FROM bdus_cfg_fields WHERE table_name = ? AND name = ?',
            ['bdus_files', 'attribution'],
            'read'
        );
        if (!$existing) {
            ToDB::upsertField($db, 'bdus_files', [
                'name'    => 'attribution',
                'label'   => 'Attribution',
                'type'    => 'text',
                'db_type' => 'TEXT',
            ]);
        }
    }
}
