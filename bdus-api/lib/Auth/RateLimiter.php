<?php

/**
 * @copyright 2007-2026 Julian Bogdani
 * @license AGPL-3.0; see LICENSE
 */

namespace Auth;

/**
 * Fixed-window request counter, kept in small files under cache/ratelimit/
 * (no schema, no extra service: nothing to migrate).
 *
 * Applied to API-key traffic and to failed logins, never to people already
 * signed in with a JWT. Limits come from environment variables
 * (BRADYPUS_RATE_<NAME>, requests per window); 0 switches a limit off.
 * Any I/O problem fails open: a broken cache directory never locks people out.
 */
class RateLimiter
{
    /** Built-in limits: [requests, window in seconds]. */
    public const DEFAULTS = [
        'KEY_WRITE'  => [60, 60],     // per API key, POST/PUT/PATCH/DELETE
        'KEY_READ'   => [300, 60],    // per API key, GET
        'KEY_IP'     => [120, 60],    // per client address, any API-key request
        'BAD_KEY_IP' => [60, 60],     // per client address, rejected credentials
        'LOGIN_IP'   => [30, 900],    // per client address, failed logins
    ];

    private static ?string $dir = null;

    public static function setDir(?string $dir): void
    {
        self::$dir = $dir;
    }

    /** [limit, window] of a named limit; limit 0 = off. */
    public static function limit(string $name): array
    {
        [$limit, $window] = self::DEFAULTS[$name];
        $env = getenv('BRADYPUS_RATE_' . $name);
        if ($env !== false && trim($env) !== '' && ctype_digit(trim($env))) {
            $limit = (int) trim($env);
        }
        return [$limit, $window];
    }

    /**
     * Counts one hit on $bucket for the named limit.
     *
     * @return int 0 when allowed, else the seconds to wait (Retry-After)
     */
    public static function hit(string $name, string $bucket): int
    {
        return self::touch($name, $bucket, true);
    }

    /** Like hit() but without counting: is the bucket already over its limit? */
    public static function blocked(string $name, string $bucket): int
    {
        return self::touch($name, $bucket, false);
    }

    private static function touch(string $name, string $bucket, bool $count): int
    {
        [$limit, $window] = self::limit($name);
        if ($limit <= 0) {
            return 0;
        }
        $dir = self::dir();
        if ($dir === null) {
            return 0;
        }

        $fh = @fopen($dir . sha1($name . '|' . $bucket) . '.cnt', 'c+');
        if (!$fh) {
            return 0;
        }
        try {
            if (!flock($fh, LOCK_EX)) {
                return 0;
            }
            $now   = time();
            $parts = explode(' ', trim((string) stream_get_contents($fh)));
            $start = (int) ($parts[0] ?? 0);
            $n     = (int) ($parts[1] ?? 0);
            if ($start === 0 || $now - $start >= $window) {
                $start = $now;
                $n     = 0;
            }
            if ($count) {
                $n++;
                ftruncate($fh, 0);
                rewind($fh);
                fwrite($fh, "$start $n");
            }
            // The limit-th request still passes; the next one is refused.
            $over = $count ? $n > $limit : $n >= $limit;
            return $over ? max(1, $window - ($now - $start)) : 0;
        } finally {
            flock($fh, LOCK_UN);
            fclose($fh);
        }
    }

    private static function dir(): ?string
    {
        $dir = self::$dir ?? (defined('MAIN_DIR') ? MAIN_DIR . 'cache/ratelimit/' : null);
        if ($dir === null) {
            return null;
        }
        if (!is_dir($dir) && !@mkdir($dir, 0775, true) && !is_dir($dir)) {
            return null;
        }
        if (random_int(1, 100) === 1) {
            foreach (glob($dir . '*.cnt') ?: [] as $f) {
                if (@filemtime($f) < time() - 7200) {
                    @unlink($f);
                }
            }
        }
        return $dir;
    }
}
