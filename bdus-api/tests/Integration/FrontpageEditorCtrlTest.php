<?php

namespace Tests\Integration;

use DB\System\Manage;
use Tests\Support\BdusTestCase;

/**
 * Integration tests for frontpage_editor_ctrl.
 *
 * Methods under test:
 *   getWelcome()   — reads welcome text from bdus_cfg_app; any logged-in user
 *   saveWelcome()  — writes welcome text to bdus_cfg_app; admin-only
 *
 * This class creates bdus_cfg_app with a seeded row (id = 1) so that
 * AppSettings::isAvailable() returns true and the DB path is exercised.
 */
class FrontpageEditorCtrlTest extends BdusTestCase
{
    // ── Lifecycle ─────────────────────────────────────────────────────────

    public static function setUpBeforeClass(): void
    {
        parent::setUpBeforeClass();

        $manage = new Manage(static::$db);
        $manage->createTable('bdus_cfg_app');

        // Seed the single settings row that CreateApp would normally create.
        static::$db->query(
            'INSERT INTO bdus_cfg_app (id, status, max_image_size, welcome) VALUES (?, ?, ?, ?)',
            [1, 'on', 1500, '# Welcome'],
            'boolean'
        );
    }

    // Restore the welcome text between tests that mutate it.
    protected function setUp(): void
    {
        static::$db->query(
            'UPDATE bdus_cfg_app SET welcome = ? WHERE id = 1',
            ['# Welcome'],
            'boolean'
        );
    }

    // ── getWelcome() ──────────────────────────────────────────────────────

    public function testGetWelcomeReturnsContent(): void
    {
        $ctrl = $this->makeController('Bdus\\Controllers\\FrontpageEditor');
        $res  = $this->callController($ctrl, 'getWelcome');

        $this->assertArrayHasKey('content', $res);
        $this->assertIsString($res['content']);
    }

    public function testGetWelcomeReturnsSeededText(): void
    {
        $ctrl = $this->makeController('Bdus\\Controllers\\FrontpageEditor');
        $res  = $this->callController($ctrl, 'getWelcome');

        $this->assertSame('# Welcome', $res['content']);
    }

    public function testGetWelcomeAccessibleToReadPrivilege(): void
    {
        $this->setPrivilege(4); // reader
        $ctrl = $this->makeController('Bdus\\Controllers\\FrontpageEditor');
        $res  = $this->callController($ctrl, 'getWelcome');
        $this->setPrivilege(1);

        // getWelcome has no privilege check — any logged-in user can read
        $this->assertArrayHasKey('content', $res);
    }

    // ── saveWelcome() ─────────────────────────────────────────────────────

    public function testSaveWelcomeSuccess(): void
    {
        $ctrl = $this->makeController(
            'Bdus\\Controllers\\FrontpageEditor',
            [],
            ['content' => '# Updated Welcome\n\nNew text.']
        );
        $res = $this->callController($ctrl, 'saveWelcome');

        $this->assertSame('success',  $res['status']);
        $this->assertSame('ok_save',  $res['code']);
    }

    public function testSaveWelcomePersistsContent(): void
    {
        $newContent = '# New Dashboard\n\nHello world.';
        $ctrl = $this->makeController(
            'Bdus\\Controllers\\FrontpageEditor',
            [],
            ['content' => $newContent]
        );
        $this->callController($ctrl, 'saveWelcome');

        // Read back via getWelcome
        $ctrl2 = $this->makeController('Bdus\\Controllers\\FrontpageEditor');
        $res2  = $this->callController($ctrl2, 'getWelcome');
        $this->assertSame($newContent, $res2['content']);
    }

    public function testSaveWelcomeStripsPhpTags(): void
    {
        $ctrl = $this->makeController(
            'Bdus\\Controllers\\FrontpageEditor',
            [],
            ['content' => 'Hello <?php echo "evil"; ?> world']
        );
        $this->callController($ctrl, 'saveWelcome');

        $ctrl2 = $this->makeController('Bdus\\Controllers\\FrontpageEditor');
        $res2  = $this->callController($ctrl2, 'getWelcome');

        $this->assertStringNotContainsString('<?php', $res2['content']);
        $this->assertStringNotContainsString('<?',    $res2['content']);
        $this->assertStringNotContainsString('?>',    $res2['content']);
    }

    public function testSaveWelcomeRequiresAdminPrivilege(): void
    {
        $this->setPrivilege(99); // reader
        $ctrl = $this->makeController(
            'Bdus\\Controllers\\FrontpageEditor',
            [],
            ['content' => '# Should Not Save']
        );
        $res = $this->callController($ctrl, 'saveWelcome');
        $this->setPrivilege(1);

        $this->assertSame('error',               $res['status']);
        $this->assertSame('not_enough_privilege', $res['code']);
    }

    public function testSaveWelcomeWithEmptyContentAllowed(): void
    {
        $ctrl = $this->makeController(
            'Bdus\\Controllers\\FrontpageEditor',
            [],
            ['content' => '']
        );
        $res = $this->callController($ctrl, 'saveWelcome');

        $this->assertSame('success', $res['status']);
        $this->assertSame('ok_save', $res['code']);
    }
}
