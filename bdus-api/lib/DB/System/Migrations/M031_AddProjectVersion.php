<?php

/**
 * @copyright 2007-2025 Julian Bogdani
 * @license AGPL-3.0; see LICENSE
 */

namespace DB\System\Migrations;

use DB\System\Manage;

/**
 * Adds bdus_version to bdus_cfg_app.
 *
 * Tracks which version of BraDypUS last ran migrations on this project.
 * The value is written at the end of every Migrate::run() call so it always
 * reflects the currently-running application version.
 *
 * Null means the project was created or last used before this migration.
 */
class M031_AddProjectVersion
{
    public const NAME = 'M031_add_project_version';

    public static function run(Manage $manage): void
    {
        if (!$manage->tableExists('bdus_cfg_app')) {
            return;
        }

        if (!$manage->columnExists('bdus_cfg_app', 'bdus_version')) {
            $manage->getDb()->query(
                'ALTER TABLE bdus_cfg_app ADD COLUMN bdus_version TEXT',
                [],
                'boolean'
            );
        }
    }
}
