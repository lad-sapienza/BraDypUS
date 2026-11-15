<?php

/**
 * @copyright 2007-2026 Julian Bogdani
 * @license AGPL-3.0; see LICENSE
 */

namespace DB\System\Migrations;

use DB\System\Manage;

/**
 * Creates bdus_mcp_prompts: the text templates an application offers to MCP
 * clients (`prompts/list`, `prompts/get`), written by its administrator. The
 * table starts empty. Purely additive.
 */
class M054_CreateMcpPrompts
{
    public const NAME = 'M054_create_mcp_prompts';

    public static function run(Manage $manage): void
    {
        $manage->createTable('bdus_mcp_prompts');
    }
}
