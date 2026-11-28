<?php

/**
 * @copyright 2007-2026 Julian Bogdani
 * @license AGPL-3.0; see LICENSE
 */

namespace DB\System\Migrations;

use Config\ToDB;
use DB\System\Manage;

/**
 * Drops the `printable` field of the files table.
 *
 * It was meant to be replaced by `public` and nothing ever read it: no export, template or screen
 * depends on it. The column and its field configuration are removed; `public` is not touched.
 * Idempotent: a missing column or field row is simply skipped.
 */
class M063_DropPrintableFromFiles
{
    public const NAME = 'M063_drop_printable_from_files';

    public static function run(Manage $manage): void
    {
        $db = $manage->getDb();

        if ($manage->tableExists('bdus_cfg_fields')) {
            ToDB::deleteField($db, 'bdus_files', 'printable');
        }

        if ($manage->tableExists('bdus_files') && $manage->columnExists('bdus_files', 'printable')) {
            $db->exec('ALTER TABLE bdus_files DROP COLUMN printable');
        }
    }
}
