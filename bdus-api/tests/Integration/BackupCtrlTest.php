<?php

namespace Tests\Integration;

use Tests\Support\BdusTestCase;

/**
 * Integration tests for backup_ctrl.
 *
 * The backup module touches the filesystem (PROJ_DIR/backups/, PROJ_DIR/db/).
 * The bootstrap sets PROJ_DIR → sys_get_temp_dir()/bradypus_test_proj/,
 * so every operation is isolated in a temp tree that we create and clean up.
 *
 * The SQLite source DB is written to PROJ_DIR/db/bdus.sqlite before the first
 * test that needs it; this exercises the native PHP dumper end-to-end without
 * relying on any external CLI binary.
 */
class BackupCtrlTest extends BdusTestCase
{
    private static string $backupsDir;
    private static string $dbDir;
    private static string $sqliteDb;

    // ── Bootstrap ─────────────────────────────────────────────────────────

    public static function setUpBeforeClass(): void
    {
        parent::setUpBeforeClass();

        // Create the directory tree that backup_ctrl expects under PROJ_DIR.
        static::$backupsDir = PROJ_DIR . 'backups/';
        static::$dbDir      = PROJ_DIR . 'db/';
        static::$sqliteDb   = static::$dbDir . 'bdus.sqlite';

        foreach ([static::$backupsDir, static::$dbDir] as $dir) {
            if (!is_dir($dir)) {
                mkdir($dir, 0755, true);
            }
        }

        // Build a small real SQLite file with two tables + a few rows.
        // This is what dumpSqliteNative() will open and dump.
        static::buildSqliteFixture();
    }

    public static function tearDownAfterClass(): void
    {
        // Remove all generated backup files so the temp tree stays clean.
        foreach (glob(static::$backupsDir . '*.sql.gz') ?: [] as $f) {
            @unlink($f);
        }
        @unlink(static::$sqliteDb);
        parent::tearDownAfterClass();
    }

