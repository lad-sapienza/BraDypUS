<?php

/**
 * @copyright 2007-2025 Julian Bogdani
 * @license AGPL-3.0; see LICENSE
 */

namespace DB\System\Migrations;

use DB\System\Manage;

/**
 * Creates the bdus_cfg_indexes system table used to track user-defined
 * and auto-generated indexes on user tables.
 *
 * Schema:
 *   id | tb | name | columns (JSON array) | unique (0/1)
 *   UNIQUE(tb, name)
 *
 * Idempotent: uses CREATE TABLE IF NOT EXISTS via Manage::createTable().
 */
class M027_CreateCfgIndexes
{
    public const NAME = 'M027_create_cfg_indexes';

    public static function run(Manage $manage): void
    {
        $manage->createTable('bdus_cfg_indexes');
    }
}
