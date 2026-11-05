<?php

/**
 * @copyright 2007-2026 Julian Bogdani
 * @license AGPL-3.0; see LICENSE
 */

namespace Record;

/**
 * Raised when a write is attempted on a SQL view that cannot be written
 * (tables.{view}.view_writable = 'none'). Controllers answer it with the code
 * `view_read_only` instead of letting the database fail with an opaque error.
 */
class ViewReadOnlyException extends \RuntimeException
{
    public function __construct(string $table)
    {
        parent::__construct("view_read_only: {$table} is a read-only SQL view");
    }
}
