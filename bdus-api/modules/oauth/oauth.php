<?php

/**
 * OAuth2 / SSO controller.
 *
 * Supported providers: google, orcid
 *
 * Flow
 * ────
 * 1. Frontend calls GET /api/auth/oauth/{provider}/redirect?app=APP&origin=ORIGIN
 *    → returns JSON { url: "..." }  (the provider authorization URL)
 *
 * 2. Frontend navigates (window.location.href) to that URL.
 *
 * 3. Provider redirects back to:
 *    GET /api/auth/oauth/{provider}/callback?app=APP&code=...&state=...
 *
 * 4. PHP verifies state, exchanges code for tokens, resolves the user,
 *    issues a BraDypUS JWT, and redirects the browser to:
 *    {origin}/#/oauth-callback?token={jwt}&app={app}
 *    On error:
 *    {origin}/#/oauth-callback?error={code}&app={app}
 *
 * Provider credentials are read from projects/{app}/config.json under the
 * key "oauth":
 *   {
 *     "oauth": {
 *       "google": { "client_id": "...", "client_secret": "..." },
 *       "orcid":  { "client_id": "...", "client_secret": "..." }
 *     }
 *   }
 *
 * User lookup
 * ──────────
 * On callback the controller tries:
 *   1. Match by (oauth_provider, oauth_sub) — returning user
 *   2. For Google only: match by email — auto-links the account on first use
 *   3. No match → error 'no_account' (admin must add the user first)
 *
 * ORCID note: ORCID's public API does not expose the user's email; match
 * is possible only via the ORCID iD (oauth_sub).  Admins must set the
 * oauth_sub field for ORCID users in advance.
 *
 * @copyright 2007-2025 Julian Bogdani
 * @license AGPL-3.0; see LICENSE
 */

use DB\System\Manage;

class oauth_ctrl extends Controller
{
    private const SUPPORTED = ['google', 'orcid'];

    // State token TTL in seconds (10 minutes)
    private const STATE_TTL = 600;

    // ── Public endpoints ─────────────────────────────────────────────────────

    /**
     * GET /api/auth/oauth/{provider}/redirect?app=APP&origin=ORIGIN
     *
     * Returns JSON { url: "..." } — the authorization URL to redirect to.
     * The caller (frontend) is responsible for navigating to it.
     */
    public function redirect(): void
    {
        $provider = $this->get['provider'] ?? '';
        $origin   = $this->get['origin']   ?? '';

        if (!in_array($provider, self::SUPPORTED, true)) {
            http_response_code(400);
            $this->returnJson(['status' => 'error', 'text' => 'unsupported_provider']);
            return;
        }

        if (!$origin) {
            http_response_code(400);
            $this->returnJson(['status' => 'error', 'text' => 'origin_required']);
            return;
        }

        $creds = $this->getCredentials($provider);
        if (!$creds) {
            http_response_code(503);
            $this->returnJson(['status' => 'error', 'text' => 'provider_not_configured']);
            return;
        }

        $state    = $this->buildState(APP, $origin);
        $oauthObj = $this->makeProvider($provider, $creds, APP);
        $url      = $oauthObj->getAuthorizationUrl([
            'state' => $state,
            'scope' => $this->scopes($provider),
        ]);

        $this->returnJson(['status' => 'success', 'url' => $url]);
    }

