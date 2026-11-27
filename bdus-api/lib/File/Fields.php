<?php

/**
 * @copyright 2007-2026 Julian Bogdani
 * @license AGPL-3.0; see LICENSE
 */

namespace File;

use Config\Config;

/**
 * The fields of the files table, as configured: the system ones (see ConfigSeed::FIELDS) and any custom
 * field an administrator added from the configuration screens, like on any other table.
 */
final class Fields
{
    /** Columns that belong to the binary or to the server: never written from a request. */
    public const PROTECTED = ['id', 'uuid', 'creator', 'ext'];

    /** Names the list endpoint uses for its own keys: a custom field cannot take them. */
    public const RESERVED = ['links', 'is_image', 'can_update', 'can_delete'];

    /**
     * Every configured field of the files table: name => type (`text`, `boolean`, …), in the
     * configured order.
     *
     * @return array<string, string>
     */
    public static function all(Config $cfg): array
    {
        $out = [];
        foreach ((array) ($cfg->get('tables.' . ConfigSeed::TABLE . '.fields') ?: []) as $fld) {
            if (!empty($fld['name']) && is_string($fld['name']) && preg_match('/^[A-Za-z0-9_]+$/', $fld['name']) === 1) {
                $out[$fld['name']] = (string) ($fld['type'] ?? 'text');
            }
        }
        return $out;
    }

    /**
     * The fields a request may write: every configured one except the protected columns.
     *
     * @return array<string, string>
     */
    public static function editable(Config $cfg): array
    {
        return array_diff_key(self::all($cfg), array_flip(self::PROTECTED));
    }

    /** Names of the system fields (cannot be deleted, renamed or retyped). */
    public static function system(): array
    {
        return array_column(ConfigSeed::FIELDS, 'name');
    }

    public static function isSystem(string $name): bool
    {
        return in_array($name, self::system(), true);
    }
}
