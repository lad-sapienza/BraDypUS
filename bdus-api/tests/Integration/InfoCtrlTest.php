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
        $ctrl = $this->makeController('info_ctrl');
        $res  = $this->callController($ctrl, 'getInfo');

        $this->assertArrayHasKey('version',        $res);
        $this->assertArrayHasKey('changelog_html', $res);
    }

    public function testGetInfoVersionIsNonEmptyString(): void
    {
        $ctrl = $this->makeController('info_ctrl');
        $res  = $this->callController($ctrl, 'getInfo');

        $this->assertIsString($res['version']);
        $this->assertNotEmpty($res['version']);
    }

    public function testGetInfoChangelogIsHtml(): void
    {
        $ctrl = $this->makeController('info_ctrl');
        $res  = $this->callController($ctrl, 'getInfo');

        // Markdown is converted to HTML; we expect at least one HTML tag.
        $this->assertStringContainsString('<', $res['changelog_html']);
    }

    public function testGetInfoRequiresReadPrivilege(): void
    {
        $_SESSION['user']['privilege'] = 99; // no privileges
        $ctrl = $this->makeController('info_ctrl');
        $res  = $this->callController($ctrl, 'getInfo');
        $_SESSION['user']['privilege'] = 1;  // restore

        $this->assertSame('error', $res['status']);
        $this->assertSame('not_enough_privilege', $res['code']);
    }
}
