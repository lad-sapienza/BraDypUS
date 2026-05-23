<?php

/**
 * @copyright 2007-2025 Julian Bogdani
 * @license AGPL-3.0; see LICENSE
 */

namespace DB\System\Migrations;

use DB\System\Manage;

/**
 * Adds the `extra` TEXT column to bdus_cfg_tables.
 *
 * The initial DB-backed config schema (M011) did not include a catch-all
 * column for non-standard table properties such as `rs` (the Harris Matrix
 * identifier field).  Any value stored there was silently dropped on save
 * and missing on load.
 *
 * This migration follows the same pattern used by bdus_cfg_fields: a JSON
 * `extra` column holds all attributes that don't have a dedicated column.
 */
class M012_AddCfgTablesExtra
{
    public const NAME = 'M012_add_cfg_tables_extra';

    public static function run(Manage $manage): void
    {
        $db = $manage->getDb();

        // Check whether bdus_cfg_tables exists (it was created by M011;
        // apps that skipped M011 don't need this column yet).
        $tables = $db->query(
            "SELECT name FROM sqlite_master WHERE type='table' AND name='bdus_cfg_tables'",
            [],
            'read'
        ) ?: [];

        if (empty($tables)) {
            return; // Table doesn't exist — nothing to alter.
        }

        // Check whether the column already exists (idempotency).
        $cols = $db->query("PRAGMA table_info(bdus_cfg_tables)", [], 'read') ?: [];
        foreach ($cols as $col) {
            if ($col['name'] === 'extra') {
                return; // Already present.
            }
        }

        $db->exec('ALTER TABLE bdus_cfg_tables ADD COLUMN extra TEXT');
    }
}
