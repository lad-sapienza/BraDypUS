<?php

namespace Tests\Integration;

use File\LegacyUrls;
use File\Store;
use File\UuidBackfill;
use Tests\Support\BdusTestCase;

/**
 * File\UuidBackfill (the repair for rows that reach bdus_files without a uuid, behind
 * bin/files-backfill-uuid.php) and File\LegacyUrls (the read-only scan for old-style addresses).
 */
class UuidBackfillTest extends BdusTestCase
{
    private string $proj;

    protected function setUp(): void
    {
        $this->proj = sys_get_temp_dir() . '/bdus_backfill_' . uniqid() . '/';
        mkdir($this->proj . 'files', 0755, true);
        mkdir($this->proj . 'cache/thumb', 0775, true);
    }

    protected function tearDown(): void
    {
        $it = new \RecursiveIteratorIterator(
            new \RecursiveDirectoryIterator(rtrim($this->proj, '/'), \FilesystemIterator::SKIP_DOTS),
            \RecursiveIteratorIterator::CHILD_FIRST
        );
        foreach ($it as $f) {
            $f->isDir() ? @rmdir($f->getPathname()) : @unlink($f->getPathname());
        }
        @rmdir(rtrim($this->proj, '/'));
        static::$db->exec('DELETE FROM bdus_files WHERE id >= 970');
        static::$db->exec("UPDATE items SET description = 'd' WHERE description LIKE '%/files/%'");
    }

    /** A row inserted the way a tool that bypasses the application would: no uuid. */
    private function bareRow(int $id, string $ext, ?string $content = 'bin'): void
    {
        static::$db->query("INSERT INTO bdus_files (id, creator, ext, filename) VALUES (?, 'x', ?, ?)", [$id, $ext, "f{$id}"], 'boolean');
        if ($content !== null) {
            file_put_contents($this->proj . "files/{$id}.{$ext}", $content);
        }
    }

    /** Rows of the shared fixture (ids below 970) have no binary in this temporary project: they count as missing. */
    private function fixtureRows(): int
    {
        return (int) static::$db->query('SELECT COUNT(*) AS n FROM bdus_files WHERE id < 970', [], 'read')[0]['n'];
    }

    private function uuidOf(int $id): ?string
    {
        return static::$db->query('SELECT uuid FROM bdus_files WHERE id = ?', [$id], 'read')[0]['uuid'];
    }

    public function testRowsWithoutAUuidGetOneAndTheirBinaryFollows(): void
    {
        $this->bareRow(970, 'jpg', 'one');
        $this->bareRow(971, 'png', 'two');

        $res = UuidBackfill::run(static::$db, $this->proj);

        $this->assertSame(2, $res['assigned']);
        $this->assertSame(2, $res['renamed']);
        $this->assertSame(0, $res['failed']);
        $this->assertTrue(Store::isUuid($this->uuidOf(970)));
        $this->assertSame('one', file_get_contents($this->proj . 'files/' . $this->uuidOf(970) . '.jpg'));
        $this->assertFileDoesNotExist($this->proj . 'files/970.jpg');
    }

    /**
     * Real data (PAThs): the extension column keeps the case it was uploaded with (jpg, JPG, JPEG…) and
     * the binary was stored under exactly that name. A case-sensitive file system (Linux, production)
     * does not find `5.JPG` when asked for `5.jpg`: the old name must be built with the extension AS
     * STORED; the new one is always lower-case (File\Store).
     */
    public function testAnExtensionKeepingItsUpperCaseIsFoundAndRenamedToALowerCaseName(): void
    {
        $this->bareRow(979, 'JPG', 'upper');
        $this->bareRow(980, 'JPEG', 'mixed');
        // This test only means something on a case-sensitive file system: check that it is one
        file_put_contents($this->proj . 'files/case.jpg', 'a');
        $sensitive = !is_file($this->proj . 'files/CASE.JPG');
        $this->assertTrue($sensitive, 'the temporary folder is case-insensitive: this test cannot prove anything here');

        $res = UuidBackfill::run(static::$db, $this->proj);

        $this->assertSame(0, $res['failed']);
        $u979 = $this->uuidOf(979);
        $this->assertFileExists($this->proj . "files/{$u979}.jpg", 'the new name is lower-case');
        $this->assertSame('upper', file_get_contents($this->proj . "files/{$u979}.jpg"));
        $this->assertFileDoesNotExist($this->proj . 'files/979.JPG', 'the old upper-case name is gone');
        $this->assertSame('mixed', file_get_contents($this->proj . 'files/' . $this->uuidOf(980) . '.jpeg'));
        $this->assertSame(0, $res['missing'] - $this->fixtureRows(), 'nothing of ours was reported missing');
    }

