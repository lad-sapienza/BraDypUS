<?php

namespace Tests\Integration;

use PHPUnit\Framework\TestCase;
use DB\DB;
use DB\System\Manage;
use DB\System\Migrations\M060_AddUuidToFiles;
use File\Store;
use Monolog\Logger;
use Monolog\Handler\NullHandler;

/**
 * M060_AddUuidToFiles: uuid + public columns, unique index, rename of the binaries,
 * derived cache purge. Idempotent and resumable. Always run with an explicit project
 * dir: the tests' PROJ_DIR must never be touched by a migration.
 */
class M060MigrationTest extends TestCase
{
    private DB     $db;
    private Manage $manage;
    private string $proj;

    protected function setUp(): void
    {
        $log = new Logger('test');
        $log->pushHandler(new NullHandler());
        $this->db = new DB('test_m060_' . uniqid(), ['db_engine' => 'sqlite', 'db_path' => ':memory:']);
        $this->db->setLog($log);
        $this->manage = new Manage($this->db);

        // The shape of bdus_files before M060
        $this->db->exec(
            'CREATE TABLE bdus_files (id INTEGER PRIMARY KEY, creator TEXT NOT NULL, ext TEXT NOT NULL, '
            . 'keywords TEXT, description TEXT, printable INTEGER, filename TEXT NOT NULL, attribution TEXT)'
        );
        $this->manage->createTable('bdus_cfg_fields');

        $this->proj = sys_get_temp_dir() . '/bdus_m060_' . uniqid() . '/';
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
    }

    private function addFile(int $id, string $ext, ?string $content = 'bin'): void
    {
        $this->db->query(
            "INSERT INTO bdus_files (id, creator, ext, filename) VALUES (?, 'x', ?, ?)",
            [$id, $ext, "f{$id}"],
            'boolean'
        );
        if ($content !== null) {
            file_put_contents($this->proj . "files/{$id}.{$ext}", $content);
        }
    }

    private function uuidOf(int $id): string
    {
        return (string) $this->db->query('SELECT uuid FROM bdus_files WHERE id = ?', [$id], 'read')[0]['uuid'];
    }

    private function migrate(): void
    {
        M060_AddUuidToFiles::run($this->manage, $this->proj);
    }

    public function testAddsColumnsAndGivesEveryFileAUniqueCanonicalUuid(): void
    {
        $this->addFile(1, 'jpg');
        $this->addFile(2, 'pdf');
        $this->addFile(3, 'png');

        $this->migrate();

        $this->assertTrue($this->manage->columnExists('bdus_files', 'uuid'));
        $this->assertTrue($this->manage->columnExists('bdus_files', 'public'));
        $uuids = array_map(fn($i) => $this->uuidOf($i), [1, 2, 3]);
        foreach ($uuids as $u) {
            $this->assertTrue(Store::isUuid($u), $u);
        }
        $this->assertCount(3, array_unique($uuids));

        // The unique index is real
        $this->expectException(\Throwable::class);
        $this->db->query("UPDATE bdus_files SET uuid = ? WHERE id = 2", [$uuids[0]], 'boolean');
    }

    public function testRenamesTheBinariesAndKeepsTheirContent(): void
    {
        $this->addFile(1, 'jpg', 'one');
        $this->addFile(2, 'pdf', 'two');

        $this->migrate();

        foreach ([1 => ['jpg', 'one'], 2 => ['pdf', 'two']] as $id => [$ext, $content]) {
            $new = $this->proj . 'files/' . $this->uuidOf($id) . '.' . $ext;
            $this->assertFileExists($new);
            $this->assertSame($content, file_get_contents($new));
            $this->assertFileDoesNotExist($this->proj . "files/{$id}.{$ext}", 'no id-named file is left');
        }
    }

    public function testExistingFilesStartPublicAndARerunDoesNotOverwriteLaterChoices(): void
    {
        $this->addFile(1, 'jpg');
        $this->addFile(2, 'jpg');

        $this->migrate();
        $this->assertSame(
            [1, 1],
            array_map(fn($r) => (int) $r['public'], $this->db->query('SELECT public FROM bdus_files ORDER BY id', [], 'read'))
        );

        $this->db->query('UPDATE bdus_files SET public = 0 WHERE id = 2', [], 'boolean');
        $this->migrate();
        $this->assertSame(0, (int) $this->db->query('SELECT public FROM bdus_files WHERE id = 2', [], 'read')[0]['public'],
            'a re-run must not make a file public again');
    }

    public function testRerunKeepsUuidsAndBinaries(): void
    {
        $this->addFile(1, 'jpg', 'one');
        $this->migrate();
        $first = $this->uuidOf(1);

        $this->migrate();

        $this->assertSame($first, $this->uuidOf(1), 'a uuid is never regenerated');
        $this->assertSame('one', file_get_contents($this->proj . "files/{$first}.jpg"));
    }

