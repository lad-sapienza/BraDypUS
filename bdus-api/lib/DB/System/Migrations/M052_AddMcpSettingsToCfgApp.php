<?php

/**
 * @copyright 2007-2026 Julian Bogdani
 * @license AGPL-3.0; see LICENSE
 */

namespace DB\System\Migrations;

use DB\System\Manage;

/**
 * Adds the MCP settings to bdus_cfg_app: `mcp_enabled` (the MCP endpoint
 * answers at all), `mcp_write` and `mcp_delete` (the tools that change data),
 * all off by default, and `mcp_guide` (text the administrator adds to the
 * `guide` tool). See Config\AppSettings::getMcp() and Mcp\Server.
 *
 * Purely additive.
 */
class M052_AddMcpSettingsToCfgApp
{
    public const NAME = 'M052_add_mcp_settings_to_cfg_app';

    public static function run(Manage $manage): void
    {
        if (!$manage->tableExists('bdus_cfg_app')) {
            return;
        }
        $db = $manage->getDb();
        foreach (['mcp_enabled', 'mcp_write', 'mcp_delete'] as $col) {
            if (!$manage->columnExists('bdus_cfg_app', $col)) {
                $db->exec("ALTER TABLE bdus_cfg_app ADD COLUMN {$col} INTEGER DEFAULT 0");
            }
        }
        if (!$manage->columnExists('bdus_cfg_app', 'mcp_guide')) {
            $db->exec("ALTER TABLE bdus_cfg_app ADD COLUMN mcp_guide TEXT");
        }
    }
}
