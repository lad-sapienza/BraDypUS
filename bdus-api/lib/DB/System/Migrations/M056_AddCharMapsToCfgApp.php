<?php

/**
 * @copyright 2007-2026 Julian Bogdani
 * @license AGPL-3.0; see LICENSE
 */

namespace DB\System\Migrations;

use DB\System\Manage;

/**
 * Adds `char_maps` to bdus_cfg_app: the application's own "char map" presets
 * (named sets of characters for the strip under long_text / md fields), as JSON
 * `{ name: list | map | string }`. See Config\AppSettings::getCharMaps() and
 * Config\CharPalette.
 *
 * Purely additive.
 */
class M056_AddCharMapsToCfgApp
{
    public const NAME = 'M056_add_char_maps_to_cfg_app';

    public static function run(Manage $manage): void
    {
        if (!$manage->tableExists('bdus_cfg_app')) {
            return;
        }
        if (!$manage->columnExists('bdus_cfg_app', 'char_maps')) {
            $manage->getDb()->exec('ALTER TABLE bdus_cfg_app ADD COLUMN char_maps TEXT');
        }
    }
}
