<?php

namespace Tests\Integration;

use Tests\Support\BdusTestCase;

/**
 * Integration tests for Login v5 endpoints:
 *   auth(), refresh(), out(), listApps().
 *
 * auth() internally calls Migrate::run(). All known migrations are pre-marked
 * as applied in seedData() so the runner skips them on the in-memory DB.
 */
class LoginCtrlTest extends BdusTestCase
{
    protected static string $testPassword = 'Test_1234!';

    // ── Seed extension ────────────────────────────────────────────────────────

    protected static function seedData(): void
    {
        parent::seedData();

        $hash = password_hash(static::$testPassword, PASSWORD_DEFAULT);
        static::$db->execInTransaction(
            "INSERT INTO bdus_users (id, name, email, password, privilege)
             VALUES (1, 'Test Admin', 'test@example.com', '{$hash}', 1)"
        );

        // Pre-mark every known migration as already applied so auth() → Migrate::run()
        // does nothing and leaves the in-memory schema intact.
        $now = time();
        foreach (\DB\System\Migrate::ALL_MIGRATIONS as $class) {
            $name = $class::NAME;
            static::$db->execInTransaction(
                "INSERT OR IGNORE INTO bdus_migrations (name, applied_at)
                 VALUES ('{$name}', {$now})"
            );
        }
    }

    // ── auth ──────────────────────────────────────────────────────────────────

    public function testAuthSuccessReturnsToken(): void
    {
        $ctrl = $this->makeController('Bdus\\Controllers\\Login', [], [
            'email'    => 'test@example.com',
            'password' => static::$testPassword,
        ]);
        $res = $this->callController($ctrl, 'auth');

        $this->assertSame('success', $res['status']);
        $this->assertSame('ok',      $res['code']);
        $this->assertArrayHasKey('token', $res);
        $this->assertNotEmpty($res['token']);
    }

    public function testAuthInvalidEmailFormatReturnsError(): void
    {
        $ctrl = $this->makeController('Bdus\\Controllers\\Login', [], [
            'email'    => 'not-an-email',
            'password' => 'anything',
        ]);
        $res = $this->callController($ctrl, 'auth');

        $this->assertSame('error',                  $res['status']);
        $this->assertSame('email_password_needed',  $res['code']);
    }

    public function testAuthEmptyPasswordReturnsError(): void
    {
        $ctrl = $this->makeController('Bdus\\Controllers\\Login', [], [
            'email'    => 'test@example.com',
            'password' => '',
        ]);
        $res = $this->callController($ctrl, 'auth');

        $this->assertSame('error',                 $res['status']);
        $this->assertSame('email_password_needed', $res['code']);
    }

    public function testAuthWrongPasswordReturnsError(): void
    {
        $ctrl = $this->makeController('Bdus\\Controllers\\Login', [], [
            'email'    => 'test@example.com',
            'password' => 'wrongpassword',
        ]);
        $res = $this->callController($ctrl, 'auth');

        $this->assertSame('error',                   $res['status']);
        $this->assertSame('login_data_not_valid',    $res['code']);
    }

    public function testAuthUnknownEmailReturnsError(): void
    {
        $ctrl = $this->makeController('Bdus\\Controllers\\Login', [], [
            'email'    => 'nobody@example.com',
            'password' => 'anything123',
        ]);
        $res = $this->callController($ctrl, 'auth');

        $this->assertSame('error',                $res['status']);
        $this->assertSame('login_data_not_valid', $res['code']);
    }

    // ── login throttling ─────────────────────────────────────────────────────

    private function attemptWrongPassword(): array
    {
        $ctrl = $this->makeController('Bdus\\Controllers\\Login', [], [
            'email'    => 'test@example.com',
            'password' => 'wrongpassword',
        ]);
        return $this->callController($ctrl, 'auth');
    }

    private function getUserThrottleState(): array
    {
        $row = static::$db->query(
            'SELECT failed_login_count, locked_until FROM bdus_users WHERE id = ?',
            [1],
            'read'
        );
        return $row[0];
    }

    // Tests in this class share one in-memory DB (recreated once per class,
    // not per method — see project_test_conventions memory), so throttling
    // state left by one test would otherwise leak into the next.
    private function resetThrottleState(): void
    {
        static::$db->execInTransaction(
            'UPDATE bdus_users SET failed_login_count = 0, locked_until = 0 WHERE id = 1'
        );
    }

    public function testAuthLocksAccountAfterMaxFailedAttempts(): void
    {
        $this->resetThrottleState();
        for ($i = 0; $i < 5; $i++) {
            $res = $this->attemptWrongPassword();
            $this->assertSame('login_data_not_valid', $res['code']);
        }

        $state = $this->getUserThrottleState();
        $this->assertSame(5, (int)$state['failed_login_count']);
        $this->assertGreaterThan(time(), (int)$state['locked_until']);

        // The 6th attempt hits the lock before even checking the password.
        $res = $this->attemptWrongPassword();
        $this->assertSame('error',           $res['status']);
        $this->assertSame('account_locked',  $res['code']);
    }