    public function testTheServerFindsTheRenamedUpperCaseFile(): void
    {
        // After the rename, Store::path() (always lower-case) points at the right file
        $this->bareRow(981, 'JPG', 'x');
        UuidBackfill::run(static::$db, $this->proj);
        $uuid = $this->uuidOf(981);

        $this->assertSame($this->proj . "files/{$uuid}.jpg", Store::path($this->proj, $uuid, 'JPG'));
        $this->assertFileExists(Store::path($this->proj, $uuid, 'JPG'));
    }

    public function testItLeavesWhatIsAlreadyRightAlone(): void
    {
        $uuid = static::insertFile(972, 'png', 'ok');
        file_put_contents($this->proj . "files/{$uuid}.png", 'mine');

        $res = UuidBackfill::run(static::$db, $this->proj);

        $this->assertSame(0, $res['assigned'], 'a uuid is never replaced');
        $this->assertSame('mine', file_get_contents($this->proj . "files/{$uuid}.png"));
        $this->assertSame($uuid, $this->uuidOf(972));
    }

    public function testItIsIdempotent(): void
    {
        $this->bareRow(973, 'jpg');
        UuidBackfill::run(static::$db, $this->proj);
        $uuid = $this->uuidOf(973);

        $again = UuidBackfill::run(static::$db, $this->proj);

        $this->assertSame(['assigned' => 0, 'renamed' => 0, 'missing' => $this->fixtureRows(), 'failed' => 0], $again);
        $this->assertSame($uuid, $this->uuidOf(973));
    }

    public function testAMissingBinaryIsCountedNotAnError(): void
    {
        $this->bareRow(974, 'jpg', null);

        $res = UuidBackfill::run(static::$db, $this->proj);

        $this->assertSame(1, $res['assigned']);
        $this->assertSame(1 + $this->fixtureRows(), $res['missing'], 'ours, plus the fixture rows that have no binary here');
        $this->assertSame(0, $res['failed']);
    }

    public function testADryRunChangesNothingAndSaysWhatWouldHappen(): void
    {
        $this->bareRow(975, 'jpg');
        $this->bareRow(976, 'png', null);

        $plan = UuidBackfill::plan(static::$db, $this->proj);

        $this->assertSame(2, $plan['without_uuid']);
        $this->assertNull($this->uuidOf(975), 'nothing was assigned');
        $this->assertFileExists($this->proj . 'files/975.jpg', 'nothing was renamed');
    }

    public function testWithoutAProjectDirOnlyTheDatabaseIsTouched(): void
    {
        $this->bareRow(977, 'jpg');

        $res = UuidBackfill::run(static::$db, null);

        $this->assertSame(1, $res['assigned']);
        $this->assertSame(0, $res['renamed']);
        $this->assertFileExists($this->proj . 'files/977.jpg');
    }

    public function testTheCacheKeyedByIdIsDropped(): void
    {
        $this->bareRow(978, 'png');
        file_put_contents($this->proj . 'cache/thumb/978.webp', 'x');

        UuidBackfill::run(static::$db, $this->proj);

        $this->assertFileDoesNotExist($this->proj . 'cache/thumb/978.webp');
    }

    // ── the scan for old-style addresses ──────────────────────────────────────

    public function testFindsOnlyOldStyleAddresses(): void
    {
        $text = 'a ![](projects/demo/files/42.jpg) b /demo/api/file/thumb/42.webp c '
              . 'projects/demo/files/3f2b8c1e-5d4a-4b7e-9a10-6c2d8e4f1a77.jpg d /api/file/3f2b8c1e-5d4a-4b7e-9a10-6c2d8e4f1a77';

        $found = LegacyUrls::find($text);

        $this->assertSame(['projects/demo/files/42.jpg', '/api/file/thumb/42.webp'], $found);
        $this->assertSame([], LegacyUrls::find(null));
        $this->assertSame([], LegacyUrls::find('nothing to see'));
    }

    public function testScanReportsTheRecordAndFieldAndChangesNothing(): void
    {
        static::$db->exec("UPDATE items SET description = 'see projects/demo/files/7.png here' WHERE id = 1");

        $found = LegacyUrls::scan(static::$db, static::$cfg);

        $hits = array_filter($found, fn($f) => $f['where'] === 'items' && $f['id'] === 1 && $f['field'] === 'description');
        $this->assertCount(1, $hits, json_encode($found));
        $this->assertSame(['projects/demo/files/7.png'], array_values($hits)[0]['found']);
        $this->assertSame(
            'see projects/demo/files/7.png here',
            static::$db->query('SELECT description FROM items WHERE id = 1', [], 'read')[0]['description'],
            'read-only'
        );
    }

    public function testScanIsQuietWhenThereIsNothing(): void
    {
        $this->assertSame([], array_values(array_filter(
            LegacyUrls::scan(static::$db, static::$cfg),
            fn($f) => $f['where'] === 'items' && $f['field'] === 'name'
        )));
    }
}
