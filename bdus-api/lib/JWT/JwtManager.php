<?php

namespace JWT;

use Firebase\JWT\JWT;
use Firebase\JWT\Key;

/**
 * Thin wrapper around firebase/php-jwt.
 *
 * Handles per-application secrets stored at:
 *   projects/{app}/cfg/.jwt_secret
 *
 * The secret is generated once on first use (random 32-byte hex) and
 * stored with permissions 0600. The cfg/ directory must not be
 * web-accessible (enforce via .htaccess or server config).
 *
 * @copyright 2007-2025 Julian Bogdani
 * @license AGPL-3.0; see LICENSE
 */
class JwtManager
{
    private const ALG             = 'HS256';
    private const TTL             = 8 * 3600;   // token lifetime: 8 hours
    private const REFRESH_WINDOW  = 30 * 60;    // refresh when < 30 min left

    // ── Generate ─────────────────────────────────────────────────────

    /**
     * Sign and return a JWT for the given user and application.
     *
     * Claims:
     *   sub  → user id
     *   prv  → numeric privilege level
     *   app  → application name
     *   name → display name
     *   eml  → email
     *   tkv  → token_version snapshot (used by Dispatcher to detect revocation)
     */
    public static function generate(array $user, string $app): string
    {
        $now = time();

        $payload = [
            'iss'  => 'bradypus',
            'iat'  => $now,
            'exp'  => $now + self::TTL,
            'sub'  => (int) $user['id'],
            'prv'  => (int) $user['privilege'],
            'tkv'  => (int) ($user['token_version'] ?? 0),
            'app'  => $app,
            'name' => $user['name']  ?? '',
            'eml'  => $user['email'] ?? '',
        ];

        return JWT::encode($payload, self::getSecret($app), self::ALG);
    }

    // ── Verify ───────────────────────────────────────────────────────

    /**
     * Validate and decode a JWT for the given application.
     * Returns the payload as an associative array, or null on failure.
     */
    public static function decode(string $token, string $app): ?array
    {
        try {
            $decoded = JWT::decode($token, new Key(self::getSecret($app), self::ALG));
            return (array) $decoded;
        } catch (\Throwable) {
            return null;
        }
    }

    // ── Peek ─────────────────────────────────────────────────────────

    /**
     * Extract the 'app' claim WITHOUT verifying the signature.
     * Used only to determine which per-app secret to load for full verification.
     */
    public static function peekApp(string $token): ?string
    {
        $parts = explode('.', $token);
        if (count($parts) !== 3) {
            return null;
        }
        $payload = json_decode(
            base64_decode(strtr($parts[1], '-_', '+/')),
            true
        );
        return $payload['app'] ?? null;
    }

    // ── Refresh helper ───────────────────────────────────────────────

    /**
     * Returns true if the token should be proactively refreshed
     * (less than REFRESH_WINDOW seconds remain before expiry).
     */
    public static function needsRefresh(array $claims): bool
    {
        return ($claims['exp'] - time()) < self::REFRESH_WINDOW;
    }

    // ── Secret management ────────────────────────────────────────────

    /**
     * Return the per-app signing secret, generating it on first call.
     *
     * The secret file location changed with M018 (moved from cfg/ to project root):
     *
     *   post-M018 : projects/{app}/.jwt_secret       (project root — canonical)
     *   pre-M018  : projects/{app}/cfg/.jwt_secret   (legacy location)
     *
     * Both candidates are tried so that apps not yet migrated continue to work.
     * When no file exists at all (first use / fresh install) the secret is generated
     * and written to the post-M018 location with chmod 0600.
     *
     * @todo Once the minimum supported installation has run M018, drop the
     *       cfg/.jwt_secret candidate and keep only {base}/.jwt_secret.
     */
    private static function getSecret(string $app): string
    {
        $base = MAIN_DIR . "projects/{$app}";

        foreach ([
            "{$base}/.jwt_secret",       // post-M018: file at project root
            "{$base}/cfg/.jwt_secret",   // pre-M018: legacy location inside cfg/
        ] as $candidate) {
            if (file_exists($candidate)) {
                return trim(file_get_contents($candidate));
            }
        }

        // First use: generate and store at the post-M018 location (project root).
        $path   = "{$base}/.jwt_secret";
        $secret = bin2hex(random_bytes(32));
        file_put_contents($path, $secret);
        chmod($path, 0600);

        return $secret;
    }
}
