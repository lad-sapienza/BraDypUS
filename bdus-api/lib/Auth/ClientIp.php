<?php

/**
 * @copyright 2007-2026 Julian Bogdani
 * @license AGPL-3.0; see LICENSE
 */

namespace Auth;

/**
 * Address of the client, for rate limits only.
 *
 * REMOTE_ADDR, unless the request comes from a trusted reverse proxy
 * (BRADYPUS_TRUSTED_PROXIES: comma-separated IPs or IPv4 CIDRs): then
 * X-Forwarded-For is read from the right, skipping trusted proxies, so an
 * address forged by the client at the left of the header is never believed.
 */
class ClientIp
{
    public static function get(?array $server = null, ?string $trusted = null): string
    {
        $server  ??= $_SERVER;
        $trusted ??= (string) (getenv('BRADYPUS_TRUSTED_PROXIES') ?: '');
        $remote  = (string) ($server['REMOTE_ADDR'] ?? '');
        $list    = array_values(array_filter(array_map('trim', explode(',', $trusted))));

        if ($list === [] || !self::isTrusted($remote, $list)) {
            return $remote;
        }
        $xff = array_reverse(array_map('trim', explode(',', (string) ($server['HTTP_X_FORWARDED_FOR'] ?? ''))));
        foreach ($xff as $ip) {
            if (filter_var($ip, FILTER_VALIDATE_IP) && !self::isTrusted($ip, $list)) {
                return $ip;
            }
        }
        return $remote;
    }

    private static function isTrusted(string $ip, array $list): bool
    {
        foreach ($list as $entry) {
            if ($entry === $ip) {
                return true;
            }
            if (str_contains($entry, '/') && !str_contains($ip, ':')) {
                [$net, $bits] = explode('/', $entry, 2);
                $n = ip2long($net);
                $i = ip2long($ip);
                $b = (int) $bits;
                if ($n !== false && $i !== false && $b >= 0 && $b <= 32) {
                    $mask = $b === 0 ? 0 : (~0 << (32 - $b)) & 0xFFFFFFFF;
                    if (($n & $mask) === ($i & $mask)) {
                        return true;
                    }
                }
            }
        }
        return false;
    }
}
