<?php

/**
 * @copyright 2007-2026 Julian Bogdani
 * @license AGPL-3.0; see LICENSE
 */

namespace Mcp;

/**
 * A prompt is refused: data an administrator submitted that cannot be saved, or
 * arguments a client supplied that cannot be rendered. `errorCode` is a code
 * string in the style of the REST API; `detail` says what is wrong.
 */
class PromptError extends \RuntimeException
{
    public function __construct(public readonly string $errorCode, public readonly mixed $detail = null)
    {
        parent::__construct($errorCode);
    }
}
