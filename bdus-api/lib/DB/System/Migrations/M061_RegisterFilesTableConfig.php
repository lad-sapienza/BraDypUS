<?php

/**
 * @copyright 2007-2026 Julian Bogdani
 * @license AGPL-3.0; see LICENSE
 */

namespace DB\System\Migrations;

use DB\System\Manage;
use File\ConfigSeed;

/**
 * Makes sure `bdus_files` is a configured table (its row in `bdus_cfg_tables` and its system
 * fields in `bdus_cfg_fields`), as it is in a new application.
 *
 * Group rules, locks and the record layer only apply to configured tables. Applications created
 * before this migration may lack the table row (or some fields), which would leave files
 * outside the permission system. Purely additive: nothing that exists is changed, and the files
 * table stays out of the list of data tables (the interface skips the `bdus_*` system tables).
 */
class M061_RegisterFilesTableConfig
{
    public const NAME = 'M061_register_files_table_config';

    public static function run(Manage $manage): void
    {
        if (!$manage->tableExists('bdus_files')
            || !$manage->tableExists('bdus_cfg_tables')
            || !$manage->tableExists('bdus_cfg_fields')) {
            return;
        }

        ConfigSeed::ensure($manage->getDb());
    }
}
