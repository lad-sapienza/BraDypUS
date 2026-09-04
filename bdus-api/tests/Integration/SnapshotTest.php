<?php

namespace Tests\Integration;

use PHPUnit\Framework\TestCase;
use DB\System\Snapshot;

/**
 * Tests for DB\System\Snapshot — the pre-upgrade rollback snapshot.
 */
class SnapshotTest extends TestCase
{
    private string $proj;

    protected function setUp(): void
    {
        $this->proj = sys_get_temp_dir() . '/bdus_snap_' . bin2hex(random_bytes(4)) . '/';
        foreach (['db', 'cfg', 'files', 'files/sub', 'cache', 'backups', 'export', 'migrations/v4v5/x'] as $d) {
            mkdir($this->proj . $d, 0777, true);
        }
        file_put_contents($this->proj . 'migrations/v4v5/x/upgrade.log', 'log');
        file_put_contents($this->proj . 'config.json', '{"db_engine":"sqlite","bdus_version":null}');
        file_put_contents($this->proj . 'db/bdus.sqlite', 'SQLITE_FORMAT_3_PLACEHOLDER');
        file_put_contents($this->proj . 'cfg/tables.json', '{"tables":[]}');
        file_put_contents($this->proj . 'files/big.bin', str_repeat('x', 4096));
        file_put_contents($this->proj . 'files/sub/nested.bin', str_repeat('y', 2048));
        file_put_contents($this->proj . 'cache/junk.tmp', 'junk');
    }

    protected function tearDown(): void
    {
        $this->rrmdir($this->proj);
    }

    private function rrmdir(string $dir): void
    {
        if (!is_dir($dir)) {
            return;
        }
        $it = new \RecursiveIteratorIterator(
            new \RecursiveDirectoryIterator($dir, \FilesystemIterator::SKIP_DOTS),
            \RecursiveIteratorIterator::CHILD_FIRST
        );
        foreach ($it as $f) {
            $f->isDir() ? rmdir($f->getPathname()) : unlink($f->getPathname());
        }
        rmdir($dir);
    }

    public function testCreatesArchiveAndSqliteBaseline(): void
    {
        $res = Snapshot::takeProjectSnapshot($this->proj);

        $this->assertFileExists($res['archive']);
        $this->assertStringEndsWith('.tar.gz', $res['archive']);
        $this->assertGreaterThan(0, filesize($res['archive']));

        $this->assertNotNull($res['sqlite']);
        $this->assertFileExists($res['sqlite']);
        $this->assertSame(
            file_get_contents($this->proj . 'db/bdus.sqlite'),
            file_get_contents($res['sqlite'])
        );

        // Both artifacts land under backups/.
        $this->assertStringStartsWith($this->proj . 'backups/', $res['archive']);
        $this->assertStringStartsWith($this->proj . 'backups/', $res['sqlite']);
    }

    public function testArchiveKeepsConfigAndDbButExcludesFilesAndCache(): void
    {
        $res = Snapshot::takeProjectSnapshot($this->proj);

        // List archive members with the same tool used to restore in production.
        exec('tar -tzf ' . escapeshellarg($res['archive']) . ' 2>/dev/null', $members, $rc);
        if ($rc !== 0) {
            $this->markTestSkipped('tar not available to inspect the archive');
        }
        $members = array_map(fn($m) => ltrim($m, './'), $members);

        $this->assertContains('config.json', $members);
        $this->assertContains('db/bdus.sqlite', $members);
        $this->assertContains('cfg/tables.json', $members);

        $has = fn(string $prefix) => (bool) array_filter(
            $members,
            fn($m) => $m !== '' && str_starts_with($m, $prefix)
        );
        $this->assertFalse($has('files/'), 'files/ must be excluded');
        $this->assertFalse($has('cache/'), 'cache/ must be excluded');
        $this->assertFalse($has('export/'), 'export/ must be excluded');
        $this->assertFalse($has('backups/'), 'backups/ must be excluded');
        $this->assertFalse($has('migrations/'), 'migrations/ must be excluded');
    }

    public function testThrowsWhenProjectDirMissing(): void
    {
        $this->expectException(\RuntimeException::class);
        Snapshot::takeProjectSnapshot($this->proj . 'nope/');
    }
}
