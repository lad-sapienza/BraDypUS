<?php

/**
 * @copyright 2007-2025 Julian Bogdani
 * @license AGPL-3.0; see LICENSE
 */

namespace Auth;

/**
 * Privilege / access-control helpers.
 * Extracted from the legacy utils class.
 */
class Authorization
{
    /** Maps privilege codes to their string keys. */
    private const PRIVILEGE_MAP = [
        1  => 'super_admin',
        10 => 'admin',
        20 => 'writer',
        25 => 'self_writer',
        30 => 'reader',
        40 => 'waiting',
    ];

    /**
     * Checks whether the current user has at least the given privilege level.
     *
     * @param string   $privilege One of: enter|read|preview|add_new|edit|multiple_edit|admin|super_admin
     * @param int|null $creator   Record creator id — enables self_writer to edit their own records
     */
    public static function can(string $privilege = 'edit', ?int $creator = null): bool
    {
        if (defined('PROJ_DIR')) {
            $app_data   = json_decode(file_get_contents(PROJ_DIR . 'cfg/app_data.json'), true);
            $app_status = $app_data['status'];
        } else {
            $app_status = 'on';
        }

        $user_priv = CurrentUser::privilege();

        if (!$user_priv || $user_priv === 0) {
            return false;
        }

        return match ($privilege) {
            'enter'                 => $app_status !== 'off'    && $user_priv < 39,
            'read', 'preview'       => $app_status !== 'off'    && $user_priv < 31,
            'add_new'               => $app_status !== 'frozen' && $user_priv < 26,
            'edit'                  => $app_status !== 'frozen' && (
                                           $user_priv < 21 ||
                                           ($creator && $creator == CurrentUser::id() && $user_priv < 26)
                                       ),
            'multiple_edit'         => $app_status !== 'frozen' && $user_priv < 21,
            'admin'                 => $app_status !== 'frozen' && $user_priv < 11,
            'super_admin'           => $user_priv < 2,
            default                 => false,
        };
    }

    /**
     * Translates between privilege codes (int) and string keys.
     *
     * - false / no arg  → string key for the current user
     * - 'all'           → full [int => string] map
     * - string key      → int code
     * - int code        → string key
     */
    public static function privilege(mixed $input = false): mixed
    {
        if (!$input) {
            $input = CurrentUser::privilege();
        }

        if ($input === 'all') {
            return self::PRIVILEGE_MAP;
        }

        if (in_array($input, self::PRIVILEGE_MAP, true)) {
            return array_search($input, self::PRIVILEGE_MAP);
        }

        if (array_key_exists($input, self::PRIVILEGE_MAP)) {
            return self::PRIVILEGE_MAP[$input];
        }

        return false;
    }
}
