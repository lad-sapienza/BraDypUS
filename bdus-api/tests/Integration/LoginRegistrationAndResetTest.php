<?php

namespace Tests\Integration;

use Tests\Support\BdusTestCase;

/**
 * Integration tests for Login::register(), requestPasswordReset() and
 * confirmPasswordReset() — the two email-backed public flows added for
 * issue #24.
 *
 * Mailer::isConfigured() is false in this environment (no RESEND_API_KEY /
 * MAIL_FROM_ADDRESS set), so these tests exercise every gate and every bit
 * of DB logic (token generation/hashing/expiry, throttle-lock clearing,
 * privilege=40 on self-registered users) without ever attempting a real
 * network call — see MailTest for the network-free Mailer/Templates unit
 * coverage, and Login.php's docblock for why an actual send() is untested.
 */
class LoginRegistrationAndResetTest extends BdusTestCase
{
    protected static string $testPassword = 'Test_1234!';

    protected static function seedData(): void
    {
        parent::seedData();

        $hash = password_hash(static::$testPassword, PASSWORD_DEFAULT);
        static::$db->execInTransaction(
            "INSERT INTO bdus_users (id, name, email, password, privilege)
             VALUES (1, 'Test Admin', 'test@example.com', '{$hash}', 1)"
        );

        static::$db->query(
            'INSERT INTO bdus_cfg_app (id, status, max_image_size, welcome) VALUES (?, ?, ?, ?)',
            [1, 'on', 1500, ''],
            'boolean'
        );

        $now = time();
        foreach (\DB\System\Migrate::ALL_MIGRATIONS as $class) {
            $name = $class::NAME;
            static::$db->execInTransaction(
                "INSERT OR IGNORE INTO bdus_migrations (name, applied_at)
                 VALUES ('{$name}', {$now})"
            );
        }
    }

    // ── register() ────────────────────────────────────────────────────────────

    public function testRegisterFailsWithMissingFields(): void
    {
        $ctrl = $this->makeController('Bdus\\Controllers\\Login', [], ['app' => 'test']);
        $res  = $this->callController($ctrl, 'register');

        $this->assertSame('error', $res['status']);
        $this->assertSame('all_fields_required', $res['code']);
    }

    public function testRegisterFailsWithInvalidEmail(): void
    {
        $ctrl = $this->makeController('Bdus\\Controllers\\Login', [], [
            'app' => 'test', 'name' => 'New User', 'email' => 'not-an-email', 'password' => 'Whatever1!',
        ]);
        $res = $this->callController($ctrl, 'register');

        $this->assertSame('error', $res['status']);
        $this->assertSame('email_not_valid', $res['code']);
    }

    public function testRegisterFailsWithShortPassword(): void
    {
        $ctrl = $this->makeController('Bdus\\Controllers\\Login', [], [
            'app' => 'test', 'name' => 'New User', 'email' => 'new@example.com', 'password' => 'short',
        ]);
        $res = $this->callController($ctrl, 'register');

        $this->assertSame('error', $res['status']);
        $this->assertSame('password_too_short', $res['code']);
    }

    public function testRegisterFailsWhenSelfRegistrationDisabled(): void
    {
        // Default seeded state: allow_self_registration = 0.
        $ctrl = $this->makeController('Bdus\\Controllers\\Login', [], [
            'app' => 'test', 'name' => 'New User', 'email' => 'new@example.com', 'password' => 'Whatever1!',
        ]);
        $res = $this->callController($ctrl, 'register');

        $this->assertSame('error', $res['status']);
        $this->assertSame('registration_not_available', $res['code']);
    }

    public function testRegisterFailsWhenMailNotConfiguredEvenIfEnabled(): void
    {
        static::$db->execInTransaction('UPDATE bdus_cfg_app SET allow_self_registration = 1 WHERE id = 1');

        $ctrl = $this->makeController('Bdus\\Controllers\\Login', [], [
            'app' => 'test', 'name' => 'New User', 'email' => 'new2@example.com', 'password' => 'Whatever1!',
        ]);
        $res = $this->callController($ctrl, 'register');

        $this->assertSame('error', $res['status']);
        $this->assertSame('email_not_configured', $res['code']);

        static::$db->execInTransaction('UPDATE bdus_cfg_app SET allow_self_registration = 0 WHERE id = 1');
    }

    // ── requestPasswordReset() ───────────────────────────────────────────────

    public function testRequestPasswordResetFailsWhenMailNotConfigured(): void
    {
        $ctrl = $this->makeController('Bdus\\Controllers\\Login', [], [
            'app' => 'test', 'email' => 'test@example.com',
        ]);
        $res = $this->callController($ctrl, 'requestPasswordReset');

        $this->assertSame('error', $res['status']);
        $this->assertSame('email_not_configured', $res['code']);
    }

    // ── confirmPasswordReset() ────────────────────────────────────────────────
    // Seeds a valid token directly (bypassing requestPasswordReset(), which
    // needs mail configured) to exercise the security-critical confirm path
    // without any network dependency.

