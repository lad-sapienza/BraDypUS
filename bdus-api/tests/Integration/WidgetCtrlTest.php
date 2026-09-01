<?php

namespace Tests\Integration;

use Tests\Support\BdusTestCase;

/**
 * Integration tests for widget_ctrl.
 *
 * widget_ctrl scans PROJ_DIR/widgets/ for *.js files (listWidgets) and serves
 * them verbatim with the correct Content-Type (serveWidget).
 *
 * The bootstrap sets PROJ_DIR → sys_get_temp_dir()/bradypus_test_proj/ but does
 * NOT pre-create the widgets/ subdirectory, so we manage it ourselves here.
 */
class WidgetCtrlTest extends BdusTestCase
{
    private static string $widgetsDir;

    // ── Bootstrap ─────────────────────────────────────────────────────────

    public static function setUpBeforeClass(): void
    {
        parent::setUpBeforeClass();
        static::$widgetsDir = PROJ_DIR . 'widgets/';
    }

    /** Remove every file we placed in the widgets dir, then the dir itself. */
    public static function tearDownAfterClass(): void
    {
        if (is_dir(static::$widgetsDir)) {
            foreach (glob(static::$widgetsDir . '*') ?: [] as $f) {
                @unlink($f);
            }
            @rmdir(static::$widgetsDir);
        }
        parent::tearDownAfterClass();
    }

    /** Ensure we start each test with a clean, empty widgets directory. */
    protected function setUp(): void
    {
        parent::setUp();
        if (is_dir(static::$widgetsDir)) {
            foreach (glob(static::$widgetsDir . '*') ?: [] as $f) {
                @unlink($f);
            }
        } else {
            mkdir(static::$widgetsDir, 0755, true);
        }
    }

    // ── listWidgets — no directory ─────────────────────────────────────────

    public function testListWidgetsReturnsEmptyWhenNoDirExists(): void
    {
        // Remove the directory so PROJ_DIR/widgets/ does not exist.
        @rmdir(static::$widgetsDir);

        $ctrl = $this->makeController('Bdus\\Controllers\\Widget');
        $res  = $this->callController($ctrl, 'listWidgets');

        $this->assertArrayHasKey('widgets', $res);
        $this->assertSame([], $res['widgets']);

        // Recreate dir for subsequent tests.
        mkdir(static::$widgetsDir, 0755, true);
    }

    // ── listWidgets — empty directory ──────────────────────────────────────

    public function testListWidgetsReturnsEmptyWhenDirIsEmpty(): void
    {
        $ctrl = $this->makeController('Bdus\\Controllers\\Widget');
        $res  = $this->callController($ctrl, 'listWidgets');

        $this->assertArrayHasKey('widgets', $res);
        $this->assertSame([], $res['widgets']);
    }

    // ── listWidgets — valid .js files ──────────────────────────────────────

    public function testListWidgetsReturnsAlphabeticallySortedNames(): void
    {
        file_put_contents(static::$widgetsDir . 'zebra.js',      'export default {}');
        file_put_contents(static::$widgetsDir . 'alpha.js',      'export default {}');
        file_put_contents(static::$widgetsDir . 'quirematrix.js','export default {}');

        $ctrl = $this->makeController('Bdus\\Controllers\\Widget');
        $res  = $this->callController($ctrl, 'listWidgets');

        $this->assertSame(['alpha', 'quirematrix', 'zebra'], $res['widgets']);
    }

    public function testListWidgetsAcceptsDashesInName(): void
    {
        file_put_contents(static::$widgetsDir . 'my-widget.js', 'export default {}');

        $ctrl = $this->makeController('Bdus\\Controllers\\Widget');
        $res  = $this->callController($ctrl, 'listWidgets');

        $this->assertContains('my-widget', $res['widgets']);
    }

    // ── listWidgets — non-JS files are ignored ────────────────────────────

    public function testListWidgetsIgnoresNonJsFiles(): void
    {
        file_put_contents(static::$widgetsDir . 'valid.js',    'export default {}');
        file_put_contents(static::$widgetsDir . 'readme.txt',  'docs');
        file_put_contents(static::$widgetsDir . 'evil.php',    '<?php echo 1;');
        file_put_contents(static::$widgetsDir . 'style.css',   'body{}');

        $ctrl = $this->makeController('Bdus\\Controllers\\Widget');
        $res  = $this->callController($ctrl, 'listWidgets');

        $this->assertSame(['valid'], $res['widgets'],
            'Only .js files with valid names should be listed');
    }

    // ── listWidgets — names with invalid characters are skipped ───────────

    public function testListWidgetsIgnoresFilesWithInvalidNames(): void
    {
        file_put_contents(static::$widgetsDir . 'valid.js',          'export default {}');
        file_put_contents(static::$widgetsDir . 'UPPER.js',          'export default {}');
        file_put_contents(static::$widgetsDir . 'has space.js',      'export default {}');
        file_put_contents(static::$widgetsDir . 'has_underscore.js', 'export default {}');

        $ctrl = $this->makeController('Bdus\\Controllers\\Widget');
        $res  = $this->callController($ctrl, 'listWidgets');

        $this->assertSame(['valid'], $res['widgets'],
            'Uppercase, spaces and underscores are invalid — only "valid" should survive');
    }

