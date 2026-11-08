<?php

namespace Tests\Integration;

use DB\System\Migrate;
use DB\System\Migrations\M051_AddLayoutsToGroups;
use Tests\Support\BdusTestCase;

/**
 * Integration tests for bin/upgrade-app.php — minor upgrades from the command line.
 *
 * Shells out to a real `php bin/upgrade-app.php` against throw-away SQLite apps
 * created with bin/create-app.php. A pending migration is simulated by deleting
 * the row of M051 from bdus_migrations (M051 is idempotent: it only adds a
 * column when it is missing, so re-applying it is harmless).
 */
class UpgradeAppCliTest extends BdusTestCase
{
    private const M = M051_AddLayoutsToGroups::NAME;

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

    /** @return array{0:int,1:string} */
    private function sh(string $cmd): array
    {
        $out = [];
        exec($cmd . ' 2>&1', $out, $rc);
        return [$rc, implode("\n", $out)];
    }

    /** Creates a real sqlite app and returns its slug. */
    private function makeApp(): string
    {
        $name = 'uptest_' . bin2hex(random_bytes(4));
        $this->made[] = $this->root() . '/projects/' . $name;

        [$rc, $out] = $this->sh(
            'cd ' . escapeshellarg($this->root())
            . ' && printf %s ' . escapeshellarg("S3cret-pw\n")
            . ' | php bin/create-app.php --name ' . escapeshellarg($name)
            . ' --engine sqlite --email admin@example.org --password-stdin'
        );
        $this->assertSame(0, $rc, "create-app failed:\n{$out}");

        return $name;
    }

    /** @return array{0:int,1:string} */
    private function upgrade(string ...$args): array
    {
        $cmd = 'cd ' . escapeshellarg($this->root()) . ' && php bin/upgrade-app.php';
        foreach ($args as $a) {
            $cmd .= ' ' . escapeshellarg($a);
        }
        return $this->sh($cmd);
    }

    private function pdo(string $app): \PDO
    {
        return new \PDO('sqlite:' . $this->root() . "/projects/{$app}/db/bdus.sqlite");
    }

    private function applied(string $app, string $name = self::M): bool
    {
        $st = $this->pdo($app)->prepare('SELECT COUNT(*) FROM bdus_migrations WHERE name = ?');
        $st->execute([$name]);
        return (int) $st->fetchColumn() > 0;
    }

    private function makePending(string $app): void
    {
        $st = $this->pdo($app)->prepare('DELETE FROM bdus_migrations WHERE name = ?');
        $st->execute([self::M]);
        $this->assertFalse($this->applied($app));
    }

    public function testUpToDateAppIsLeftAlone(): void
    {
        $app = $this->makeApp();

        [$rc, $out] = $this->upgrade('--app', $app);

        $this->assertSame(0, $rc, $out);
        $this->assertStringContainsString("{$app}: up-to-date", $out);
    }

    public function testDryRunListsPendingAndChangesNothing(): void
    {
        $app = $this->makeApp();
        $this->makePending($app);

        [$rc, $out] = $this->upgrade('--app', $app, '--dry-run');

        $this->assertSame(0, $rc, $out);
        $this->assertStringContainsString("{$app}: pending (1)", $out);
        $this->assertStringContainsString(self::M, $out);
        $this->assertFalse($this->applied($app), 'dry run must not apply anything');
    }

    public function testAppliesPendingMigrationsAndLogsIntoTheAppLog(): void
    {
        $app = $this->makeApp();
        $this->makePending($app);

        [$rc, $out] = $this->upgrade('--app', $app);

        $this->assertSame(0, $rc, $out);
        $this->assertStringContainsString("{$app}: upgraded (1)", $out);
        $this->assertTrue($this->applied($app), 'M051 must be recorded as applied');

        $n = (int) $this->pdo($app)
            ->query("SELECT COUNT(*) FROM bdus_log WHERE message LIKE '%Minor upgrade applied from the command line%'")
            ->fetchColumn();
        $this->assertSame(1, $n, 'the outcome must be written to the app log');

        // Second run: nothing left to do.
        [$rc2, $out2] = $this->upgrade('--app', $app);
        $this->assertSame(0, $rc2, $out2);
        $this->assertStringContainsString("{$app}: up-to-date", $out2);
    }

    public function testNeverRunsAMajorUpgrade(): void
    {
        $app = $this->makeApp();
        $this->makePending($app);

        // A v4 app has no bdus_version in config.json.
        $cfgFile = $this->root() . "/projects/{$app}/config.json";
        $cfg = json_decode((string) file_get_contents($cfgFile), true);
        unset($cfg['bdus_version']);
        file_put_contents($cfgFile, json_encode($cfg));

        [$rc, $out] = $this->upgrade('--app', $app);

        $this->assertSame(3, $rc, $out);
        $this->assertStringContainsString("{$app}: needs-major", $out);
        $this->assertFalse($this->applied($app), 'a major upgrade must not be applied from here');
    }

    public function testAllContinuesPastABrokenApp(): void
    {
        $good = $this->makeApp();
        $this->makePending($good);
        $bad = $this->makeApp();
        file_put_contents($this->root() . "/projects/{$bad}/config.json", '{not json');

        // --dry-run: the real apps under projects/ are only inspected.
        [$rc, $out] = $this->upgrade('--all', '--dry-run', '--json');

        $this->assertSame(1, $rc, $out);
        $data = json_decode($out, true);
        $this->assertIsArray($data, $out);
        $by = array_column($data['results'], null, 'app');

        $this->assertSame('pending', $by[$good]['state']);
        $this->assertSame([self::M], $by[$good]['migrations']);
        $this->assertSame('failed', $by[$bad]['state']);
        $this->assertNotEmpty($by[$bad]['error']);
    }

    public function testRejectsBadInvocation(): void
    {
        [$rc] = $this->upgrade();
        $this->assertSame(2, $rc, 'neither --app nor --all');

        [$rc] = $this->upgrade('--app', 'a', '--all');
        $this->assertSame(2, $rc, 'both --app and --all');

        [$rc] = $this->upgrade('--app', 'Bad-Name');
        $this->assertSame(2, $rc, 'invalid slug');

        [$rc] = $this->upgrade('--app', 'no_such_app_zz');
        $this->assertSame(2, $rc, 'unknown app');
    }

    public function testUpgraderReportsPendingWithoutRunningAnything(): void
    {
        // In the test bootstrap PROJ_DIR points at the fixture project, whose config
        // carries the current version: not a major upgrade, so pending() is a list
        // (the fixture schema has no bdus_migrations rows: every migration is listed).
        $this->assertFalse(Migrate::isMajorUpgradeNeeded());

        $pending = \DB\System\Upgrader::pending(static::$db);

        $this->assertIsArray($pending);
        $this->assertContains(self::M, $pending);
    }
}
