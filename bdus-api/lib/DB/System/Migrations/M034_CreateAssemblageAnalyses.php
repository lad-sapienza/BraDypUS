<?php

/**
 * @copyright 2007-2025 Julian Bogdani
 * @license AGPL-3.0; see LICENSE
 */

namespace DB\System\Migrations;

use DB\System\Manage;

/**
 * Creates the bdus_assemblage_analyses table.
 *
 * Stores saved pivot analysis configurations (source table, char field,
 * group path, measure) in a JSON definition column, following the same
 * pattern as bdus_charts.
 */
class M034_CreateAssemblageAnalyses
{
    public const NAME = 'M034_create_assemblage_analyses';

    public static function run(Manage $manage): void
    {
        $manage->createTable('bdus_assemblage_analyses');
    }
}
