<?php

/**
 * @copyright 2007-2025 Julian Bogdani
 * @license AGPL-3.0; see LICENSE
 */

namespace Auth;

/**
 * Password hashing and verification helpers.
 * Extracted from the legacy utils class.
 */
class Password
{
    public static function hash(string $password): string
    {
        return password_hash($password, PASSWORD_DEFAULT);
    }

    /**
     * Verifies a plaintext password against a stored hash.
     * Accepts legacy SHA-1 hashes (40-char hex strings) for backwards compatibility.
     */
    public static function verify(string $plain, string $stored): bool
    {
        if (strlen($stored) === 40) {
            return $stored === sha1($plain);
        }
        return password_verify($plain, $stored);
    }
}
