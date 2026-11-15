<?php

/**
 * @copyright 2007-2026 Julian Bogdani
 * @license AGPL-3.0; see LICENSE
 */

namespace Auth;

/**
 * Where the current request comes from, recorded with every entry of the
 * record history (bdus_versions.channel, M053): `web` (the interface or any
 * JWT client), `api` (an API key on the REST API), `mcp`, `import`, `cli`.
 *
 * An entry point that is not the plain REST API names itself with set();
 * everything else is derived from the principal.
 */
class Channel
{
    private static ?string $name   = null;
    private static ?string $client = null;

    /** @param string|null $client  OAuth client the request acts through, when there is one */
    public static function set(?string $name, ?string $client = null): void
    {
        self::$name   = $name;
        self::$client = $client;
    }

    public static function get(): string
    {
        if (self::$name !== null) {
            return self::$name;
        }
        if (CurrentUser::isApiKey()) {
            return 'api';
        }
        return PHP_SAPI === 'cli' && !CurrentUser::isAuthenticated() ? 'cli' : 'web';
    }

    /** Id of the API key that authenticated the request, or null. */
    public static function keyId(): ?int
    {
        $id = CurrentUser::isApiKey() ? (int) CurrentUser::get('key_id') : 0;
        return $id ?: null;
    }

    public static function client(): ?string
    {
        return self::$client;
    }
}
