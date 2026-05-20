<?php

/**
 * @copyright 2007-2025 Julian Bogdani
 * @license AGPL-3.0; see LICENSE
 *
 * API key authentication for the unified Bdus\Router surface.
 *
 * Clients pass a plain-text key via:
 *   - Authorization: Bearer {key}   header
 *   - ?api_key={key}                query-string parameter
 *
 * On success, populates CurrentUser with the key's privilege level and sets
 * `is_api_key = true` so the rest of the stack can distinguish key auth from
 * JWT auth.  Plain-text keys are never stored; only SHA-256 hashes.
 */

namespace Auth;

use DB\DBInterface;

class ApiKeyAuth
{
    /**
     * Attempt to authenticate the current request via API key.
     *
     * Returns true and populates CurrentUser on success.
     * Returns false (silently) if no key is present or the key is invalid.
     *
     * @param DBInterface $db
     * @param string      $prefix  Application table prefix
     */
    public static function attempt(DBInterface $db, string $prefix): bool
    {
        $key = self::extractKey();
        if ($key === null) {
            return false;
        }

        $keyHash = hash('sha256', $key);

        try {
            $rows = $db->query(
                "SELECT id, label, privilege FROM {$prefix}api_keys
                  WHERE key_hash = ? AND revoked_at IS NULL LIMIT 1",
                [$keyHash],
                'read'
            );
        } catch (\Throwable $e) {
            // Table may not exist yet (migrations not run) — treat as no key.
            return false;
        }

        if (empty($rows)) {
            return false;
        }

        $row = $rows[0];

        // Update last_used_at asynchronously (non-critical, fire-and-forget).
        try {
            $db->query(
                "UPDATE {$prefix}api_keys SET last_used_at = ? WHERE key_hash = ?",
                [time(), $keyHash],
                'boolean'
            );
        } catch (\Throwable $e) {
            // Intentionally ignored.
        }

        // Privilege: use stored value; fall back to READ (30) for legacy rows
        // that were created before M006 added the column.
        $privilege = isset($row['privilege']) && $row['privilege'] !== null
            ? (int) $row['privilege']
            : \UAC\UAC::READ;

        // Populate CurrentUser so all existing privilege checks work unchanged.
        // id=0 is intentional — API keys have no user identity.
        CurrentUser::set([
            'id'         => 0,
            'name'       => 'API Key: ' . ($row['label'] ?? 'unknown'),
            'privilege'  => $privilege,
            'is_api_key' => true,
        ]);

        return true;
    }

    // ── Private helpers ───────────────────────────────────────────────────────

    private static function extractKey(): ?string
    {
        // 1. Authorization: Bearer {key}
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
