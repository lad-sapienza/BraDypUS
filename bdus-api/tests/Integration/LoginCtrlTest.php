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