    private function seedResetToken(string $token, int $expiresInSeconds): void
    {
        static::$db->execInTransaction(
            "UPDATE bdus_users SET reset_token_hash = '" . hash('sha256', $token) . "', "
            . 'reset_token_expires = ' . (time() + $expiresInSeconds) . ' WHERE id = 1'
        );
    }

    public function testConfirmPasswordResetSucceedsWithValidToken(): void
    {
        $this->seedResetToken('valid-token-123', 3600);

        $ctrl = $this->makeController('Bdus\\Controllers\\Login', [], [
            'app' => 'test', 'email' => 'test@example.com', 'token' => 'valid-token-123', 'password' => 'NewPass1234!',
        ]);
        $res = $this->callController($ctrl, 'confirmPasswordReset');

        $this->assertSame('success', $res['status']);
        $this->assertSame('ok_password_update', $res['code']);

        $row = static::$db->query('SELECT password, reset_token_hash, reset_token_expires FROM bdus_users WHERE id = 1', [], 'read')[0];
        $this->assertSame('', $row['reset_token_hash']);
        $this->assertSame(0, (int) $row['reset_token_expires']);
        $this->assertTrue(password_verify('NewPass1234!', $row['password']));
    }

    public function testConfirmPasswordResetClearsThrottleLock(): void
    {
        $this->seedResetToken('valid-token-456', 3600);
        static::$db->execInTransaction(
            'UPDATE bdus_users SET failed_login_count = 5, locked_until = ' . (time() + 900) . ' WHERE id = 1'
        );

        $ctrl = $this->makeController('Bdus\\Controllers\\Login', [], [
            'app' => 'test', 'email' => 'test@example.com', 'token' => 'valid-token-456', 'password' => 'NewPass1234!',
        ]);
        $res = $this->callController($ctrl, 'confirmPasswordReset');
        $this->assertSame('success', $res['status']);

        $row = static::$db->query('SELECT failed_login_count, locked_until FROM bdus_users WHERE id = 1', [], 'read')[0];
        $this->assertSame(0, (int) $row['failed_login_count']);
        $this->assertSame(0, (int) $row['locked_until']);
    }

    public function testConfirmPasswordResetRejectsWrongToken(): void
    {
        $this->seedResetToken('valid-token-789', 3600);

        $ctrl = $this->makeController('Bdus\\Controllers\\Login', [], [
            'app' => 'test', 'email' => 'test@example.com', 'token' => 'wrong-token', 'password' => 'NewPass1234!',
        ]);
        $res = $this->callController($ctrl, 'confirmPasswordReset');

        $this->assertSame('error', $res['status']);
        $this->assertSame('invalid_or_expired_reset_token', $res['code']);
    }

    public function testConfirmPasswordResetRejectsExpiredToken(): void
    {
        $this->seedResetToken('expired-token', -60);

        $ctrl = $this->makeController('Bdus\\Controllers\\Login', [], [
            'app' => 'test', 'email' => 'test@example.com', 'token' => 'expired-token', 'password' => 'NewPass1234!',
        ]);
        $res = $this->callController($ctrl, 'confirmPasswordReset');

        $this->assertSame('error', $res['status']);
        $this->assertSame('invalid_or_expired_reset_token', $res['code']);
    }

    public function testConfirmPasswordResetRejectsUnknownEmail(): void
    {
        $ctrl = $this->makeController('Bdus\\Controllers\\Login', [], [
            'app' => 'test', 'email' => 'nobody@example.com', 'token' => 'anything', 'password' => 'NewPass1234!',
        ]);
        $res = $this->callController($ctrl, 'confirmPasswordReset');

        $this->assertSame('error', $res['status']);
        $this->assertSame('invalid_or_expired_reset_token', $res['code']);
    }

    public function testConfirmPasswordResetRejectsShortPassword(): void
    {
        $this->seedResetToken('valid-token-short', 3600);

        $ctrl = $this->makeController('Bdus\\Controllers\\Login', [], [
            'app' => 'test', 'email' => 'test@example.com', 'token' => 'valid-token-short', 'password' => 'short',
        ]);
        $res = $this->callController($ctrl, 'confirmPasswordReset');

        $this->assertSame('error', $res['status']);
        $this->assertSame('password_too_short', $res['code']);
    }

    public function testConfirmPasswordResetTokenIsSingleUse(): void
    {
        $this->seedResetToken('single-use-token', 3600);

        $ctrl1 = $this->makeController('Bdus\\Controllers\\Login', [], [
            'app' => 'test', 'email' => 'test@example.com', 'token' => 'single-use-token', 'password' => 'NewPass1234!',
        ]);
        $this->assertSame('success', $this->callController($ctrl1, 'confirmPasswordReset')['status']);

        // Same token again must fail — it was cleared after first use.
        $ctrl2 = $this->makeController('Bdus\\Controllers\\Login', [], [
            'app' => 'test', 'email' => 'test@example.com', 'token' => 'single-use-token', 'password' => 'AnotherPass1!',
        ]);
        $res2 = $this->callController($ctrl2, 'confirmPasswordReset');
        $this->assertSame('error', $res2['status']);
        $this->assertSame('invalid_or_expired_reset_token', $res2['code']);
    }
}
