<?php

/**
 * @copyright 2007-2026 Julian Bogdani
 * @license AGPL-3.0; see LICENSE
 */

namespace Mcp\Tools;

use Mcp\Context;
use Mcp\Tool;
use Mcp\ToolError;

abstract class Base implements Tool
{
    public function needs(): ?string
    {
        return null;
    }

    /** Annotations of a tool that only reads the application's own data. */
    protected function readOnly(): array
    {
        return ['readOnlyHint' => true, 'destructiveHint' => false, 'idempotentHint' => true, 'openWorldHint' => false];
    }

    /**
     * The table named by $args['table'], when the principal may read it. A table
     * out of reach is reported exactly like one that does not exist.
     *
     * Plugin tables are accepted only with $allowPlugin, and then judged by the
     * table they belong to: their rows are read through their record.
     */
    protected function table(array $args, Context $ctx, bool $allowPlugin = false): string
    {
        $tb = $args['table'] ?? null;
        if (!is_string($tb) || $tb === '') {
            throw new ToolError('parameter_missing', 'table');
        }
        $known = $ctx->cfg->get('tables.*.name') ?: [];
        if (!in_array($tb, $known, true) || str_starts_with($tb, 'bdus_')) {
            throw new ToolError('unknown_table', $tb);
        }
        $gate = $tb;
        if ($ctx->cfg->get("tables.{$tb}.is_plugin")) {
            $gate = $allowPlugin ? $ctx->cfg->get("tables.{$tb}.plugin_of") : null;
        }
        if (!$gate || !\Auth\Authorization::can('read', table: $gate)) {
            throw new ToolError('unknown_table', $tb);
        }
        return $tb;
    }

    /**
     * Counts $rows written or deleted against the hourly budget of the API key
     * (MCP_WRITE_ROWS). People signed in with a JWT are not limited, as on the
     * rest of the API. Call it before changing anything.
     */
    protected function budgetRows(int $rows): void
    {
        $keyId = \Auth\Channel::keyId();
        if ($keyId === null) {
            return;
        }
        if ($wait = \Auth\RateLimiter::hit('MCP_WRITE_ROWS', (string) $keyId, $rows)) {
            throw new ToolError('too_many_requests', [
                'retry_after' => $wait,
                'note'        => 'This key has written too many records in the last hour. Nothing was changed.',
            ]);
        }
    }

    /**
     * Same as table(), callable from a sibling class that shares the checks (RsValidate::prepare()).
     *
     * @internal
     */
    public function tableOf(array $args, Context $ctx): string
    {
        return $this->table($args, $ctx);
    }

    /** An integer argument within [$min, $max], or $default when absent. */
    protected function int(array $args, string $key, int $default, int $min, int $max): int
    {
        if (!isset($args[$key])) {
            return $default;
        }
        if (!is_int($args[$key]) && !(is_string($args[$key]) && ctype_digit($args[$key]))) {
            throw new ToolError('invalid_parameters', $key);
        }
        return max($min, min($max, (int) $args[$key]));
    }
}