    public function testAMissingBinaryIsSkippedAndTheOthersAreStillRenamed(): void
    {
        $this->addFile(1, 'jpg', null);   // row without a file on disk
        $this->addFile(2, 'jpg', 'two');

        $this->migrate();

        $this->assertFileExists($this->proj . 'files/' . $this->uuidOf(2) . '.jpg');
        $this->assertFileDoesNotExist($this->proj . 'files/' . $this->uuidOf(1) . '.jpg');
        $this->assertTrue(Store::isUuid($this->uuidOf(1)), 'the row still gets its uuid');
    }

    public function testAFailedRenameMakesItThrowAfterTryingAllAndAReRunCompletesIt(): void
    {
        $this->addFile(1, 'jpg', 'one');
        $this->addFile(2, 'jpg', 'two');
        // Pre-assign file 1's uuid and put a directory where its new name must go: rename() fails.
        $u1 = Store::newUuid();
        $this->db->exec('ALTER TABLE bdus_files ADD COLUMN uuid TEXT');
        $this->db->query('UPDATE bdus_files SET uuid = ? WHERE id = 1', [$u1], 'boolean');
        mkdir($this->proj . "files/{$u1}.jpg");

        try {
            $this->migrate();
            $this->fail('the migration must not report success when a file could not be renamed');
        } catch (\RuntimeException $e) {
            $this->assertStringContainsString('1 file(s)', $e->getMessage());
        }
        $this->assertFileExists($this->proj . 'files/' . $this->uuidOf(2) . '.jpg', 'the other file was still renamed');
        $this->assertFileExists($this->proj . 'files/1.jpg', 'the failed one is left where it was');

        rmdir($this->proj . "files/{$u1}.jpg");
        $this->migrate();
        $this->assertSame('one', file_get_contents($this->proj . "files/{$u1}.jpg"));
    }

    public function testNeverOverwritesAFileThatAlreadyHasItsUuidName(): void
    {
        // An interrupted earlier run (or a manual copy) left both names: the one under the uuid wins.
        $this->addFile(1, 'jpg', 'old');
        $u1 = Store::newUuid();
        $this->db->exec('ALTER TABLE bdus_files ADD COLUMN uuid TEXT');
        $this->db->query('UPDATE bdus_files SET uuid = ? WHERE id = 1', [$u1], 'boolean');
        file_put_contents($this->proj . "files/{$u1}.jpg", 'new');

        $this->migrate();

        $this->assertSame('new', file_get_contents($this->proj . "files/{$u1}.jpg"), 'not overwritten');
        $this->assertFileExists($this->proj . 'files/1.jpg', 'the old one is left for a human to look at');
    }

    public function testDerivedCacheNamedByIdIsPurgedButNotUuidNamedOnes(): void
    {
        $this->addFile(5, 'png');
        $keep = '00000000-0000-4000-8000-000000000009';
        file_put_contents($this->proj . 'cache/thumb/5.webp', 'x');
        file_put_contents($this->proj . 'cache/thumb/5.webp.fail', '');
        file_put_contents($this->proj . "cache/thumb/{$keep}.webp", 'x');

        $this->migrate();

        $this->assertFileDoesNotExist($this->proj . 'cache/thumb/5.webp');
        $this->assertFileDoesNotExist($this->proj . 'cache/thumb/5.webp.fail');
        $this->assertFileExists($this->proj . "cache/thumb/{$keep}.webp");
    }

    public function testAnExtensionThatCannotBeNamedSafelyIsLeftAlone(): void
    {
        $this->addFile(1, 'jpg');
        $this->db->query("UPDATE bdus_files SET ext = 'tar.gz' WHERE id = 1", [], 'boolean');
        file_put_contents($this->proj . 'files/1.tar.gz', 'legacy');

        $this->migrate();

        $this->assertFileExists($this->proj . 'files/1.tar.gz');
    }

    public function testRegistersFieldConfigOnceAndKeepsAUserEditedLabel(): void
    {
        $this->migrate();
        $rows = $this->db->query(
            "SELECT name FROM bdus_cfg_fields WHERE table_name = 'bdus_files' AND name IN ('uuid','public')", [], 'read'
        );
        $this->assertCount(2, $rows);

        $this->db->query("UPDATE bdus_cfg_fields SET label = 'Open' WHERE table_name = 'bdus_files' AND name = 'public'", [], 'boolean');
        $this->migrate();
        $label = $this->db->query("SELECT label FROM bdus_cfg_fields WHERE table_name = 'bdus_files' AND name = 'public'", [], 'read')[0]['label'];
        $this->assertSame('Open', $label);
    }

    public function testAnAppWithoutTheFilesTableIsLeftAlone(): void
    {
        $this->db->exec('DROP TABLE bdus_files');
        $this->migrate();
        $this->assertFalse($this->manage->tableExists('bdus_files'));
    }

    public function testIsRegisteredAndFlaggedAsAffectingFiles(): void
    {
        $this->assertContains(M060_AddUuidToFiles::class, \DB\System\Migrate::ALL_MIGRATIONS);
        $this->assertContains(M060_AddUuidToFiles::NAME, \DB\System\Migrate::FILE_AFFECTING_MIGRATIONS);
    }
}
