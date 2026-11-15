<?php

/**
 * @copyright 2007-2026 Julian Bogdani
 * @license AGPL-3.0; see LICENSE
 */

namespace Mcp;

/**
 * A tool refuses a call: unknown table, not enough privilege, invalid
 * arguments. Reported to the client as a tool result with `isError`, carrying
 * the same code strings as the REST API, so the model can read and correct.
 */
class ToolError extends \RuntimeException
{
    public function __construct(public readonly string $errorCode, public readonly mixed $detail = null)
    {
        parent::__construct($errorCode);
    }
}
