<?php

namespace Tests\Integration;

use Tests\Support\BdusTestCase;

/**
 * Integration tests for NewApp::getStatus() and NewApp::create().
 *
 * create() with valid params would write to disk and is covered by hurl phase 01.
 * Here we test the shape of getStatus() and the "not permitted" guard of create().
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

    // ── create — not permitted ────────────────────────────────────────────────

    public function testCreateNotPermitted(): void
    {
        // Ensure BRADYPUS_ALLOW_NEW_APP is not '1' and the projects directory is
        // non-empty (it always contains the bdus-api source tree) so isPermitted()
        // returns false.
        $original = getenv('BRADYPUS_ALLOW_NEW_APP');
        putenv('BRADYPUS_ALLOW_NEW_APP=0');

        // We also need MAIN_DIR/projects/ to be non-empty. In the test environment
        // MAIN_DIR is the project root; create a canary file if projects/ is empty.
        $projectsDir = MAIN_DIR . 'projects/';
        $canary = null;
        if (!\Bdus\Utils::dirContent($projectsDir)) {
            $canary = $projectsDir . '.canary_test';
            file_put_contents($canary, '1');
        }

        $ctrl = $this->makeController('Bdus\\Controllers\\NewApp', [], ['name' => 'testapp']);
        $res  = $this->callController($ctrl, 'create');

        $this->assertSame('error',                  $res['status']);
        $this->assertSame('not_allowed_app_create', $res['code']);

        // Restore
        putenv('BRADYPUS_ALLOW_NEW_APP=' . ($original ?: ''));
        if ($canary && file_exists($canary)) {
            unlink($canary);
        }
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
}