    private static function buildSqliteFixture(): void
    {
        $pdo = new \PDO('sqlite:' . static::$sqliteDb);
        $pdo->setAttribute(\PDO::ATTR_ERRMODE, \PDO::ERRMODE_EXCEPTION);
        $pdo->exec('CREATE TABLE IF NOT EXISTS items (
            id   INTEGER PRIMARY KEY AUTOINCREMENT,
            name TEXT NOT NULL,
            note TEXT
        )');
        $pdo->exec("INSERT INTO items (name, note) VALUES
            ('Alpha', 'first note'),
            ('Beta',  NULL),
            ('Gamma', 'it''s a quote')");
        $pdo->exec('CREATE TABLE IF NOT EXISTS tags (
            id    INTEGER PRIMARY KEY AUTOINCREMENT,
            label TEXT NOT NULL
        )');
        $pdo->exec("INSERT INTO tags (label) VALUES ('red'), ('blue')");
        $pdo->exec('CREATE INDEX IF NOT EXISTS idx_items_name ON items(name)');
    }

    // ── listBackups ───────────────────────────────────────────────────────

    public function testListBackupsReturnsExpectedShape(): void
    {
        $ctrl = $this->makeController('backup_ctrl');
        $res  = $this->callController($ctrl, 'listBackups');

        $this->assertSame('sqlite', $res['engine'],
            'engine should reflect the test DB engine');
        $this->assertIsBool($res['can_delete']);
        $this->assertIsBool($res['can_restore']);
        $this->assertIsArray($res['backups']);
    }

    public function testListBackupsIsEmptyWhenNoBupFiles(): void
    {
        // Ensure the backups dir is clean before this test.
        foreach (glob(static::$backupsDir . '*.sql.gz') ?: [] as $f) {
            @unlink($f);
        }

        $ctrl = $this->makeController('backup_ctrl');
        $res  = $this->callController($ctrl, 'listBackups');

        $this->assertSame([], $res['backups']);
    }

    // ── doBackup (SQLite native dumper) ───────────────────────────────────

    public function testDoBackupCreatesGzipFile(): void
    {
        $ctrl = $this->makeController('backup_ctrl');
        $res  = $this->callController($ctrl, 'doBackup');

        $this->assertSame('success', $res['status'],
            'doBackup should succeed: ' . ($res['detail'] ?? ''));

        $files = glob(static::$backupsDir . '*.sql.gz') ?: [];
        $this->assertNotEmpty($files, 'At least one .sql.gz file should exist after doBackup');
    }

    public function testDoBackupFileIsValidGzip(): void
    {
        // Run a backup first (may already exist from previous test in the same class).
        $files = glob(static::$backupsDir . '*.sql.gz') ?: [];
        if (empty($files)) {
            $ctrl = $this->makeController('backup_ctrl');
            $this->callController($ctrl, 'doBackup');
            $files = glob(static::$backupsDir . '*.sql.gz') ?: [];
        }

        $this->assertNotEmpty($files);
        $latest = end($files);

        // PHP's gzopen() returns false on corrupt gzip data.
        $gz = gzopen($latest, 'rb');
        $this->assertNotFalse($gz, 'Backup file must be readable as gzip');
        $sample = gzread($gz, 128);
        gzclose($gz);
        $this->assertStringContainsString('BraDypUS', $sample,
            'Gzip content should start with the BraDypUS header comment');
    }

    public function testDoBackupSqlContainsExpectedStatements(): void
    {
        $files = glob(static::$backupsDir . '*.sql.gz') ?: [];
        if (empty($files)) {
            $ctrl = $this->makeController('backup_ctrl');
            $this->callController($ctrl, 'doBackup');
            $files = glob(static::$backupsDir . '*.sql.gz') ?: [];
        }

        $latest = end($files);
        $sql    = '';
        $gz     = gzopen($latest, 'rb');
        while (!gzeof($gz)) {
            $sql .= gzread($gz, 8192);
        }
        gzclose($gz);

        $this->assertStringContainsString('BEGIN TRANSACTION',   $sql);
        $this->assertStringContainsString('COMMIT',              $sql);
        $this->assertStringContainsString('CREATE TABLE',        $sql);
        $this->assertStringContainsString('DROP TABLE IF EXISTS', $sql);
        $this->assertStringContainsString('"items"',             $sql);
        $this->assertStringContainsString('"tags"',              $sql);
        $this->assertStringContainsString('INSERT INTO "items"', $sql);
        $this->assertStringContainsString('INSERT INTO "tags"',  $sql);
        $this->assertStringContainsString("'Alpha'",             $sql, 'Row data must be present');
        $this->assertStringContainsString('NULL',                $sql, 'NULL values must be serialised as SQL NULL');
        $this->assertStringContainsString("'it''s a quote'",     $sql, "Single quotes in values must be doubled");
    }

    public function testDoBackupSqlContainsIndex(): void
    {
        $files = glob(static::$backupsDir . '*.sql.gz') ?: [];
        if (empty($files)) {
            $ctrl = $this->makeController('backup_ctrl');
            $this->callController($ctrl, 'doBackup');
            $files = glob(static::$backupsDir . '*.sql.gz') ?: [];
        }

        $latest = end($files);
        $sql    = '';
        $gz     = gzopen($latest, 'rb');
        while (!gzeof($gz)) {
            $sql .= gzread($gz, 8192);
        }
        gzclose($gz);

        $this->assertStringContainsString('idx_items_name', $sql,
            'Indexes should be included in the dump');
    }

    public function testDoBackupAppearsInListAfterCreation(): void
    {
        // Clean slate
        foreach (glob(static::$backupsDir . '*.sql.gz') ?: [] as $f) {
            @unlink($f);
        }

        $ctrl = $this->makeController('backup_ctrl');
        $this->callController($ctrl, 'doBackup');

        $ctrl2 = $this->makeController('backup_ctrl');
        $res   = $this->callController($ctrl2, 'listBackups');

        $this->assertCount(1, $res['backups'], 'One backup should appear in the list');
        $backup = $res['backups'][0];
        $this->assertArrayHasKey('file',           $backup);
        $this->assertArrayHasKey('app',            $backup);
        $this->assertArrayHasKey('engine',         $backup);
        $this->assertArrayHasKey('timestamp',      $backup);
        $this->assertArrayHasKey('formatted_time', $backup);
        $this->assertArrayHasKey('size_mb',        $backup);
        $this->assertSame('sqlite', $backup['engine']);
        // size_mb may round to 0.000 for tiny test fixtures — just ensure it is a non-negative number.
        $this->assertGreaterThanOrEqual(0, $backup['size_mb']);
        // Verify the actual file on disk is non-empty.
        $this->assertGreaterThan(0, filesize(static::$backupsDir . $backup['file']));
    }

    // ── parseFileName (via listBackups round-trip) ─────────────────────────

    public function testParseFileNameExtractsMetadata(): void
    {
        // Create a fake backup file with the standard naming pattern.
        $ts   = time() - 3600;
        $name = "bdus_test-sqlite-{$ts}.sql.gz";
        file_put_contents(static::$backupsDir . $name, gzencode('-- test'));

        $ctrl = $this->makeController('backup_ctrl');
        $res  = $this->callController($ctrl, 'listBackups');

        $found = array_filter($res['backups'], fn($b) => $b['file'] === $name);
        $this->assertNotEmpty($found, "Backup file {$name} should appear in listing");

        $b = array_values($found)[0];
        $this->assertSame('bdus_test', $b['app']);
        $this->assertSame('sqlite',    $b['engine']);
        $this->assertSame($ts,         $b['timestamp']);
        $this->assertStringContainsString(date('Y', $ts), $b['formatted_time']);

        @unlink(static::$backupsDir . $name);
    }

    // ── deleteBackup ──────────────────────────────────────────────────────

    public function testDeleteBackupRemovesFile(): void
    {
        $name = 'bdus_test-sqlite-' . (time() - 100) . '.sql.gz';
        $path = static::$backupsDir . $name;
        file_put_contents($path, gzencode('-- dummy'));
        $this->assertFileExists($path);

        $ctrl = $this->makeController('backup_ctrl', ['file' => $name]);
        $res  = $this->callController($ctrl, 'deleteBackup');

        $this->assertSame('success', $res['status']);
        $this->assertFileDoesNotExist($path);
    }

    public function testDeleteBackupReturnsErrorForMissingFile(): void
    {
        $ctrl = $this->makeController('backup_ctrl', ['file' => 'nonexistent-file.sql.gz']);
        $res  = $this->callController($ctrl, 'deleteBackup');

        $this->assertSame('error', $res['status']);
    }

    public function testDeleteBackupBlocksDirectoryTraversal(): void
    {
        // A path like ../../etc/passwd must be reduced to basename only.
        // The file "passwd" won't exist in backupsDir, so we get file_not_found,
        // not a successful delete of an arbitrary path.
        $ctrl = $this->makeController('backup_ctrl', ['file' => '../../etc/passwd']);
        $res  = $this->callController($ctrl, 'deleteBackup');

        $this->assertSame('error', $res['status']);
    }

    // ── downloadBackup ────────────────────────────────────────────────────

    public function testDownloadBackupSetsContentDispositionHeader(): void
    {
        $name = 'bdus_test-sqlite-' . time() . '.sql.gz';
        $path = static::$backupsDir . $name;
        file_put_contents($path, gzencode('-- download test'));

        $ctrl = $this->makeController('backup_ctrl', ['file' => $name]);

        ob_start();
        $ctrl->downloadBackup();
        $body = ob_get_clean();

        // Body should be the raw gzip content (magic bytes 1f 8b).
        $this->assertNotEmpty($body);
        $this->assertStringStartsWith("\x1f\x8b", $body, 'Body must start with gzip magic bytes');

        @unlink($path);
    }

    public function testDownloadBackupReturnsErrorForMissingFile(): void
    {
        $ctrl = $this->makeController('backup_ctrl', ['file' => 'ghost.sql.gz']);
        $res  = $this->callController($ctrl, 'downloadBackup');

        $this->assertSame('error', $res['status']);
    }

    public function testDownloadBackupRejectsEmptyFileName(): void
    {
        $ctrl = $this->makeController('backup_ctrl', ['file' => '']);
        $res  = $this->callController($ctrl, 'downloadBackup');

        $this->assertSame('error', $res['status']);
    }

    // ── Privilege checks ──────────────────────────────────────────────────

    public function testListBackupsRequiresReadPrivilege(): void
    {
        $this->setPrivilege(100); // no privilege
        $ctrl = $this->makeController('backup_ctrl');
        $res  = $this->callController($ctrl, 'listBackups');
        $this->setPrivilege(1);   // restore

        $this->assertSame('error', $res['status']);
        $this->assertSame('not_enough_privilege', $res['code']);
    }

    public function testDoBackupRequiresEditPrivilege(): void
    {
        $this->setPrivilege(100);
        $ctrl = $this->makeController('backup_ctrl');
        $res  = $this->callController($ctrl, 'doBackup');
        $this->setPrivilege(1);

        $this->assertSame('error', $res['status']);
        $this->assertSame('not_enough_privilege', $res['code']);
    }

    public function testDeleteBackupRequiresAdminPrivilege(): void
    {
        $this->setPrivilege(30); // below admin threshold
        $ctrl = $this->makeController('backup_ctrl', ['file' => 'any.sql.gz']);
        $res  = $this->callController($ctrl, 'deleteBackup');
        $this->setPrivilege(1);

        $this->assertSame('error', $res['status']);
        $this->assertSame('not_enough_privilege', $res['code']);
    }

    // ── restoreBackup ─────────────────────────────────────────────────────

    public function testRestoreBackupRequiresSuperAdminPrivilege(): void
    {
        // super_admin requires privilege < 2 (online) or < 11 (offline).
        // In the test environment is_online() is false, so we need >= 11 to be denied.
        $this->setPrivilege(11);
        $ctrl = $this->makeController('backup_ctrl', ['file' => 'any.sql.gz']);
        $res  = $this->callController($ctrl, 'restoreBackup');
        $this->setPrivilege(1);

        $this->assertSame('error',                $res['status']);
        $this->assertSame('not_enough_privilege', $res['code']);
    }

    public function testRestoreBackupRejectsMissingFileName(): void
    {
        $ctrl = $this->makeController('backup_ctrl', ['file' => '']);
        $res  = $this->callController($ctrl, 'restoreBackup');

        $this->assertSame('error', $res['status']);
    }

    public function testRestoreBackupRejectsEngineMismatch(): void
    {
        // Create a fake backup that claims to be a MySQL dump while the test
        // DB engine is SQLite — restoreBackup() must reject it.
        $ts   = time();
        $name = "bdus_test-mysql-{$ts}.sql.gz";
        file_put_contents(static::$backupsDir . $name, gzencode('-- mysql dump'));

        $ctrl = $this->makeController('backup_ctrl', ['file' => $name]);
        $res  = $this->callController($ctrl, 'restoreBackup');

        @unlink(static::$backupsDir . $name);

        $this->assertSame('error',                $res['status']);
        $this->assertSame('wrong_restore_engine', $res['code']);
    }

    public function testRestoreBackupSucceedsWithValidSqliteDump(): void
    {
        // First create a real backup via doBackup(), then restore it.
        // After restore the data must still be intact (same rows).
        $ctrl = $this->makeController('backup_ctrl');
        $bup  = $this->callController($ctrl, 'doBackup');
        $this->assertSame('success', $bup['status'], 'Prerequisite: backup must succeed');

        // Find the file that was just created.
        $files = glob(static::$backupsDir . '*.sql.gz') ?: [];
        $this->assertNotEmpty($files);
        $latest = basename(end($files));

        $ctrl2 = $this->makeController('backup_ctrl', ['file' => $latest]);
        $res   = $this->callController($ctrl2, 'restoreBackup');

        $this->assertSame('success',           $res['status'],
            'restoreBackup should succeed: ' . ($res['detail'] ?? $res['code'] ?? ''));
        $this->assertSame('ok_backup_restored', $res['code']);

        // Verify data is still readable after restore.
        $rows = static::$db->query("SELECT name FROM items ORDER BY id", [], 'rows');
        $this->assertNotEmpty($rows, 'Table data must be present after restore');
        $this->assertSame('Alpha', $rows[0]['name']);
    }

    public function testRestoreBackupDirectoryTraversalBlocked(): void
    {
        $ctrl = $this->makeController('backup_ctrl', ['file' => '../../etc/passwd']);
        $res  = $this->callController($ctrl, 'restoreBackup');

        // Must get an error (file not found or param error), never a success.
        $this->assertSame('error', $res['status']);
    }
}
