<?php

/**
 * @copyright 2007-2026 Julian Bogdani
 * @license AGPL-3.0; see LICENSE
 */

namespace Config;

/**
 * Where the values of a select-like field come from. Three settings say it and they are
 * alternatives, never combined:
 *
 *   get_values_from_tb  the distinct values of a column ("table:field"): the text is stored
 *   id_from_tb          the records of a lookup table (FK): the integer id is stored
 *   vocabulary_set      a vocabulary: the text is stored
 *
 * A field that carries more than one is ambiguous: the form offers the list of one and the
 * server used to check the value against another. When it happens in a stored config (it
 * could be written before this was refused) the first of the list above is the one in force,
 * the same order `Record\FieldOptions` and `Config\TableSchema` already follow.
 */
final class FieldSources
{
    /** In order of precedence. */
    public const KEYS = ['get_values_from_tb', 'id_from_tb', 'vocabulary_set'];

    /** @return string[] the sources set on the field config, in order of precedence */
    public static function set(array $fld): array
    {
        return array_values(array_filter(self::KEYS, fn($k) => !empty($fld[$k])));
    }

    public static function conflicts(array $fld): bool
    {
        return count(self::set($fld)) > 1;
    }

    /** The source in force, or null when the field has none of the three. */
    public static function effective(array $fld): ?string
    {
        return self::set($fld)[0] ?? null;
    }
}
