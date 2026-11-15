<?php

/**
 * @copyright 2007-2026 Julian Bogdani
 * @license AGPL-3.0; see LICENSE
 */

namespace Mcp;

/**
 * The token a validation tool returns and its write tool asks for, so that a write is
 * always preceded by a dry run of exactly the same payload: `expires.signature`,
 * an HMAC-SHA256 over the expiry, the scope (which pair of tools), the principal
 * (API key id or user id) and the canonical JSON of the payload, with a key derived
 * from the application secret. Nothing is stored.
 *
 * It proves that this payload was validated; it does not prove that the user agreed
 * (that is the client's confirmation) and it is not single-use: within its
 * lifetime the same call can be sent again (see dev/mcp.md).
 */
final class ValidationToken
{
    public const TTL = 900;

    /** A token for $payload, valid for TTL seconds (or until $expires). */
    public static function issue(string $scope, array $payload, Context $ctx, ?int $expires = null): string
    {
        $expires ??= time() + self::TTL;
        return $expires . '.' . self::sign($scope, $payload, $ctx, $expires);
    }

    /** Is $token the one issued for this scope, payload and principal, and still valid? */
    public static function valid(mixed $token, string $scope, array $payload, Context $ctx): bool
    {
        if (!is_string($token) || preg_match('/^(\d{1,12})\.[a-f0-9]{64}$/', $token, $m) !== 1 || (int) $m[1] < time()) {
            return false;
        }
        return hash_equals(self::issue($scope, $payload, $ctx, (int) $m[1]), $token);
    }

    private static function sign(string $scope, array $payload, Context $ctx, int $expires): string
    {
        $who = \Auth\CurrentUser::isApiKey()
            ? 'key:' . (int) \Auth\CurrentUser::get('key_id')
            : 'user:' . (int) \Auth\CurrentUser::id();
        $json = json_encode(self::canonical($payload), JSON_UNESCAPED_UNICODE);
        return hash_hmac('sha256', $expires . '|' . $scope . '|' . $who . '|' . $json, $ctx->signingKey);
    }

    /** Same payload, same text: object keys sorted, lists left in order. */
    private static function canonical(mixed $value): mixed
    {
        if (!is_array($value)) {
            return $value;
        }
        if (!array_is_list($value)) {
            ksort($value);
        }
        return array_map(self::canonical(...), $value);
    }
}
