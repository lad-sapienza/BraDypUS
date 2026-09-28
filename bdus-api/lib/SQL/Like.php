<?php

/**
 * @copyright 2007-2026 Julian Bogdani
 * @license AGPL-3.0; see LICENSE
 */

declare(strict_types=1);

namespace SQL;

/**
 * Picks the LIKE operator that makes text searches case-insensitive on every
 * supported engine.
 *
 * SQLite and MySQL/MariaDB already compare case-insensitively with a plain
 * LIKE (ASCII on SQLite, per collation on MySQL), so they keep it — the SQL
 * they receive is unchanged. PostgreSQL's LIKE is case-sensitive; it needs
 * ILIKE to behave the same, otherwise a search for "imp" would miss "IMP001"
 * there while finding it everywhere else (issue #73).
 */
final class Like
{
    /**
     * @param string $engine  DB engine as returned by DBInterface::getEngine()
     *                        ('sqlite' | 'mysql' | 'pgsql'); anything else,
     *                        including an empty string, gets the plain LIKE
     * @param bool   $negated true for NOT LIKE / NOT ILIKE
     */
    public static function operator(string $engine, bool $negated = false): string
    {
        $op = $engine === 'pgsql' ? 'ILIKE' : 'LIKE';

        return $negated ? 'NOT ' . $op : $op;
    }
}
