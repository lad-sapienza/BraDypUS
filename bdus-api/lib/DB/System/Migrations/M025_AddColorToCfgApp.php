<?php

/**
 * @copyright 2007-2025 Julian Bogdani
 * @license AGPL-3.0; see LICENSE
 */

namespace DB\System\Migrations;

use DB\System\Manage;

/**
 * Adds a `color` column to bdus_cfg_app so administrators can customize
 * the application's primary colour palette at runtime.
 * Defaults to 'indigo' (the Aura preset default).
 */
class M025_AddColorToCfgApp
{
    public const NAME = 'M025_add_color_to_cfg_app';

    public static function run(Manage $manage): void
    {
        if (!$manage->columnExists('bdus_cfg_app', 'color')) {
            $manage->getDb()->exec(
                "ALTER TABLE bdus_cfg_app ADD COLUMN color TEXT DEFAULT 'indigo'"
            );
        }
    }
}
