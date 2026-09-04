<?php

/**
 * @copyright 2007-2026 Julian Bogdani
 * @license AGPL-3.0; see LICENSE
 */

namespace DB\System\Migrations;

use DB\System\Manage;

/**
 * Adds `allow_self_registration` to bdus_cfg_app — opt-in gate for the public
 * "create account" flow (Login::register()). Defaults to 0/off: an app that
 * hasn't run this migration, or whose admin never turned it on, keeps the
 * pre-existing behaviour of admin-managed users only.
 */
class M041_AddSelfRegistrationToCfgApp
{
    public const NAME = 'M041_add_self_registration_to_cfg_app';

    public static function run(Manage $manage): void
    {
        if (!$manage->columnExists('bdus_cfg_app', 'allow_self_registration')) {
            $manage->getDb()->exec(
                'ALTER TABLE bdus_cfg_app ADD COLUMN allow_self_registration INTEGER NOT NULL DEFAULT 0'
            );
        }
    }
}
