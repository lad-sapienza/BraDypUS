<?php

/**
 * @copyright 2007-2025 Julian Bogdani
 * @license AGPL-3.0; see LICENSE
 */

namespace DB\System\Migrations;

use DB\System\Manage;

/**
 * Creates the api_keys system table used by the v1 REST API.
 *
 * Stores SHA-256 hashes of API keys (plain-text keys are never persisted).
 * The table is created via the standard Manage::createTable() pathway so
 * the descriptor in lib/DB/System/Structure/api_keys.json is the single
 * source of truth for column definitions and indexes.
 */
class M005_CreateApiKeys
{
    public const NAME = 'M005_create_api_keys';

    public static function run(Manage $manage): void
    {
        $manage->createTable('bdus_api_keys');
    }
}
