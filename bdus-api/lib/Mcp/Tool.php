<?php

/**
 * @copyright 2007-2026 Julian Bogdani
 * @license AGPL-3.0; see LICENSE
 */

namespace Mcp;

/**
 * One MCP tool. Tools are generic: parameterised by table, never one per table.
 */
interface Tool
{
    /** Tool name, snake_case, stable. */
    public function name(): string;

    /**
     * @return array{title: string, description: string, inputSchema: array, annotations: array}
     */
    public function definition(): array;

    /**
     * What the tool needs switched on in the application's MCP settings:
     * null (it only reads), 'write' or 'delete'. A tool that needs something
     * counts as a write for rate limits.
     */
    public function needs(): ?string;

    /**
     * Runs the tool and returns its structured result.
     *
     * @throws ToolError when the call is refused
     */
    public function call(array $args, Context $ctx): array;
}
