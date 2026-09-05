<?php

/**
 * @copyright 2007-2026 Julian Bogdani
 * @license AGPL-3.0; see LICENSE
 */

namespace DB\System\Migrations;

use DB\System\Manage;

/**
 * Adds `lang` to bdus_cfg_app — the app's default UI language, applied for a
 * browser that hasn't picked one yet (see Controllers\Info::getAppInfo() and
 * AppLayout.vue). Previously exposed in the App settings form (ConfigAppForm.vue)
 * but never actually read or persisted anywhere — this migration and the
 * matching Config/AppSettings changes make the field do what it always looked
 * like it did.
 */
class M042_AddLangToCfgApp
{
    public const NAME = 'M042_add_lang_to_cfg_app';

    public static function run(Manage $manage): void
    {
        if (!$manage->columnExists('bdus_cfg_app', 'lang')) {
            $manage->getDb()->exec(
                "ALTER TABLE bdus_cfg_app ADD COLUMN lang TEXT NOT NULL DEFAULT 'en'"
            );
        }
    }
}
