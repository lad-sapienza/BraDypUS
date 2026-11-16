<?php

/**
 * @copyright 2007-2026 Julian Bogdani
 * @license AGPL-3.0; see LICENSE
 */

namespace DB\System\Migrations;

use DB\System\Manage;

/**
 * Adds bdus_api_keys.disabled_at: a key can be switched off and on again, unlike
 * revoked_at, which is final. A disabled key is refused like a revoked one until it
 * is enabled. Nullable: existing keys stay enabled. Purely additive.
 */
class M055_AddDisabledAtToApiKeys
{
    public const NAME = 'M055_add_disabled_at_to_api_keys';

    public static function run(Manage $manage): void
    {
        if ($manage->tableExists('bdus_api_keys') && !$manage->columnExists('bdus_api_keys', 'disabled_at')) {
            $manage->getDb()->exec('ALTER TABLE bdus_api_keys ADD COLUMN disabled_at INTEGER');
        }
    }
}
