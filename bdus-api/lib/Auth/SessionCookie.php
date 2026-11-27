<?php

/**
 * @copyright 2007-2026 Julian Bogdani
 * @license AGPL-3.0; see LICENSE
 */

namespace Auth;

/**
 * The session cookie: lets the browser show protected files in `<img>`, `<a href>`, PDF viewers,
 * where a script cannot attach an `Authorization` header.
 *
 * It carries the same signed login token (per-application secret, expiry, revocation through
 * `token_version`) that the application otherwise sends in the header, so signing out, a
 * password change or a revoked session end it exactly as they end the token.
 *
 * What makes it safe is where it is accepted: **only for GET requests to the routes listed in
 * `Router::COOKIE_ROUTES` (reading a file)**. Every route that changes anything still requires
 * the header, which a cross-site page cannot make the browser send. Cookie authentication
 * therefore adds no way to forge a write. Flags: `HttpOnly` (scripts cannot read it), `Secure`
 * on HTTPS, `SameSite=Lax` (`BRADYPUS_COOKIE_SAMESITE` may set `Strict`, or `None` for a
 * front-end served from another site; `None` is only used over HTTPS, otherwise it falls back
 * to `Lax`). One cookie per application, so several applications can be open in one browser.
 */
final class SessionCookie
{
    public const ENV_SAMESITE = 'BRADYPUS_COOKIE_SAMESITE';

    private const SAMESITE = ['Lax', 'Strict', 'None'];

    /** Cookie name for an application. */
    public static function name(string $app): string
    {
        return 'bdus_s_' . preg_replace('/[^A-Za-z0-9_]/', '_', $app);
    }

    /** True when the request reached us over HTTPS, directly or through a trusted proxy. */
    public static function isHttps(?array $server = null, ?string $trusted = null): bool
    {
        $server ??= $_SERVER;
        $https = strtolower((string) ($server['HTTPS'] ?? ''));
        if ($https !== '' && $https !== 'off') {
            return true;
        }
        if ((int) ($server['SERVER_PORT'] ?? 0) === 443) {
            return true;
        }
        // The proxy that terminates TLS says so, and is believed only when it is a trusted one.
        return strtolower((string) ($server['HTTP_X_FORWARDED_PROTO'] ?? '')) === 'https'
            && ClientIp::isTrustedProxy((string) ($server['REMOTE_ADDR'] ?? ''), $trusted);
    }

    /**
     * Attributes of the cookie for this request.
     *
     * @return array{secure: bool, samesite: string}
     */
    public static function attributes(?array $server = null, ?string $env = null, ?string $trusted = null): array
    {
        $env    ??= (string) (getenv(self::ENV_SAMESITE) ?: '');
        $wanted   = ucfirst(strtolower($env));
        $sameSite = in_array($wanted, self::SAMESITE, true) ? $wanted : 'Lax';
        $secure   = self::isHttps($server, $trusted);

        // SameSite=None is refused by browsers without Secure: do not issue a cookie that
        // would silently not work, use the safe default instead.
        if ($sameSite === 'None' && !$secure) {
            $sameSite = 'Lax';
        }

        return ['secure' => $secure, 'samesite' => $sameSite];
    }

    /** Sets the cookie to `$jwt` (a session cookie: the token's own expiry is what ends it). */
    public static function issue(string $app, string $jwt): void
    {
        if (headers_sent()) {
            return;
        }
        $a = self::attributes();
        setcookie(self::name($app), $jwt, [
            'expires'  => 0,
            'path'     => '/',
            'secure'   => $a['secure'],
            'httponly' => true,
            'samesite' => $a['samesite'],
        ]);
    }

    /** Removes the cookie. */
    public static function clear(string $app): void
    {
        if (headers_sent()) {
            return;
        }
        $a = self::attributes();
        setcookie(self::name($app), '', [
            'expires'  => 1,
            'path'     => '/',
            'secure'   => $a['secure'],
            'httponly' => true,
            'samesite' => $a['samesite'],
        ]);
    }

    /**
     * Identifies the principal from the cookie, for a route that accepts it. Only a GET (or
     * HEAD) request is ever authenticated this way. Returns whether someone was identified; the
     * caller still verifies `token_version` like for a header token.
     */
    public static function authenticate(string $app, ?array $cookies = null, ?string $method = null): bool
    {
        $method  = strtoupper($method ?? (string) ($_SERVER['REQUEST_METHOD'] ?? 'GET'));
        if ($method !== 'GET' && $method !== 'HEAD') {
            return false;
        }
        $cookies ??= $_COOKIE;
        $token = $cookies[self::name($app)] ?? null;
        if (!is_string($token) || $token === '') {
            return false;
        }

        $claims = \JWT\JwtManager::decode($token, $app);
        if (!$claims || empty($claims['sub'])) {
            return false;
        }

        CurrentUser::set([
            'id'        => (int) $claims['sub'],
            'privilege' => (int) ($claims['prv'] ?? 0),
            'tkv'       => isset($claims['tkv']) ? (int) $claims['tkv'] : 0,
            'name'      => $claims['name'] ?? '',
            'email'     => $claims['eml']  ?? '',
            'app'       => $app,
        ]);
        return true;
    }
}
