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
     */
    private static function getSecret(string $app): string
    {
        $base = MAIN_DIR . "projects/{$app}";

        // Post-M018: secret at project root.  Pre-M018 fallback: cfg/.jwt_secret.
        foreach (["{$base}/.jwt_secret", "{$base}/cfg/.jwt_secret"] as $candidate) {
            if (file_exists($candidate)) {
                return trim(file_get_contents($candidate));
            }
        }

        // First use: generate and store at the project root (post-M018 location).
        $path   = "{$base}/.jwt_secret";
        $secret = bin2hex(random_bytes(32));
        file_put_contents($path, $secret);
        chmod($path, 0600);

        return $secret;
    }
}
