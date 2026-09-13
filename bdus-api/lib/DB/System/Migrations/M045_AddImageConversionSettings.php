<?php

/**
 * @copyright 2007-2026 Julian Bogdani
 * @license AGPL-3.0; see LICENSE
 */

namespace DB\System\Migrations;

use DB\System\Manage;

/**
 * Adds optional image-conversion settings to bdus_cfg_app, alongside the
 * existing max_image_size resize: whether to convert uploaded raster images
 * to a standard web format (webp/jpg), and the quality/DPI to apply when
 * converting. See Config\AppSettings, Controllers\Record::uploadFile(),
 * Controllers\File::replaceFile() and Image\Resizer::process().
 */
class M045_AddImageConversionSettings
{
    public const NAME = 'M045_add_image_conversion_settings';

    public static function run(Manage $manage): void
    {
        $db = $manage->getDb();

        if (!$manage->columnExists('bdus_cfg_app', 'image_convert')) {
            $db->exec("ALTER TABLE bdus_cfg_app ADD COLUMN image_convert INTEGER NOT NULL DEFAULT 0");
        }
        if (!$manage->columnExists('bdus_cfg_app', 'image_format')) {
            $db->exec("ALTER TABLE bdus_cfg_app ADD COLUMN image_format TEXT NOT NULL DEFAULT 'webp'");
        }
        if (!$manage->columnExists('bdus_cfg_app', 'image_quality')) {
            $db->exec("ALTER TABLE bdus_cfg_app ADD COLUMN image_quality INTEGER NOT NULL DEFAULT 85");
        }
        if (!$manage->columnExists('bdus_cfg_app', 'image_dpi')) {
            $db->exec("ALTER TABLE bdus_cfg_app ADD COLUMN image_dpi INTEGER NOT NULL DEFAULT 72");
        }
    }
}
