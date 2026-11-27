<?php

/**
 * @copyright 2007-2026 Julian Bogdani
 * @license AGPL-3.0; see LICENSE
 */

declare(strict_types=1);

namespace Record;

use Config\Config;

/**
 * Turns the value of a form/API/import field into the value a column can store.
 *
 * An empty value (`''`, blank text) sent for a numeric, boolean, date or timestamp
 * column means "nothing": it is stored as NULL. SQLite and MySQL (non-strict) accept `''` there,
 * PostgreSQL refuses it (`invalid input syntax for type integer: ""`), and MySQL strict mode
 * refuses it too. TEXT columns keep what they are given.
 */
final class ColumnValue
{
    /** Column types (first word of `db_type`, upper case) where an empty value is NULL. */
    private const NULL_WHEN_EMPTY = [
        'INTEGER', 'INT', 'BIGINT', 'SMALLINT', 'TINYINT', 'MEDIUMINT',
        'REAL', 'FLOAT', 'DOUBLE', 'NUMERIC', 'DECIMAL', 'BOOLEAN',
        'DATE', 'TIMESTAMP', 'DATETIME', 'TIME',
    ];

    /** Is $dbType (as configured, e.g. `INTEGER`, `decimal(10,2)`) a type where `''` means NULL? */
    public static function emptyIsNull(?string $dbType): bool
    {
        $t = strtoupper(trim((string) $dbType));
        $t = preg_split('/[\s(]/', $t, 2)[0] ?? '';
        return in_array($t, self::NULL_WHEN_EMPTY, true);
    }

    /** The value to write for field $fld of table $tb. */
    public static function forColumn(Config $cfg, string $tb, string $fld, mixed $val): mixed
    {
        if (!is_string($val) || trim($val) !== '') {
            return $val;
        }
        return self::emptyIsNull((string) $cfg->get("tables.{$tb}.fields.{$fld}.db_type")) ? null : $val;
    }
}
