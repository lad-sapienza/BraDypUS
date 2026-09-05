<?php

namespace Tests\Integration;

use Tests\Support\BdusTestCase;

/**
 * Integration tests for OAuth::link() and OAuth::register() — the two
 * follow-up endpoints redeemed from a `pending` token issued on a
 * no_account redirect (see OAuth::callback()), added for issue #40:
 *
 *   - link()     attaches an OAuth identity to an existing account after
 *                verifying its password (reuses Login::authenticate(), so
 *                the anti-brute-force throttling from #26 applies here too).
 *   - register() self-signup with only an email (the provider — typically
 *                ORCID, which never exposes one to auto-match on — already
 *                proved the identity); same allow_self_registration +
 *                Mailer gate as Login::register().
 *
 * Mailer::isConfigured() is false in this environment, so — exactly like
 * LoginRegistrationAndResetTest — register() is exercised up to that gate,
 * never past it (no real network call). link() has no mail dependency, so
 * its full success path (including the actual DB write of oauth_provider/
 * oauth_sub) is covered here.
 *
 * Pending tokens are built locally with the same base64+HMAC shape as
 * OAuth::buildPendingIdentity(), signed with the same per-app secret file
 * OAuth::jwtSecret() reads — so a token built here verifies exactly like
 * one issued by a real callback() redirect.
 */
class OAuthLinkingTest extends BdusTestCase
{
    protected static string $testPassword = 'Test_1234!';

    private string $projDir;
    private string $secretFile;
    private string $secret;

    protected static function seedData(): void
    {
        parent::seedData();

        $hash = password_hash(static::$testPassword, PASSWORD_DEFAULT);
        static::$db->execInTransaction(
            "INSERT INTO bdus_users (id, name, email, password, privilege)
             VALUES (1, 'Test Admin', 'test@example.com', '{$hash}', 1)"
        );
        static::$db->execInTransaction(
            "INSERT INTO bdus_users (id, name, email, password, privilege)
             VALUES (2, 'Second User', 'second@example.com', '{$hash}', 10)"
        );

        static::$db->query(
            'INSERT INTO bdus_cfg_app (id, status, max_image_size, welcome) VALUES (?, ?, ?, ?)',
            [1, 'on', 1500, ''],
            'boolean'
        );

        // createSchema() builds bdus_users from the current Structure JSON
        // (columns only — the partial unique index on (oauth_provider,
        // oauth_sub) is added by this migration, not the base structure).
        // Run it explicitly so testLinkRejectsIdentityAlreadyClaimedByAnotherUser
        // exercises a real constraint violation, matching any real app (M022
        // has already shipped, so every deployed bdus_users has this index).
        \DB\System\Migrations\M022_AddOAuthToUsers::run(new \DB\System\Manage(static::$db));
    }

    protected function setUp(): void
    {
        // Same real project dir OAuthCtrlTest uses for APP='test' (see tests/bootstrap.php).
        $this->projDir    = MAIN_DIR . 'projects/test/';
        $this->secretFile = $this->projDir . '.jwt_secret';

        if (!is_dir($this->projDir)) {
            mkdir($this->projDir, 0755, true);
        }
        // Reuse the secret if OAuth::jwtSecret() already created one (e.g. a
        // prior test class ran first); otherwise seed one ourselves. Either
        // way $this->secret ends up matching exactly what the controller
        // will independently read from the same file.
        if (!file_exists($this->secretFile)) {
            file_put_contents($this->secretFile, bin2hex(random_bytes(32)));
        }
        $this->secret = trim(file_get_contents($this->secretFile));
    }

    /** Same base64+HMAC shape as the private OAuth::buildPendingIdentity(). */
    private function makePendingToken(string $provider, string $sub, ?string $name, ?int $ts = null): string
    {
        $payload = base64_encode(json_encode([
            'provider' => $provider,
            'sub'      => $sub,
            'name'     => $name,
            'app'      => 'test',
            'ts'       => $ts ?? time(),
        ]));
        $sig = hash_hmac('sha256', $payload, $this->secret);
        return $payload . '.' . $sig;
    }

    // ── link() ───────────────────────────────────────────────────────────────

    public function testLinkRejectsGarbagePendingToken(): void
    {
        $ctrl = $this->makeController('Bdus\\Controllers\\OAuth', [], [
            'app' => 'test', 'pending' => 'not-a-real-token',
            'email' => 'test@example.com', 'password' => static::$testPassword,
        ]);
        $res = $this->callController($ctrl, 'link');

        $this->assertSame('error', $res['status']);
        $this->assertSame('invalid_or_expired_pending', $res['code']);
    }

    public function testLinkRejectsExpiredPendingToken(): void
    {
        $token = $this->makePendingToken('orcid', '0000-0001-2345-6789', 'Jane Doe', time() - 700);

        $ctrl = $this->makeController('Bdus\\Controllers\\OAuth', [], [
            'app' => 'test', 'pending' => $token,
            'email' => 'test@example.com', 'password' => static::$testPassword,
        ]);
        $res = $this->callController($ctrl, 'link');

        $this->assertSame('error', $res['status']);
        $this->assertSame('invalid_or_expired_pending', $res['code']);
    }

