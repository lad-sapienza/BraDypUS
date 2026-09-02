<?php

namespace Tests\Integration;

use Tests\Support\BdusTestCase;

/**
 * Integration tests for NewApp::getStatus() and NewApp::create().
 *
 * create() with valid params would write to disk and is covered by hurl phase 01.
 * Here we test the shape of getStatus() and the "not permitted" guard of create().
 * App creation is gated solely by BRADYPUS_ALLOW_NEW_APP=1 (no empty-projects
 * shortcut).
 */
class NewAppCtrlTest extends BdusTestCase
{
    // ── getStatus ─────────────────────────────────────────────────────────────

    public function testGetStatusReturnsExpectedShape(): void
    {
        $ctrl = $this->makeController('Bdus\\Controllers\\NewApp');
        $res  = $this->callController($ctrl, 'getStatus');

        $this->assertSame('success', $res['status']);
        $this->assertArrayHasKey('permitted', $res);
        $this->assertArrayHasKey('engines',   $res);
        $this->assertIsBool($res['permitted']);
        $this->assertIsArray($res['engines']);
        $this->assertNotEmpty($res['engines']);
        $this->assertContains('sqlite', $res['engines']);
    }

    public function testGetStatusReflectsAllowFlag(): void
    {
        $original = getenv('BRADYPUS_ALLOW_NEW_APP');

        putenv('BRADYPUS_ALLOW_NEW_APP=0');
        $res = $this->callController($this->makeController('Bdus\\Controllers\\NewApp'), 'getStatus');
        $this->assertFalse($res['permitted']);

        putenv('BRADYPUS_ALLOW_NEW_APP=1');
        $res = $this->callController($this->makeController('Bdus\\Controllers\\NewApp'), 'getStatus');
        $this->assertTrue($res['permitted']);

        putenv('BRADYPUS_ALLOW_NEW_APP=' . ($original ?: ''));
    }

    // ── create — not permitted ────────────────────────────────────────────────

    public function testCreateNotPermitted(): void
    {
        // Gated solely by the env flag — the projects/ directory is irrelevant.
        $original = getenv('BRADYPUS_ALLOW_NEW_APP');
        putenv('BRADYPUS_ALLOW_NEW_APP=0');

        $ctrl = $this->makeController('Bdus\\Controllers\\NewApp', [], ['name' => 'testapp']);
        $res  = $this->callController($ctrl, 'create');

        $this->assertSame('error',                  $res['status']);
        $this->assertSame('not_allowed_app_create', $res['code']);

        putenv('BRADYPUS_ALLOW_NEW_APP=' . ($original ?: ''));
    }

    public function testCreateMissingRequiredParamsReturnsError(): void
    {
        // When permitted but params are missing/invalid CreateApp throws.
        putenv('BRADYPUS_ALLOW_NEW_APP=1');

        $ctrl = $this->makeController('Bdus\\Controllers\\NewApp', [], [
            // name, email, password, db_engine all missing
        ]);
        $res = $this->callController($ctrl, 'create');

        $this->assertSame('error', $res['status']);
        $this->assertSame('error_app_not_created', $res['code']);
        $this->assertArrayHasKey('detail', $res);

        putenv('BRADYPUS_ALLOW_NEW_APP=0');
    }

    /**
     * With history-mode routing the app name is a URL path segment, so names
     * that collide with server-handled prefixes (api, projects, cache, …) are
     * rejected by CreateApp::validateData before anything touches the disk.
     */
    public function testCreateReservedNameReturnsError(): void
    {
        putenv('BRADYPUS_ALLOW_NEW_APP=1');

        foreach (['api', 'API', 'projects', 'cache', 'login'] as $reserved) {
            $ctrl = $this->makeController('Bdus\\Controllers\\NewApp', [], [
                'name'       => $reserved,
                'definition' => 'Reserved-name probe',
                'email'      => 'admin@example.org',
                'password'   => 'secret',
                'db_engine'  => 'sqlite',
            ]);
            $res = $this->callController($ctrl, 'create');

            $this->assertSame('error', $res['status'], "name `$reserved` should be rejected");
            $this->assertSame('error_app_not_created', $res['code']);
            $this->assertStringContainsString('reserved', strtolower($res['detail'] ?? ''));
            $this->assertDirectoryDoesNotExist(MAIN_DIR . "projects/$reserved");
        }

        putenv('BRADYPUS_ALLOW_NEW_APP=0');
    }
}