    // ── serveWidget — happy path ───────────────────────────────────────────

    public function testServeWidgetOutputsJsContent(): void
    {
        $content = 'export default { mount(c,v){ c.textContent = v } }';
        file_put_contents(static::$widgetsDir . 'hello.js', $content);

        $ctrl = $this->makeController('Bdus\\Controllers\\Widget', ['name' => 'hello']);

        ob_start();
        $ctrl->serveWidget();
        $body = ob_get_clean();

        $this->assertSame($content, $body);
    }

    public function testServeWidgetReturnsDashNameWidget(): void
    {
        $content = '/* my-widget */';
        file_put_contents(static::$widgetsDir . 'my-widget.js', $content);

        $ctrl = $this->makeController('Bdus\\Controllers\\Widget', ['name' => 'my-widget']);

        ob_start();
        $ctrl->serveWidget();
        $body = ob_get_clean();

        $this->assertSame($content, $body);
    }

    // ── serveWidget — 404 for missing widget ───────────────────────────────

    public function testServeWidgetReturns404ForNonexistentWidget(): void
    {
        $ctrl = $this->makeController('Bdus\\Controllers\\Widget', ['name' => 'ghost']);
        $res  = $this->callController($ctrl, 'serveWidget');

        $this->assertSame('error',            $res['status']);
        $this->assertSame('widget_not_found', $res['code']);
    }

    // ── serveWidget — 400 for invalid names ────────────────────────────────

    public function testServeWidgetReturns400ForEmptyName(): void
    {
        $ctrl = $this->makeController('Bdus\\Controllers\\Widget', ['name' => '']);
        $res  = $this->callController($ctrl, 'serveWidget');

        $this->assertSame('error',               $res['status']);
        $this->assertSame('invalid_widget_name', $res['code']);
    }

    public function testServeWidgetBlocksPathTraversalDotDot(): void
    {
        $ctrl = $this->makeController('Bdus\\Controllers\\Widget', ['name' => '../../etc/passwd']);
        $res  = $this->callController($ctrl, 'serveWidget');

        $this->assertSame('error',               $res['status']);
        $this->assertSame('invalid_widget_name', $res['code']);
    }

    public function testServeWidgetBlocksPathTraversalSlash(): void
    {
        $ctrl = $this->makeController('Bdus\\Controllers\\Widget', ['name' => 'sub/evil']);
        $res  = $this->callController($ctrl, 'serveWidget');

        $this->assertSame('error',               $res['status']);
        $this->assertSame('invalid_widget_name', $res['code']);
    }

    public function testServeWidgetBlocksUpperCaseName(): void
    {
        $ctrl = $this->makeController('Bdus\\Controllers\\Widget', ['name' => 'MyWidget']);
        $res  = $this->callController($ctrl, 'serveWidget');

        $this->assertSame('error',               $res['status']);
        $this->assertSame('invalid_widget_name', $res['code']);
    }

    public function testServeWidgetBlocksUnderscoreName(): void
    {
        // Underscores are not in the allowed charset [a-z0-9\-]
        $ctrl = $this->makeController('Bdus\\Controllers\\Widget', ['name' => 'quire_matrix']);
        $res  = $this->callController($ctrl, 'serveWidget');

        $this->assertSame('error',               $res['status']);
        $this->assertSame('invalid_widget_name', $res['code']);
    }

    public function testServeWidgetBlocksNullByte(): void
    {
        $ctrl = $this->makeController('Bdus\\Controllers\\Widget', ['name' => "hello\x00world"]);
        $res  = $this->callController($ctrl, 'serveWidget');

        $this->assertSame('error',               $res['status']);
        $this->assertSame('invalid_widget_name', $res['code']);
    }

    // ── Privilege checks ──────────────────────────────────────────────────

    public function testListWidgetsRequiresReadPrivilege(): void
    {
        $this->setPrivilege(100);
        $ctrl = $this->makeController('Bdus\\Controllers\\Widget');
        $res  = $this->callController($ctrl, 'listWidgets');
        $this->setPrivilege(1);

        $this->assertSame('error',                $res['status']);
        $this->assertSame('not_enough_privilege', $res['code']);
    }

    public function testServeWidgetRequiresReadPrivilege(): void
    {
        $this->setPrivilege(100);
        $ctrl = $this->makeController('Bdus\\Controllers\\Widget', ['name' => 'anything']);
        $res  = $this->callController($ctrl, 'serveWidget');
        $this->setPrivilege(1);

        $this->assertSame('error',                $res['status']);
        $this->assertSame('not_enough_privilege', $res['code']);
    }
}
