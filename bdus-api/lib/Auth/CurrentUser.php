<?php

namespace Auth;

/**
 * Holds the authenticated user's data for the current request.
 *
 * Populated from validated JWT claims at bootstrap (constants.php).
 * Replaces direct $_SESSION['user'] access throughout the codebase.
 *
 * @copyright 2007-2025 Julian Bogdani
 * @license AGPL-3.0; see LICENSE
 */
class CurrentUser
{
    private static array $data = [];

    /**
     * Populate from JWT claims or a user array.
     * Expected keys: id, name, email, privilege, app
     */
    public static function set(array $data): void
    {
        self::$data = $data;
    }

    /**
     * Return a specific field, or the whole array when no key is given.
     */
    public static function get(string $key = null)
    {
        if ($key === null) {
            return self::$data;
        }
        return self::$data[$key] ?? null;
    }

    public static function id(): ?int
    {
        return isset(self::$data['id']) ? (int) self::$data['id'] : null;
    }

    public static function privilege(): ?int
    {
        return isset(self::$data['privilege']) ? (int) self::$data['privilege'] : null;
    }

    public static function isAuthenticated(): bool
    {
        return !empty(self::$data['id']);
    }
}
