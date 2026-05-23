<?php

/**
 * @copyright 2007-2025 Julian Bogdani
 * @license AGPL-3.0; see LICENSE
 */

namespace DB\System\Migrations;

use DB\System\Manage;

/**
 * Renames cfg/app_data.json → cfg/config.json.
 *
 * Why:
 *   After M015 the only JSON file remaining in cfg/ is app_data.json.
 *   The name "app_data" is a historical artifact; "config" is clearer and
 *   consistent with every other framework's convention for the single
 *   boot-strap config file.
 *
 * The file stays inside cfg/ (which is already protected by .htaccess from
 * web access) so no security change is needed.  Keeping it in cfg/ also
 * avoids conflicts with the direct file-serving of projects/{app}/files/
 * that would prevent adding a blanket deny-all at the project root.
 *
 * Idempotency:
 *   If cfg/config.json already exists (migration ran before) the rename is
 *   skipped.  If cfg/app_data.json is missing the migration does nothing.
 *
 * @param Manage      $manage  System table manager.
 * @param string|null $projDir Override project root (for testing).
 */
class M016_RenameAppDataJson
{
    public const NAME = 'M016_rename_app_data_json';

    public static function run(Manage $manage, ?string $projDir = null): void
    {
        $root = $projDir ?? (defined('PROJ_DIR') ? PROJ_DIR : null);
        if ($root === null) {
            return;
        }
        if (!str_ends_with($root, '/')) {
            $root .= '/';
        }

        $old = $root . 'cfg/app_data.json';
        $new = $root . 'cfg/config.json';

        if (file_exists($new)) {
            return; // Already renamed — idempotent.
        }
        if (!file_exists($old)) {
            return; // Nothing to rename (fresh install writes config.json directly).
        }

        rename($old, $new);
    }
}
