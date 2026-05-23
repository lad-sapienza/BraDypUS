<?php

/**
 * @copyright 2007-2025 Julian Bogdani
 * @license AGPL-3.0; see LICENSE
 */

namespace DB\System\Migrations;

use DB\System\Manage;

/**
 * Removes any cfg/*.json file other than config.json that M015 may have missed.
 *
 * Why:
 *   M015 originally used a loop over bdus_cfg_tables to determine which files
 *   to delete.  System-table JSON files (e.g. cfg/files.json) were excluded
 *   from bdus_cfg_tables by M011, so they survived M015.  The bug was fixed
 *   in M015 (glob approach), but apps on which M015 had already run with the
 *   old logic retain the stale files.  This migration cleans them up.
 *
 * Idempotency:
 *   Missing files are silently skipped.
 */
class M017_CleanupCfgDir
{
    public const NAME = 'M017_cleanup_cfg_dir';

    public static function run(Manage $manage, ?string $projDir = null): void
    {
        $root = $projDir ?? (defined('PROJ_DIR') ? PROJ_DIR : null);
        if ($root === null) {
            return;
        }
        if (!str_ends_with($root, '/')) {
            $root .= '/';
        }

        $cfgDir = $root . 'cfg/';
        if (!is_dir($cfgDir)) {
            return;
        }

        foreach (scandir($cfgDir) ?: [] as $entry) {
            if ($entry !== 'config.json' && str_ends_with($entry, '.json')) {
                @unlink($cfgDir . $entry);
            }
        }
    }
}
