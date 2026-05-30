<?php

namespace Tests\Integration;

use Tests\Support\BdusTestCase;

/**
 * Integration tests for info_ctrl.
 */
class InfoCtrlTest extends BdusTestCase
{
    // ── getInfo ───────────────────────────────────────────────────────────

    public function testGetInfoReturnsSuccess(): void
    {
        $ctrl = $this->makeController('Bdus\\Controllers\\Info');
        $res  = $this->callController($ctrl, 'getInfo');

        $this->assertArrayHasKey('version',      $res);
        $this->assertArrayHasKey('changelog_md', $res); // raw MD; HTML conversion done client-side
    }

    public function testGetInfoVersionIsNonEmptyString(): void
    {
        $ctrl = $this->makeController('Bdus\\Controllers\\Info');
        $res  = $this->callController($ctrl, 'getInfo');

        $this->assertIsString($res['version']);
        $this->assertNotEmpty($res['version']);
    }

    public function testGetInfoChangelogIsMd(): void
    {
        $ctrl = $this->makeController('Bdus\\Controllers\\Info');
        $res  = $this->callController($ctrl, 'getInfo');

        // Raw Markdown is returned; HTML conversion is done client-side via marked.js.
        $this->assertIsString($res['changelog_md']);
        $this->assertNotEmpty($res['changelog_md']);
    }

    public function testGetInfoRequiresReadPrivilege(): void
    {
        $this->setPrivilege(99); // no privileges
        $ctrl = $this->makeController('Bdus\\Controllers\\Info');
        $res  = $this->callController($ctrl, 'getInfo');
        $this->setPrivilege(1);  // restore

        $this->assertSame('error', $res['status']);
        $this->assertSame('not_enough_privilege', $res['code']);
    }
}
