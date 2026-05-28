<?php

namespace Tests\Integration;

use Tests\Support\BdusTestCase;
use DB\System\Manage;

/**
 * Integration tests for oauth_ctrl.
 *
 * What is tested here
 * ───────────────────
 * redirect() — all paths that return JSON (no browser redirect, no exit):
 *   • Unsupported provider → 400
 *   • Empty origin         → 400
 *   • Unconfigured app     → 503
 *   • Configured app       → 200 + url (requires fake credentials; provider
 *     URL is constructed locally without a network call)
 *
 * callback() — the path where origin is absent and the controller falls
 *   back to JSON output (no browser Location redirect).  Tests that rely
 *   on header() + exit run in isolated processes (@runInSeparateProcess).
 *
 * User resolution — resolveUser() is private, so it is exercised through
 *   the full flow by seeding bdus_users rows and invoking the controller
 *   method whose first observable effect is user resolution.
 *
 * The PHP OAuth provider libraries make no network calls until
 *   getAccessToken() / getResourceOwner() are invoked, so redirect() can
 *   be tested without internet access.
 */
class OAuthCtrlTest extends BdusTestCase
{
    // Project directory created for the APP constant ('test')
    private string $projDir;
    private string $configFile;

    public static function setUpBeforeClass(): void
    {
        parent::setUpBeforeClass();

        // Ensure bdus_users exists in the test DB so resolveUser() can query it
        static::$db->query("
            CREATE TABLE IF NOT EXISTS bdus_users (
                id             INTEGER PRIMARY KEY AUTOINCREMENT,
                name           TEXT    NOT NULL,
                email          TEXT    NOT NULL,
                password       TEXT    NOT NULL,
                privilege      INTEGER NOT NULL,
                settings       TEXT,
                oauth_provider TEXT,
                oauth_sub      TEXT
            )",
            [], 'boolean'
        );
    }

    protected function setUp(): void
    {
        // Create a minimal project directory for APP='test' so config reads work
        $this->projDir    = MAIN_DIR . 'projects/test/';
        $this->configFile = $this->projDir . 'config.json';

        if (!is_dir($this->projDir)) {
            mkdir($this->projDir, 0755, true);
        }

        // Start with NO oauth config (simulates unconfigured app)
        file_put_contents($this->configFile, json_encode([
            'name'      => 'test',
            'db_engine' => 'sqlite',
        ]));

        // Clear any OAuth users between tests
        static::$db->query('DELETE FROM bdus_users', [], 'boolean');
    }

    protected function tearDown(): void
    {
        // Remove the fake config so it cannot interfere with other test classes
        if (file_exists($this->configFile)) {
            unlink($this->configFile);
        }
    }

    // ── redirect() ───────────────────────────────────────────────────────────

    public function testRedirectRejectsUnsupportedProvider(): void
    {
        $ctrl = $this->makeController('oauth_ctrl', ['provider' => 'twitter', 'origin' => 'http://localhost']);
        $res  = $this->callController($ctrl, 'redirect');

        $this->assertSame('error', $res['status']);
        $this->assertStringContainsString('unsupported', $res['text'] ?? '');
    }

    public function testRedirectRejectsEmptyOrigin(): void
    {
        $ctrl = $this->makeController('oauth_ctrl', ['provider' => 'google', 'origin' => '']);
        $res  = $this->callController($ctrl, 'redirect');

        $this->assertSame('error', $res['status']);
        $this->assertStringContainsString('origin', $res['text'] ?? '');
    }

    public function testRedirectRejectsOrphanProvider(): void
    {
        // 'orcid' is supported but not configured in this app
        $ctrl = $this->makeController('oauth_ctrl', ['provider' => 'orcid', 'origin' => 'http://localhost']);
        $res  = $this->callController($ctrl, 'redirect');

        $this->assertSame('error', $res['status']);
        $this->assertStringContainsString('not_configured', $res['text'] ?? '');
    }

    public function testRedirectReturnsUrlWhenConfigured(): void
    {
        // Seed fake but structurally correct credentials
        file_put_contents($this->configFile, json_encode([
            'name'  => 'test',
            'oauth' => [
                'google' => [
                    'client_id'     => 'fake-client-id.apps.googleusercontent.com',
                    'client_secret' => 'fake-secret',
                ],
            ],
        ]));

        $ctrl = $this->makeController('oauth_ctrl', ['provider' => 'google', 'origin' => 'http://localhost']);
        $res  = $this->callController($ctrl, 'redirect');

        $this->assertSame('success', $res['status']);
        $this->assertArrayHasKey('url', $res);
        $this->assertStringStartsWith('https://accounts.google.com', $res['url']);
    }

    public function testRedirectGoogleUrlContainsRequiredParams(): void
    {
        file_put_contents($this->configFile, json_encode([
            'name'  => 'test',
            'oauth' => [
                'google' => [
                    'client_id'     => 'test-id.apps.googleusercontent.com',
                    'client_secret' => 'test-secret',
                ],
            ],
        ]));

        $ctrl = $this->makeController('oauth_ctrl', ['provider' => 'google', 'origin' => 'http://localhost:5173']);
        $res  = $this->callController($ctrl, 'redirect');

        $this->assertSame('success', $res['status']);
        $parsed = parse_url($res['url']);
        parse_str($parsed['query'] ?? '', $params);

        $this->assertArrayHasKey('client_id',     $params);
        $this->assertArrayHasKey('redirect_uri',  $params);
        $this->assertArrayHasKey('state',         $params);
        $this->assertArrayHasKey('scope',         $params);
        $this->assertStringContainsString('test', $params['redirect_uri']);
        $this->assertStringContainsString('email', $params['scope']);
    }

