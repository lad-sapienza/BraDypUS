<?php

/**
 * @copyright 2007-2026 Julian Bogdani
 * @license AGPL-3.0; see LICENSE
 */

namespace DB\System\Migrations;

use Config\AppSettings;
use DB\System\Manage;
use Image\Presets;

/**
 * Gives every stored image preset its `access` and `enabled` (files rework).
 *
 * Until now every derived image was public. A preset now says who may be sent it (`public`, for files
 * marked public, or `inherit`, whoever may read the original) and whether it is served. To keep what is
 * published today exactly as it is, the existing presets become `access = public` and `enabled`, and the
 * built-in `thumb` (never stored before, so public by default) is written out explicitly as a public
 * preset: it then also shows in the administration screen. M060 already marked the existing files public.
 *
 * Idempotent: a preset that already has the keys is left alone, and so is an explicit `thumb`.
 */
class M062_AddAccessToImagePresets
{
    public const NAME = 'M062_add_access_to_image_presets';

    public static function run(Manage $manage): void
    {
        if (!$manage->tableExists('bdus_cfg_app') || !$manage->columnExists('bdus_cfg_app', 'image_presets')) {
            return;
        }

        $db      = $manage->getDb();
        $stored  = AppSettings::getImagePresets($db);
        $changed = false;
        $hasThumb = false;

        foreach ($stored as $i => $preset) {
            if (!is_array($preset)) {
                continue;
            }
            if (($preset['name'] ?? null) === Presets::SYSTEM) {
                $hasThumb = true;
            }
            if (!array_key_exists('access', $preset)) {
                $stored[$i]['access'] = 'public';
                $changed = true;
            }
            if (!array_key_exists('enabled', $preset)) {
                $stored[$i]['enabled'] = true;
                $changed = true;
            }
        }

        if (!$hasThumb) {
            $thumb           = Presets::defaultThumb();
            $thumb['access'] = 'public';   // it was public: published sites keep working
            array_unshift($stored, $thumb);
            $changed = true;
        }

        if ($changed) {
            AppSettings::saveImagePresets($db, $stored);
        }
    }
}