    public function testAuthLockedAccountRejectsEvenCorrectPassword(): void
    {
        $this->resetThrottleState();
        static::$db->execInTransaction(
            'UPDATE bdus_users SET failed_login_count = 5, locked_until = ' . (time() + 900) . ' WHERE id = 1'
        );

        $ctrl = $this->makeController('Bdus\\Controllers\\Login', [], [
            'email'    => 'test@example.com',
            'password' => static::$testPassword,
        ]);
        $res = $this->callController($ctrl, 'auth');

        $this->assertSame('error',          $res['status']);
        $this->assertSame('account_locked', $res['code']);
    }

    public function testAuthSuccessfulLoginResetsFailedCount(): void
    {
        $this->resetThrottleState();
        $this->attemptWrongPassword();
        $this->attemptWrongPassword();
        $this->assertSame(2, (int)$this->getUserThrottleState()['failed_login_count']);

        $ctrl = $this->makeController('Bdus\\Controllers\\Login', [], [
            'email'    => 'test@example.com',
            'password' => static::$testPassword,
        ]);
        $res = $this->callController($ctrl, 'auth');
        $this->assertSame('success', $res['status']);

        $state = $this->getUserThrottleState();
        $this->assertSame(0,   (int)$state['failed_login_count']);
        $this->assertSame(0, (int)$state['locked_until']);
    }

    public function testAuthExpiredLockClearsAndAllowsRetry(): void
    {
        static::$db->execInTransaction(
            'UPDATE bdus_users SET failed_login_count = 5, locked_until = ' . (time() - 60) . ' WHERE id = 1'
        );

        $ctrl = $this->makeController('Bdus\\Controllers\\Login', [], [
            'email'    => 'test@example.com',
            'password' => static::$testPassword,
        ]);
        $res = $this->callController($ctrl, 'auth');

        $this->assertSame('success', $res['status']);
        $state = $this->getUserThrottleState();
        $this->assertSame(0,   (int)$state['failed_login_count']);
        $this->assertSame(0, (int)$state['locked_until']);
    }

    /**
     * Simulates an app that hasn't run M039 yet: migrations only apply after
     * a successful login, so authenticate() must keep working (throttling
     * simply off) against a bdus_users table that doesn't have these columns
     * — otherwise every login on that app breaks with a SQL error before the
     * admin ever gets a chance to apply the upgrade. Regression test for a
     * real bug caught by manual verification, not by the fixture (BdusTestCase
     * always builds the current, fully-migrated schema).
     */
    public function testAuthWorksBeforeThrottlingColumnsExist(): void
    {
        static::$db->execInTransaction('ALTER TABLE bdus_users DROP COLUMN failed_login_count');
        static::$db->execInTransaction('ALTER TABLE bdus_users DROP COLUMN locked_until');

        try {
            $wrong = $this->attemptWrongPassword();
            $this->assertSame('login_data_not_valid', $wrong['code']);

            $ok = $this->makeController('Bdus\\Controllers\\Login', [], [
                'email'    => 'test@example.com',
                'password' => static::$testPassword,
            ]);
            $res = $this->callController($ok, 'auth');
            $this->assertSame('success', $res['status']);
        } finally {
            // Restore the schema so later tests in this class see the normal shape.
            static::$db->execInTransaction('ALTER TABLE bdus_users ADD COLUMN failed_login_count INTEGER NOT NULL DEFAULT 0');
            static::$db->execInTransaction('ALTER TABLE bdus_users ADD COLUMN locked_until INTEGER NOT NULL DEFAULT 0');
        }
    }

    // ── refresh ───────────────────────────────────────────────────────────────

    public function testRefreshReturnsNewToken(): void
    {
        // CurrentUser is already set as authenticated (privilege=1) by BdusTestCase.
        $ctrl = $this->makeController('Bdus\\Controllers\\Login');
        $res  = $this->callController($ctrl, 'refresh');

        $this->assertSame('success', $res['status']);
        $this->assertSame('ok',      $res['code']);
        $this->assertArrayHasKey('token', $res);
        $this->assertNotEmpty($res['token']);
    }

    // ── out ───────────────────────────────────────────────────────────────────

    public function testOutAlwaysReturnsSuccess(): void
    {
        $ctrl = $this->makeController('Bdus\\Controllers\\Login');
        $res  = $this->callController($ctrl, 'out');

        $this->assertSame('success', $res['status']);
        $this->assertSame('ok',      $res['code']);
    }

    // ── listApps ─────────────────────────────────────────────────────────────

    public function testListAppsReturnsExpectedShape(): void
    {
        // listApps reads from MAIN_DIR/projects/ on disk; just verify the envelope.
        $ctrl = $this->makeController('Bdus\\Controllers\\Login');
        $res  = $this->callController($ctrl, 'listApps');

        $this->assertSame('success', $res['status']);
        $this->assertArrayHasKey('apps', $res);
        $this->assertIsArray($res['apps']);
    }

    public function testListAppsRowShapeWhenAppsExist(): void
    {
        $ctrl = $this->makeController('Bdus\\Controllers\\Login');
        $res  = $this->callController($ctrl, 'listApps');

        if (empty($res['apps'])) {
            $this->markTestSkipped('No apps on disk — skipping row-shape assertion.');
        }

        $app = $res['apps'][0];
        foreach (['db', 'name', 'definition', 'oauth'] as $key) {
            $this->assertArrayHasKey($key, $app, "Missing key: $key");
        }
        $this->assertIsArray($app['oauth']);
    }
}
