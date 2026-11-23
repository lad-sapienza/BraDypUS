<?php

namespace Config;

/**
 * Validity of the database names chosen by the user for tables and fields.
 *
 * Lowercase letters, digits and underscore, starting with a letter, 2 to 63
 * characters (63 is the PostgreSQL identifier limit; MySQL allows 64).
 * Only applied when a name is created or renamed: names already in a
 * configuration (v4 migrations, activated tables/views) are never re-checked.
 */
final class Identifier
{
    public const MAX_LENGTH = 63;
    public const PATTERN    = '^[a-z][a-z0-9_]{1,62}$';

    public static function isValid(mixed $name): bool
    {
        return is_string($name) && preg_match('/' . self::PATTERN . '/', $name) === 1;
    }
}