    /**
     * GET /api/auth/oauth/{provider}/callback?app=APP&code=...&state=...
     *
     * This is the redirect_uri: it must be reachable by the browser.
     * On success  → 302 to {origin}/#/oauth-callback?token=JWT&app=APP
     * On failure  → 302 to {origin}/#/oauth-callback?error=CODE&app=APP
     */
    public function callback(): void
    {
        $provider = $this->get['provider'] ?? '';
        $code     = $this->get['code']     ?? '';
        $state    = $this->get['state']    ?? '';

        if (!in_array($provider, self::SUPPORTED, true) || !$code || !$state) {
            $this->redirectError('invalid_request', '');
            return;
        }

        // Verify state and extract origin
        $stateData = $this->verifyState($state, APP);
        if (!$stateData) {
            $this->redirectError('invalid_state', '');
            return;
        }

        $origin = $stateData['origin'];

        $creds = $this->getCredentials($provider);
        if (!$creds) {
            $this->redirectError('provider_not_configured', $origin);
            return;
        }

        try {
            $oauthObj  = $this->makeProvider($provider, $creds, APP);
            $tokenObj  = $oauthObj->getAccessToken('authorization_code', ['code' => $code]);

            // ORCID includes the iD and name directly in the token response;
            // calling getResourceOwner() is unnecessary and would require a
            // per-user URL template substitution.
            $ownerData = ($provider === 'orcid')
                ? []
                : $oauthObj->getResourceOwner($tokenObj)->toArray();

            [$sub, $email, $name] = $this->extractIdentity($provider, $tokenObj, $ownerData);

            $user = $this->resolveUser($provider, $sub, $email);
            if (!$user) {
                $this->redirectError('no_account', $origin);
                return;
            }

            // Auto-link: store oauth_sub on first login via this provider
            if (empty($user['oauth_sub'])) {
                $mgr = new Manage($this->db);
                $mgr->editRow('bdus_users', (int) $user['id'], [
                    'oauth_provider' => $provider,
                    'oauth_sub'      => $sub,
                ]);
            }

            $token = \JWT\JwtManager::generate($user, APP);
            $this->log->info("OAuth2 login via {$provider} for user {$user['id']}");

            $this->redirectSuccess($token, APP, $origin);

        } catch (\Throwable $e) {
            $this->log->error($e);
            $this->redirectError('oauth_error', $origin);
        }
    }

    // ── State token helpers ──────────────────────────────────────────────────

    /**
     * Build a signed state token: base64url(payload).signature
     *
     * Payload JSON: { app, origin, nonce, ts }
     */
    private function buildState(string $app, string $origin): string
    {
        $payload = base64_encode(json_encode([
            'app'    => $app,
            'origin' => $origin,
            'nonce'  => bin2hex(random_bytes(16)),
            'ts'     => time(),
        ]));

        $sig = hash_hmac('sha256', $payload, $this->jwtSecret($app));
        return $payload . '.' . $sig;
    }

    /**
     * Verify a state token; return the decoded payload array or null on failure.
     */
    private function verifyState(string $state, string $app): ?array
    {
        $parts = explode('.', $state, 2);
        if (count($parts) !== 2) {
            return null;
        }

        [$payload, $sig] = $parts;

        $expected = hash_hmac('sha256', $payload, $this->jwtSecret($app));
        if (!hash_equals($expected, $sig)) {
            return null;
        }

        $data = json_decode(base64_decode($payload), true);
        if (!is_array($data) || ($data['ts'] + self::STATE_TTL) < time()) {
            return null;
        }

        return $data;
    }

    private function jwtSecret(string $app): string
    {
        $base = MAIN_DIR . "projects/{$app}";
        foreach (["{$base}/.jwt_secret", "{$base}/cfg/.jwt_secret"] as $c) {
            if (file_exists($c)) {
                return trim(file_get_contents($c));
            }
        }
        // Fallback: generate (mirrors JwtManager behaviour)
        $secret = bin2hex(random_bytes(32));
        file_put_contents("{$base}/.jwt_secret", $secret);
        chmod("{$base}/.jwt_secret", 0600);
        return $secret;
    }

    // ── Provider factory ─────────────────────────────────────────────────────

    /**
     * @return \League\OAuth2\Client\Provider\AbstractProvider
     */
    private function makeProvider(string $provider, array $creds, string $app): object
    {
        $callbackUrl = $this->callbackUrl($provider, $app);

        switch ($provider) {
            case 'google':
                return new \League\OAuth2\Client\Provider\Google([
                    'clientId'     => $creds['client_id'],
                    'clientSecret' => $creds['client_secret'],
                    'redirectUri'  => $callbackUrl,
                ]);

            case 'orcid':
                // ORCID iD and display name are returned in the token response
                // itself (no separate userinfo call needed for /authenticate scope).
                // urlResourceOwnerDetails is required by GenericProvider but never
                // called — we pass a placeholder and skip getResourceOwner().
                return new \League\OAuth2\Client\Provider\GenericProvider([
                    'clientId'                => $creds['client_id'],
                    'clientSecret'            => $creds['client_secret'],
                    'redirectUri'             => $callbackUrl,
                    'urlAuthorize'            => 'https://orcid.org/oauth/authorize',
                    'urlAccessToken'          => 'https://orcid.org/oauth/token',
                    'urlResourceOwnerDetails' => 'https://orcid.org/oauth/userinfo',
                    'scopeSeparator'          => ' ',
                ]);
        }

        throw new \InvalidArgumentException("Unknown provider: {$provider}");
    }

