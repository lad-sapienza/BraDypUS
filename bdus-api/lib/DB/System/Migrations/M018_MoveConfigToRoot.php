<?php

/**
 * @copyright 2007-2025 Julian Bogdani
 * @license AGPL-3.0; see LICENSE
 */

namespace DB\System\Migrations;

use DB\System\Manage;
use DB\System\CreateApp;

/**
 * Moves cfg/config.json and cfg/.jwt_secret to the project root, then
 * removes the cfg/ directory and writes a <Files>-based .htaccess at the
 * project root to protect the two sensitive files from web access.
 *
 * Why:
 *   After M015–M017 the cfg/ directory contains only config.json, .jwt_secret
 *   and a deny-all .htaccess.  Moving those two files to the project root
 *   eliminates the unnecessary subdirectory while keeping the files protected.
 *
 *   Unlike a directory-level deny-all, a <Files> directive blocks only the
 *   named files, so projects/{app}/files/* continues to be served directly
 *   by the web server without interference.
 *
 * cfg/ removal:
 *   After moving the files, the cfg/ directory is removed.  If for any reason
 *   unexpected files are still present the rmdir() call silently fails and
 *   cfg/ is left in place — the migration still completes successfully.
 *
 * Idempotency:
 *   If config.json already exists at the project root the migration is skipped.
 */
class M018_MoveConfigToRoot
{
    public const NAME = 'M018_move_config_to_root';


    public static function run(Manage $manage, ?string $projDir = null): void
    {
        $root = $projDir ?? (defined('PROJ_DIR') ? PROJ_DIR : null);
        if ($root === null) {
            return;
        }
        if (!str_ends_with($root, '/')) {
            $root .= '/';
        }

        $cfgDir   = $root . 'cfg/';
        $oldCfg   = $cfgDir . 'config.json';
        $newCfg   = $root   . 'config.json';
        $oldJwt   = $cfgDir . '.jwt_secret';
        $newJwt   = $root   . '.jwt_secret';

        if (file_exists($newCfg)) {
            return; // Already migrated — idempotent.
        }
        if (!file_exists($oldCfg)) {
            return; // Nothing to move (fresh install writes directly to root).
        }

        // 1 — Move config.json
        rename($oldCfg, $newCfg);

        // 2 — Move .jwt_secret if present
        if (file_exists($oldJwt)) {
            rename($oldJwt, $newJwt);
        }

        // 3 — Write <Files> .htaccess at project root
        CreateApp::writeProjectHtaccess(rtrim($root, '/'));

        // 4 — Remove cfg/.htaccess and try to rmdir cfg/
        @unlink($cfgDir . '.htaccess');
        @rmdir($cfgDir); // Silently skipped if non-empty.
    }
}
