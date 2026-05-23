<?php

/**
 * @copyright 2007-2025 Julian Bogdani
 * @license AGPL-3.0; see LICENSE
 */

namespace DB\System\Migrations;

use DB\System\Manage;

/**
 * Renames cfg/app_data.json → cfg/config.json and strips legacy fields.
 *
 * Rename rationale:
 *   After M015 the only JSON file remaining in cfg/ is app_data.json.
 *   The name "app_data" is a historical artifact; "config" is clearer and
 *   consistent with every other framework's convention for the single
 *   boot-strap config file.  The file stays inside cfg/ (already protected
 *   by .htaccess) to avoid conflicts with the direct file-serving of
 *   projects/{app}/files/.
 *
 * Field cleanup:
 *   Several fields that were written by v4 are no longer read or acted on
 *   in v5.  They are stripped during the rename to keep config.json minimal:
 *     – gmapskey          (Google Maps — removed from UI, unused in v5)
 *     – googleanaytics    (Google Analytics — typo + unused in v5)
 *     – maxImageSize      (image resize — never implemented in v5)
 *     – virtual_keyboard  (on-screen keyboard — unused in v5)
 *     – api_login_as_user (anonymous API user — unused in v5)
 *     – auth_login_as_user (alias of above — unused in v5)
 *
 * Idempotency:
 *   If cfg/config.json already exists the rename is skipped entirely.
 *   If cfg/app_data.json is missing the migration does nothing.
 */
class M016_RenameAppDataJson
{
    public const NAME = 'M016_rename_app_data_json';

    /** Fields present in v4 app_data.json that are no longer used in v5. */
    private const OBSOLETE_FIELDS = [
        'gmapskey',
        'googleanaytics',
        'maxImageSize',
        'virtual_keyboard',
        'api_login_as_user',
        'auth_login_as_user',
    ];

    /**
     * @param Manage      $manage  System table manager.
     * @param string|null $projDir Override project root (for testing).
     */
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

        // Parse, strip obsolete fields, write as config.json, remove old file.
        $data = json_decode(file_get_contents($old), true);
        if (!is_array($data)) {
            rename($old, $new); // Corrupt JSON — rename as-is, don't touch content.
            return;
        }

        foreach (self::OBSOLETE_FIELDS as $field) {
            unset($data[$field]);
        }

        file_put_contents(
            $new,
            json_encode($data, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES)
        );
        unlink($old);
    }
}
