<?php

/**
 * @copyright 2007-2026 Julian Bogdani
 * @license AGPL-3.0; see LICENSE
 */

namespace DB\System\Migrations;

use DB\System\Manage;

/**
 * Adds `image_presets` to bdus_cfg_app: a JSON list of derived-image presets
 * (name, max side, format, quality) served on demand and cached under
 * projects/{app}/cache/. See Image\Presets, Image\Derivative and
 * Config\AppSettings::getImagePresets().
 *
 * Purely additive: NULL means "no custom presets" (only the built-in `thumb`).
 */
class M048_AddImagePresetsToCfgApp
{
    public const NAME = 'M048_add_image_presets_to_cfg_app';

    public static function run(Manage $manage): void
    {
        if (!$manage->tableExists('bdus_cfg_app')) {
            return;
        }
        if (!$manage->columnExists('bdus_cfg_app', 'image_presets')) {
            $manage->getDb()->exec("ALTER TABLE bdus_cfg_app ADD COLUMN image_presets TEXT");
        }
    }
}