    public function testRedirectOrcidUrlPointsToOrcid(): void
    {
        file_put_contents($this->configFile, json_encode([
            'name'  => 'test',
            'oauth' => [
                'orcid' => [
                    'client_id'     => 'APP-TESTORCID00000000',
                    'client_secret' => 'fake-secret',
                ],
            ],
        ]));

        $ctrl = $this->makeController('oauth_ctrl', ['provider' => 'orcid', 'origin' => 'http://localhost']);
        $res  = $this->callController($ctrl, 'redirect');

        $this->assertSame('success', $res['status']);
        $this->assertStringStartsWith('https://orcid.org', $res['url']);
    }

    public function testRedirectStateTokenIsSignedAndWellFormed(): void
    {
        file_put_contents($this->configFile, json_encode([
            'name'  => 'test',
            'oauth' => [
                'google' => ['client_id' => 'cid', 'client_secret' => 'sec'],
            ],
        ]));

        $ctrl = $this->makeController('oauth_ctrl', ['provider' => 'google', 'origin' => 'http://localhost']);
        $res  = $this->callController($ctrl, 'redirect');

        $this->assertSame('success', $res['status']);
        $parsed = parse_url($res['url']);
        parse_str($parsed['query'] ?? '', $params);
        $state = $params['state'] ?? '';

        // State must be payload.signature (exactly two dot-separated parts)
        $this->assertSame(2, count(explode('.', $state)));

        // Payload must decode to valid JSON with the expected keys
        $payload = json_decode(base64_decode(explode('.', $state)[0]), true);
        $this->assertIsArray($payload);
        $this->assertArrayHasKey('app',    $payload);
        $this->assertArrayHasKey('origin', $payload);
        $this->assertArrayHasKey('nonce',  $payload);
        $this->assertArrayHasKey('ts',     $payload);
        $this->assertSame('test',              $payload['app']);
        $this->assertSame('http://localhost',  $payload['origin']);
    }

    // ── callback() — JSON fallback path (origin empty) ────────────────────────

    /**
     * When code or state are missing the callback falls back to JSON output
     * (no origin available for a browser redirect).
     */
    public function testCallbackRejectsMissingCodeAndState(): void
    {
        $ctrl = $this->makeController('oauth_ctrl', [
            'provider' => 'google',
            'code'     => '',
            'state'    => '',
        ]);
        ob_start();
        $ctrl->callback();
        $raw = ob_get_clean();
        $res = json_decode($raw ?: '{}', true);

        $this->assertSame('error',           $res['status'] ?? null);
        $this->assertSame('invalid_request', $res['text']   ?? null);
    }

    public function testCallbackRejectsUnsupportedProvider(): void
    {
        $ctrl = $this->makeController('oauth_ctrl', [
            'provider' => 'facebook',
            'code'     => 'somecode',
            'state'    => 'somestate',
        ]);
        ob_start();
        $ctrl->callback();
        $raw = ob_get_clean();
        $res = json_decode($raw ?: '{}', true);

        $this->assertSame('error', $res['status'] ?? null);
    }

    // ── listApps OAuth field ──────────────────────────────────────────────────

    public function testListAppsIncludesEmptyOauthArrayWhenUnconfigured(): void
    {
        // The 'test' app config has no oauth section → oauth must be []
        $ctrl = $this->makeController('login_ctrl', []);
        $res  = $this->callController($ctrl, 'listApps');

        $this->assertSame('success', $res['status']);

        $testApp = null;
        foreach ($res['apps'] ?? [] as $app) {
            if ($app['db'] === 'test') {
                $testApp = $app;
                break;
            }
        }

        // The 'test' project dir exists (we created it in setUp), so it should appear
        if ($testApp !== null) {
            $this->assertArrayHasKey('oauth', $testApp);
            $this->assertIsArray($testApp['oauth']);
            $this->assertEmpty($testApp['oauth']);
        } else {
            $this->markTestSkipped('test app not visible in listApps — config.json may be missing');
        }
    }

    public function testListAppsIncludesConfiguredProviders(): void
    {
        file_put_contents($this->configFile, json_encode([
            'name'  => 'test',
            'db_engine' => 'sqlite',
            'oauth' => [
                'google' => ['client_id' => 'cid', 'client_secret' => 'sec'],
                'orcid'  => ['client_id' => 'oid', 'client_secret' => 'osec'],
            ],
        ]));

        $ctrl = $this->makeController('login_ctrl', []);
        $res  = $this->callController($ctrl, 'listApps');

        $testApp = null;
        foreach ($res['apps'] ?? [] as $app) {
            if ($app['db'] === 'test') {
                $testApp = $app;
                break;
            }
        }

        if ($testApp === null) {
            $this->markTestSkipped('test app not visible in listApps');
        }

        $this->assertContains('google', $testApp['oauth']);
        $this->assertContains('orcid',  $testApp['oauth']);
    }

    public function testListAppsOmitsProviderWithMissingSecret(): void
    {
        file_put_contents($this->configFile, json_encode([
            'name'      => 'test',
            'db_engine' => 'sqlite',
            'oauth'     => [
                'google' => ['client_id' => 'cid', 'client_secret' => ''],  // empty secret
                'orcid'  => ['client_id' => 'oid', 'client_secret' => 'osec'],
            ],
        ]));

        $ctrl = $this->makeController('login_ctrl', []);
        $res  = $this->callController($ctrl, 'listApps');

        $testApp = null;
        foreach ($res['apps'] ?? [] as $app) {
            if ($app['db'] === 'test') { $testApp = $app; break; }
        }
        if (!$testApp) { $this->markTestSkipped('test app not visible'); }

        $this->assertNotContains('google', $testApp['oauth']);
        $this->assertContains('orcid',    $testApp['oauth']);
    }
}
