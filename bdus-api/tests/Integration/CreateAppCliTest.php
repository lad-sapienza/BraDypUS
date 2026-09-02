<?php

namespace Tests\Integration;

use Tests\Support\BdusTestCase;

/**
 * Integration tests for bin/create-app.php — the CLI app-creation entrypoint.
 *
 * Shells out to a real `php bin/create-app.php` run so argument parsing and the
 * standalone bootstrap are exercised. Only the sqlite path is covered here (no
 * external DB); the pgsql path shares the same CreateApp call and is covered by
 * the hurl suite + add-app.sh.
 */
class CreateAppCliTest extends BdusTestCase
{
    /** @var string[] project dirs to remove after each test */
    private array $made = [];

    protected function tearDown(): void
    {
        foreach ($this->made as $dir) {
            if (is_dir($dir)) {
                exec('rm -rf ' . escapeshellarg($dir));
            }
        }
        $this->made = [];
        parent::tearDown();
    }

    private function root(): string
    {
        return rtrim(MAIN_DIR, '/');
    }

    /** @return array{0:int,1:string}  [exit code, combined output] */
    private function runCli(string $stdin, string ...$args): array
    {
        $cmd = 'cd ' . escapeshellarg($this->root())
             . ' && printf %s ' . escapeshellarg($stdin)
             . ' | php ' . escapeshellarg($this->root() . '/bin/create-app.php');
        foreach ($args as $a) {
            $cmd .= ' ' . escapeshellarg($a);
        }
        $cmd .= ' 2>&1';

        $out = [];
        exec($cmd, $out, $rc);
        return [$rc, implode("\n", $out)];
    }

    public function testCreatesSqliteApp(): void
    {
        $this->assertFileExists($this->root() . '/bin/create-app.php');

        $name = 'clitest_' . bin2hex(random_bytes(4));
        $this->made[] = $this->root() . '/projects/' . $name;

        [$rc, $out] = $this->runCli(
            "S3cret-pw\n",
            '--name', $name,
            '--engine', 'sqlite',
            '--email', 'admin@example.org',
            '--password-stdin',
            '--definition', 'CLI test app'
        );

        $this->assertSame(0, $rc, "CLI failed (rc={$rc}):\n{$out}");
        $this->assertStringContainsString("OK: application '{$name}'", $out);

        $cfgFile = $this->root() . "/projects/{$name}/config.json";
        $this->assertFileExists($cfgFile);
        $cfg = json_decode((string) file_get_contents($cfgFile), true);
        $this->assertSame('sqlite', $cfg['db_engine'] ?? null);
        $this->assertFileExists($this->root() . "/projects/{$name}/db/bdus.sqlite");

        // A second create with the same name must be rejected.
        [$rc2, $out2] = $this->runCli(
            "S3cret-pw\n",
            '--name', $name,
            '--engine', 'sqlite',
            '--email', 'admin@example.org',
            '--password-stdin'
        );
        $this->assertNotSame(0, $rc2, "second create should fail:\n{$out2}");
    }

    public function testRejectsMissingEngine(): void
    {
        [$rc, $out] = $this->runCli(
            "pw\n",
            '--name', 'x',
            '--email', 'a@b.c',
            '--password-stdin'
        );
        $this->assertSame(2, $rc, $out);
        $this->assertStringContainsString('--engine is required', $out);
    }

    public function testRejectsInvalidName(): void
    {
        [$rc, $out] = $this->runCli(
            "pw\n",
            '--name', 'Bad-Name',
            '--engine', 'sqlite',
            '--email', 'a@b.c',
            '--password-stdin'
        );
        $this->assertSame(2, $rc, $out);
        $this->assertStringContainsString('--name must match', $out);
    }

    public function testHelpExitsZero(): void
    {
        [$rc, $out] = $this->runCli('', '--help');
        $this->assertSame(0, $rc);
        $this->assertStringContainsString('Usage:', $out);
    }
}
