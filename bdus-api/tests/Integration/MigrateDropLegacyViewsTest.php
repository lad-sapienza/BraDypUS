<?php

namespace Tests\Integration;

use PHPUnit\Framework\TestCase;
use DB\DB;
use DB\System\Manage;
use DB\System\Migrate;
use Monolog\Logger;
use Monolog\Handler\TestHandler;

/**
 * Regression tests for Migrate::dropLegacyViews().
 *
 * v4 apps can carry hand-written SQL views. Left in place they make SQLite
 * reject the DROP COLUMN / table-recreation migrations (M030/M032/M037/M038)
 * with "error in view … after drop column", aborting the whole major upgrade
 * (observed on the real "paths" app: view paths__colophon_places referenced
 * m_msplaces.table_link, which M038 drops). dropLegacyViews() removes every
 * view — logging its definition first — before the migration loop runs.
 */
class MigrateDropLegacyViewsTest extends TestCase
{
    private DB $db;
    private Manage $manage;
    private Logger $log;
    private TestHandler $logHandler;

    protected function setUp(): void
    {
        $this->logHandler = new TestHandler();
        $this->log = new Logger('test');
        $this->log->pushHandler($this->logHandler);

        $this->db = new DB('test_drop_views', ['db_engine' => 'sqlite', 'db_path' => ':memory:']);
        $this->db->setLog($this->log);
        $this->manage = new Manage($this->db);
    }

    public function testNoOpWhenNoViews(): void
    {
        $this->db->exec('CREATE TABLE t (id INTEGER PRIMARY KEY)');

        Migrate::dropLegacyViews($this->db, $this->log);

        $this->assertSame([], $this->viewNames());
        $this->assertFalse($this->logHandler->hasWarningRecords());
    }

    public function testDropsEveryViewAndLogsItsDefinition(): void
    {
        $this->db->exec('CREATE TABLE t (id INTEGER PRIMARY KEY, name TEXT)');
        $this->db->exec('CREATE VIEW v_names AS SELECT name FROM t');
        $this->db->exec('CREATE VIEW v_ids   AS SELECT id   FROM t');

        Migrate::dropLegacyViews($this->db, $this->log);

        $this->assertSame([], $this->viewNames());
        $this->assertTrue($this->logHandler->hasWarningThatContains("dropping legacy SQL view 'v_names'"));
        $this->assertTrue($this->logHandler->hasWarningThatContains("dropping legacy SQL view 'v_ids'"));
        // The original definition is preserved in the log so an admin can recreate it.
        $this->assertTrue($this->logHandler->hasWarningThatContains('SELECT name FROM t'));
    }

    public function testUnblocksDropColumnOnAViewReferencedTable(): void
    {
        // Reproduces the paths-app failure shape: a plugin table with a
        // table_link column and a view that reads it.
        $this->db->exec(
            'CREATE TABLE tags (id INTEGER PRIMARY KEY, table_link TEXT, id_link INTEGER)'
        );
        $this->db->exec('CREATE VIEW v_tag_link AS SELECT id, table_link FROM tags');

        // SQLite refuses to drop a column referenced by an existing view.
        try {
            $this->db->exec('ALTER TABLE tags DROP COLUMN table_link');
            $this->fail('Expected DROP COLUMN to fail while the legacy view still exists');
        } catch (\Throwable $e) {
            $this->assertStringContainsStringIgnoringCase('view', $e->getMessage());
        }

        Migrate::dropLegacyViews($this->db, $this->log);

        // Same statement now succeeds.
        $this->db->exec('ALTER TABLE tags DROP COLUMN table_link');
        $this->assertFalse($this->manage->columnExists('tags', 'table_link'));
        $this->assertSame([], $this->viewNames());
    }

    public function testIsIdempotent(): void
    {
        $this->db->exec('CREATE TABLE t (id INTEGER PRIMARY KEY)');
        $this->db->exec('CREATE VIEW v AS SELECT id FROM t');

        Migrate::dropLegacyViews($this->db, $this->log);
        Migrate::dropLegacyViews($this->db, $this->log); // second run must not error

        $this->assertSame([], $this->viewNames());
    }

    private function viewNames(): array
    {
        return array_column(
            $this->db->query("SELECT name FROM sqlite_master WHERE type='view' ORDER BY name", [], 'read'),
            'name'
        );
    }
}
