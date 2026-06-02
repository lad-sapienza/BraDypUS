<?php

namespace Tests\Integration;

use Tests\Support\BdusTestCase;

/**
 * Integration tests for Login v5 endpoints:
 *   auth(), refresh(), out(), listApps().
 *
 * auth() internally calls Migrate::run(). To prevent all migrations from
 * re-running on the lightweight in-memory DB, we pre-populate bdus_migrations
 * with every known migration name in seedData().
 */
class LoginCtrlTest extends BdusTestCase
{
    protected static string $testPassword = 'Test_1234!';

    // ── Schema extension ──────────────────────────────────────────────────────

    protected static function createSchema(): void
    {
        parent::createSchema();

        static::$db->execInTransaction('
            CREATE TABLE bdus_users (
                id             INTEGER PRIMARY KEY AUTOINCREMENT,
                name           TEXT    NOT NULL,
                email          TEXT    NOT NULL,
                password       TEXT    NOT NULL,
                privilege      INTEGER NOT NULL,
                settings       TEXT,
                oauth_provider TEXT,
                oauth_sub      TEXT,
                token_version  INTEGER NOT NULL DEFAULT 0
            )
        ');

        // Extra system tables that migrations may try to CREATE IF NOT EXISTS.
        // We pre-create them as stubs so the migration runner does not error.
        static::$db->execInTransaction('
            CREATE TABLE IF NOT EXISTS bdus_queries (
                id         INTEGER PRIMARY KEY AUTOINCREMENT,
                user_id    INTEGER NOT NULL,
                created_at INTEGER NOT NULL,
                name       TEXT    NOT NULL,
                tb         TEXT    NOT NULL,
                query      TEXT,
                is_global  INTEGER NOT NULL DEFAULT 0
            )
        ');
    }

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
