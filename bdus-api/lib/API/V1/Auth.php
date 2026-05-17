<?php

/**
 * @copyright 2007-2025 Julian Bogdani
 * @license AGPL-3.0; see LICENSE
 *
 * API key authentication for the v1 REST API.
 *
 * Clients authenticate by passing a plain-text API key via:
 *   - Authorization: Bearer {key}  header, OR
 *   - ?api_key={key}               query-string parameter.
 *
 * The key is hashed with SHA-256 before the DB lookup; plain-text keys are
 * never stored.
 */

namespace API\V1;

use DB\DBInterface;

class Auth
{
    /**
     * Verify the API key in the current request.
     *
     * Calls Router::error() + exit on failure so callers never continue past
     * an unauthenticated request.
     */
    public static function verify(DBInterface $db, string $prefix): void
    {
        $key = self::extractKey();
        if (!$key) {
            Router::error('API key required', 'MISSING_API_KEY', 401);
            exit;
        }

        $keyHash = hash('sha256', $key);

        try {
            $rows = $db->query(
                "SELECT id FROM {$prefix}api_keys WHERE key_hash = ? AND revoked_at IS NULL LIMIT 1",
                [$keyHash],
                'read'
            );
        } catch (\Throwable $e) {
            Router::error('API keys table not available — run migrations first', 'API_UNAVAILABLE', 503);
            exit;
        }

        if (empty($rows)) {
            Router::error('Invalid or revoked API key', 'INVALID_API_KEY', 401);
            exit;
        }

        // Update last_used_at — fire and forget (non-critical)
        try {
            $db->query(
                "UPDATE {$prefix}api_keys SET last_used_at = ? WHERE key_hash = ?",
                [time(), $keyHash],
                'boolean'
            );
        } catch (\Throwable $e) {
            // Intentionally ignored
        }
    }

    // ── Private helpers ───────────────────────────────────────────────────────

    private static function extractKey(): ?string
    {
        // 1. Authorization: Bearer {key} header
        $auth = $_SERVER['HTTP_AUTHORIZATION']
             ?? $_SERVER['REDIRECT_HTTP_AUTHORIZATION']
             ?? '';

        if ($auth === '' && function_exists('getallheaders')) {
            $all  = getallheaders();
            $auth = $all['Authorization'] ?? $all['authorization'] ?? '';
        }

        if (preg_match('/^Bearer\s+(.+)$/i', $auth, $m)) {
            return trim($m[1]);
        }

        // 2. ?api_key= query-string parameter
        return $_GET['api_key'] ?? null;
    }
}