    private function scopes(string $provider): array
    {
        return match ($provider) {
            'google' => ['openid', 'email', 'profile'],
            'orcid'  => ['/authenticate'],
            default  => [],
        };
    }

    // ── Identity extraction ──────────────────────────────────────────────────

    /**
     * Extract (sub, email, name) from provider token / resource owner.
     *
     * @return array{string, string|null, string|null}
     */
    private function extractIdentity(string $provider, $tokenObj, array $owner): array
    {
        switch ($provider) {
            case 'google':
                // Google resource owner exposes getId(), getEmail(), getName()
                $sub   = $owner['sub']   ?? $owner['id']    ?? '';
                $email = $owner['email'] ?? null;
                $name  = $owner['name']  ?? null;
                return [$sub, $email, $name];

            case 'orcid':
                // ORCID token response contains an 'orcid' field with the iD.
                // The resource-owner URL is per-user and doesn't provide email
                // via the public API. We extract the iD from the token values.
                $values = $tokenObj->getValues();
                $sub    = $values['orcid'] ?? ($owner['orcid-identifier']['path'] ?? '');
                // ORCID public API does not return email
                $name = null;
                if (isset($owner['name'])) {
                    $given  = $owner['name']['given-names']['value']  ?? '';
                    $family = $owner['name']['family-name']['value']  ?? '';
                    $name   = trim("$given $family") ?: null;
                }
                return [$sub, null, $name];
        }

        return ['', null, null];
    }

    // ── User resolution ──────────────────────────────────────────────────────

    /**
     * Resolve the BraDypUS user from OAuth identity.
     *
     * Strategy:
     *  1. Match by (oauth_provider, oauth_sub)
     *  2. Match by email (Google only, auto-links on first use)
     *  3. null → no account
     */
    private function resolveUser(string $provider, string $sub, ?string $email): ?array
    {
        $mgr = new Manage($this->db);

        // 1. Sub match
        if ($sub) {
            $rows = $mgr->getBySQL('bdus_users', 'oauth_provider = ? AND oauth_sub = ?', [$provider, $sub]);
            if (!empty($rows[0])) {
                $u = $rows[0];
                unset($u['password'], $u['settings']);
                return $u;
            }
        }

        // 2. Email match (Google only)
        if ($email && $provider === 'google') {
            $rows = $mgr->getBySQL('bdus_users', 'email = ?', [$email]);
            if (!empty($rows[0])) {
                $u = $rows[0];
                unset($u['password'], $u['settings']);
                return $u;
            }
        }

        return null;
    }

    // ── Redirect helpers ─────────────────────────────────────────────────────

    private function redirectSuccess(string $token, string $app, string $origin): void
    {
        $url = rtrim($origin, '/') . '/#/oauth-callback?'
             . http_build_query(['token' => $token, 'app' => $app]);
        header("Location: {$url}", true, 302);
        exit;
    }

    private function redirectError(string $code, string $origin): void
    {
        if (!$origin) {
            // Last-resort: no origin available (state was invalid)
            http_response_code(400);
            header('Content-Type: application/json');
            echo json_encode(['status' => 'error', 'text' => $code]);
            exit;
        }
        $url = rtrim($origin, '/') . '/#/oauth-callback?'
             . http_build_query(['error' => $code, 'app' => APP]);
        header("Location: {$url}", true, 302);
        exit;
    }

    private function callbackUrl(string $provider, string $app): string
    {
        $scheme = (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off') ? 'https' : 'http';
        $host   = $_SERVER['HTTP_HOST'] ?? 'localhost';
        $base   = rtrim(dirname($_SERVER['SCRIPT_NAME']), '/');
        return "{$scheme}://{$host}{$base}/api/auth/oauth/{$provider}/callback?app={$app}";
    }

    // ── Config helpers ───────────────────────────────────────────────────────

    private function getCredentials(string $provider): ?array
    {
        $configFile = MAIN_DIR . "projects/" . APP . "/config.json";
        if (!file_exists($configFile)) {
            return null;
        }
        $cfg = json_decode(file_get_contents($configFile), true);
        $creds = $cfg['oauth'][$provider] ?? null;
        if (!is_array($creds) || empty($creds['client_id']) || empty($creds['client_secret'])) {
            return null;
        }
        return $creds;
    }
}