    public function testLinkRejectsWrongPassword(): void
    {
        $token = $this->makePendingToken('orcid', '0000-0001-1111-1111', 'Jane Doe');

        $ctrl = $this->makeController('Bdus\\Controllers\\OAuth', [], [
            'app' => 'test', 'pending' => $token,
            'email' => 'test@example.com', 'password' => 'WrongPassword!',
        ]);
        $res = $this->callController($ctrl, 'link');

        $this->assertSame('error', $res['status']);
        $this->assertSame('login_data_not_valid', $res['code']);

        $row = static::$db->query('SELECT oauth_provider, oauth_sub FROM bdus_users WHERE id = 1', [], 'read')[0];
        $this->assertNull($row['oauth_provider']);
        $this->assertNull($row['oauth_sub']);
    }

    public function testLinkSucceedsAndSetsOauthIdentity(): void
    {
        $token = $this->makePendingToken('orcid', '0000-0001-2222-3333', 'Test Admin');

        $ctrl = $this->makeController('Bdus\\Controllers\\OAuth', [], [
            'app' => 'test', 'pending' => $token,
            'email' => 'test@example.com', 'password' => static::$testPassword,
        ]);
        $res = $this->callController($ctrl, 'link');

        $this->assertSame('success', $res['status']);
        $this->assertSame('ok', $res['code']);
        $this->assertNotEmpty($res['token']);

        $row = static::$db->query('SELECT oauth_provider, oauth_sub FROM bdus_users WHERE id = 1', [], 'read')[0];
        $this->assertSame('orcid', $row['oauth_provider']);
        $this->assertSame('0000-0001-2222-3333', $row['oauth_sub']);
    }

    public function testLinkRejectsIdentityAlreadyClaimedByAnotherUser(): void
    {
        $sub = '0000-0001-4444-5555';

        // First: user 1 successfully links this identity.
        $token1 = $this->makePendingToken('orcid', $sub, 'Test Admin');
        $ctrl1  = $this->makeController('Bdus\\Controllers\\OAuth', [], [
            'app' => 'test', 'pending' => $token1,
            'email' => 'test@example.com', 'password' => static::$testPassword,
        ]);
        $this->assertSame('success', $this->callController($ctrl1, 'link')['status']);

        // Then: user 2 tries to claim the very same (provider, sub) pair.
        $token2 = $this->makePendingToken('orcid', $sub, 'Second User');
        $ctrl2  = $this->makeController('Bdus\\Controllers\\OAuth', [], [
            'app' => 'test', 'pending' => $token2,
            'email' => 'second@example.com', 'password' => static::$testPassword,
        ]);
        $res2 = $this->callController($ctrl2, 'link');

        $this->assertSame('error', $res2['status']);
        $this->assertSame('oauth_identity_already_linked', $res2['code']);
    }

    // ── register() ───────────────────────────────────────────────────────────
    // Mailer::isConfigured() is false in this environment (no RESEND_API_KEY /
    // MAIL_FROM_ADDRESS set) — every gate up to it is exercised, exactly like
    // LoginRegistrationAndResetTest, without ever attempting a real send().

    public function testRegisterRejectsGarbagePendingToken(): void
    {
        $ctrl = $this->makeController('Bdus\\Controllers\\OAuth', [], [
            'app' => 'test', 'pending' => 'not-a-real-token', 'email' => 'new@example.com',
        ]);
        $res = $this->callController($ctrl, 'register');

        $this->assertSame('error', $res['status']);
        $this->assertSame('invalid_or_expired_pending', $res['code']);
    }

    public function testRegisterRejectsInvalidEmail(): void
    {
        $token = $this->makePendingToken('orcid', '0000-0002-1111-2222', 'New Person');

        $ctrl = $this->makeController('Bdus\\Controllers\\OAuth', [], [
            'app' => 'test', 'pending' => $token, 'email' => 'not-an-email',
        ]);
        $res = $this->callController($ctrl, 'register');

        $this->assertSame('error', $res['status']);
        $this->assertSame('email_not_valid', $res['code']);
    }

    public function testRegisterFailsWhenSelfRegistrationDisabled(): void
    {
        // Default seeded state: allow_self_registration = 0.
        $token = $this->makePendingToken('orcid', '0000-0002-3333-4444', 'New Person');

        $ctrl = $this->makeController('Bdus\\Controllers\\OAuth', [], [
            'app' => 'test', 'pending' => $token, 'email' => 'new@example.com',
        ]);
        $res = $this->callController($ctrl, 'register');

        $this->assertSame('error', $res['status']);
        $this->assertSame('registration_not_available', $res['code']);
    }

    public function testRegisterFailsWhenMailNotConfiguredEvenIfEnabled(): void
    {
        static::$db->execInTransaction('UPDATE bdus_cfg_app SET allow_self_registration = 1 WHERE id = 1');

        $token = $this->makePendingToken('orcid', '0000-0002-5555-6666', 'New Person');
        $ctrl  = $this->makeController('Bdus\\Controllers\\OAuth', [], [
            'app' => 'test', 'pending' => $token, 'email' => 'new2@example.com',
        ]);
        $res = $this->callController($ctrl, 'register');

        $this->assertSame('error', $res['status']);
        $this->assertSame('email_not_configured', $res['code']);

        static::$db->execInTransaction('UPDATE bdus_cfg_app SET allow_self_registration = 0 WHERE id = 1');
    }
}
