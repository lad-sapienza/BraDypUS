<?php

/**
 * @copyright 2007-2022 Julian Bogdani
 * @license AGPL-3.0; see LICENSE
 */

declare(strict_types=1);

namespace SQL;

class SafeQuery
{
    /**
     * Gets $sql ad array of values,
     * serializes them in JSON,
     * base64 encodes the string
     * and makes it safe for URL
     * Returns the safe string
     *
     * @param string $sql
     * @param array $values
     * @return string
     */
    public static function encode(string $sql, array $values = []): string
    {
        $json = json_encode([$sql, $values]);
        return str_replace(['+', '/', '='], ['-', '_', ''], base64_encode($json));
    }

    /**
     * Gets an encoded strong
     * replaces URL safe characters
     * base64 decodes it
     * JSON decodes it
     * Returns array of $sql and $values
     *
     * @param string $string
     * @return array
     */
    public static function decode(string $string): array
    {
        $json_str = base64_decode(str_replace(['-', '_'], ['+', '/'], $string));
        return json_decode($json_str, true);
    }
}
