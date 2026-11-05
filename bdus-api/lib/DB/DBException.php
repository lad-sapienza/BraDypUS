<?php
/**
 * @copyright 2007-2022 Julian Bogdani
 * @license AGPL-3.0; see LICENSE
 */

namespace DB;

class DBException extends \Exception
{
    /**
     * SQLSTATEs reserved for rules written by the database designer:
     * 45000 = MySQL/MariaDB `SIGNAL SQLSTATE '45000'`,
     * P0001 = PostgreSQL `RAISE EXCEPTION`.
     */
    private const USER_DEFINED_SQLSTATES = ['45000', 'P0001'];

    /**
     * Wraps a PDOException. The message stays the generic "Database error"
     * (SQL text, table and column names must not reach the client), unless the
     * error was raised on purpose by a trigger / stored routine: then
     * getRuleMessage() returns the message chosen by its author.
     */
    public static function fromPdo(\PDOException $e): self
    {
        return new self('Database error', 0, $e);
    }

    /**
     * Message of a user-defined database rule (trigger SIGNAL / RAISE
     * EXCEPTION), or null for any other database error.
     */
    public function getRuleMessage(): ?string
    {
        $prev = $this->getPrevious();
        if (!$prev instanceof \PDOException) {
            return null;
        }

        $state = $prev->errorInfo[0] ?? (string) $prev->getCode();
        if (!in_array($state, self::USER_DEFINED_SQLSTATES, true)) {
            return null;
        }

        $msg = (string) ($prev->errorInfo[2] ?? '');
        // PostgreSQL: "ERROR:  message\nCONTEXT: PL/pgSQL function …" — keep
        // only the first line, without the severity prefix.
        $msg = trim(strtok($msg, "\n") ?: '');
        $msg = trim(preg_replace('/^(ERROR|FATAL):\s*/', '', $msg));

        return $msg !== '' ? $msg : null;
    }
}
