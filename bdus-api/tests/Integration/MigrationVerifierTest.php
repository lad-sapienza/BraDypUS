<?php

namespace Tests\Integration;

use PHPUnit\Framework\TestCase;
use DB\DB;
use DB\System\Manage;
use DB\System\Migrate;
use DB\Verify\MigrationVerifier;
use Monolog\Logger;
use Monolog\Handler\NullHandler;

/**
 * Tests for DB\Verify\MigrationVerifier.
 *
 * Each test builds an in-memory SQLite DB in a known good or known broken
 * state and asserts on the structured report (never on printed text).
 */
class MigrationVerifierTest extends TestCase
{
    private DB $db;

    protected function setUp(): void
    {
        $log = new Logger('test');
        $log->pushHandler(new NullHandler());

        $this->db = new DB('test_verify', ['db_engine' => 'sqlite', 'db_path' => ':memory:']);
        $this->db->setLog($log);

        $manage = new Manage($this->db);
        foreach ($manage->available_tables as $tb) {
            $manage->createTable($tb);
        }
    }

    // ── helpers ──────────────────────────────────────────────────────────────

    private function makeDb(string $name): DB
    {
        $log = new Logger('test');
        $log->pushHandler(new NullHandler());
        $db = new DB($name, ['db_engine' => 'sqlite', 'db_path' => ':memory:']);
        $db->setLog($log);
        return $db;
    }

    private function markAllMigrationsApplied(?DB $db = null): void
    {
        $db ??= $this->db;
        foreach (Migrate::ALL_MIGRATIONS as $class) {
            $db->query(
                'INSERT INTO bdus_migrations (name, applied_at) VALUES (?, ?)',
                [$class::NAME, time()],
                'boolean'
            );
        }
    }

    private function cfgTable(string $name, int $isPlugin = 0, ?DB $db = null): void
    {
        ($db ?? $this->db)->query(
            'INSERT INTO bdus_cfg_tables (name, is_plugin, sort) VALUES (?, ?, 0)',
            [$name, $isPlugin],
            'boolean'
        );
    }

    private function cfgRelation(string $from, string $to, ?DB $db = null): void
    {
        ($db ?? $this->db)->query(
            'INSERT INTO bdus_cfg_relations (from_tb, from_col, to_tb, to_col, on_delete, on_update)
             VALUES (?, ?, ?, ?, ?, ?)',
            [$from, 'id_link', $to, 'id', 'RESTRICT', 'CASCADE'],
            'boolean'
        );
    }

    /** Returns the single check with the given id, or fails the test. */
    private function check(array $report, string $id): array
    {
        foreach ($report['checks'] as $c) {
            if ($c['id'] === $id) {
                return $c;
            }
        }
        $this->fail("no check with id '{$id}' in report (" .
            implode(', ', array_column($report['checks'], 'id')) . ')');
    }

    /**
     * Minimal healthy v5 app: a normal table with one correctly-linked plugin
     * table (post-M038 shape — no table_link column), all migrations applied.
     */
    private function seedHealthy(): void
    {
        $this->markAllMigrationsApplied();
        $this->db->exec('CREATE TABLE items (id INTEGER PRIMARY KEY AUTOINCREMENT, creator INTEGER)');
        $this->db->exec('CREATE TABLE tags (id INTEGER PRIMARY KEY AUTOINCREMENT, id_link INTEGER NOT NULL, label TEXT)');
        $this->db->exec('INSERT INTO items (id) VALUES (1), (2)');
        $this->db->exec("INSERT INTO tags (id_link, label) VALUES (1, 'a'), (2, 'b')");
        $this->cfgTable('items', 0);
        $this->cfgTable('tags', 1);
        $this->cfgRelation('tags', 'items');
    }

    // ── tests ────────────────────────────────────────────────────────────────

    public function testHealthyAppHasNoFailures(): void
    {
        $this->seedHealthy();

        $report = (new MigrationVerifier($this->db))->run();

        $this->assertTrue($report['summary']['ok'], json_encode($report['checks'], JSON_PRETTY_PRINT));
        $this->assertSame(0, $report['summary']['failed']);
        $this->assertSame('pass', $this->check($report, 'migrations_applied')['status']);
        $this->assertSame('pass', $this->check($report, 'plugin_relations')['status']);
        $this->assertFalse($report['baseline_used']);
    }

    public function testPendingMigrationFails(): void
    {
        $this->seedHealthy();
        // Remove the last migration marker.
        $all  = Migrate::ALL_MIGRATIONS;
        $last = (end($all))::NAME;
        $this->db->query('DELETE FROM bdus_migrations WHERE name = ?', [$last], 'boolean');

        $report = (new MigrationVerifier($this->db))->run();

        $c = $this->check($report, 'migrations_applied');
        $this->assertSame('fail', $c['status']);
        $this->assertContains($last, $c['items']);
        $this->assertFalse($report['summary']['ok']);
    }

    public function testPluginTableWithoutRelationFails(): void
    {
        $this->seedHealthy();
        $this->db->exec('CREATE TABLE loose (id INTEGER PRIMARY KEY AUTOINCREMENT, id_link INTEGER)');
        $this->cfgTable('loose', 1); // is_plugin, but no bdus_cfg_relations row

        $report = (new MigrationVerifier($this->db))->run();

        $c = $this->check($report, 'plugin_relations');
        $this->assertSame('fail', $c['status']);
        $this->assertStringContainsString('loose', implode("\n", $c['items']));
    }

    public function testPluginRelationToMissingParentFails(): void
    {
        $this->seedHealthy();
        $this->db->exec('CREATE TABLE widgets (id INTEGER PRIMARY KEY AUTOINCREMENT, id_link INTEGER)');
        $this->cfgTable('widgets', 1);
        $this->cfgRelation('widgets', 'no_such_parent');

        $report = (new MigrationVerifier($this->db))->run();

        $this->assertSame('fail', $this->check($report, 'plugin_relations')['status']);
    }

    public function testResidualMultiTenantPluginFails(): void
    {
        $this->seedHealthy();
        // A plugin table that still carries table_link with two distinct parents.
        $this->db->exec(
            'CREATE TABLE m_shared (id INTEGER PRIMARY KEY AUTOINCREMENT, table_link TEXT, id_link INTEGER)'
        );
        $this->db->exec("INSERT INTO m_shared (table_link, id_link) VALUES ('items', 1), ('other', 2)");
        $this->cfgTable('m_shared', 1);
        $this->cfgRelation('m_shared', 'items');

        $report = (new MigrationVerifier($this->db))->run();

        $c = $this->check($report, 'plugin_residual_multitenant');
        $this->assertSame('fail', $c['status']);
        $this->assertStringContainsString('m_shared', implode("\n", $c['items']));
    }

    public function testLegacyPrefixTableFails(): void
    {
        $this->seedHealthy();
        $this->db->exec('CREATE TABLE paths__leftover (id INTEGER PRIMARY KEY AUTOINCREMENT)');

        $report = (new MigrationVerifier($this->db))->run();

        $c = $this->check($report, 'prefix_residue');
        $this->assertSame('fail', $c['status']);
        $this->assertStringContainsString('paths__leftover', implode("\n", $c['items']));
    }

    public function testLegacyPrefixInDataColumnFails(): void
    {
        $this->seedHealthy();
        $this->db->query(
            'INSERT INTO bdus_rs (tb, first, second, relation) VALUES (?, ?, ?, ?)',
            ['paths__items', 1, 2, 1],
            'boolean'
        );

        $report = (new MigrationVerifier($this->db, ['legacyPrefixes' => ['paths']]))->run();

        $this->assertSame('fail', $this->check($report, 'prefix_residue')['status']);
    }

    public function testFileLinkStuckInUserlinksFails(): void
    {
        $this->seedHealthy();
        $this->db->query(
            'INSERT INTO bdus_userlinks (tb_one, id_one, tb_two, id_two, sort) VALUES (?, ?, ?, ?, ?)',
            ['files', 5, 'items', 1, 0],
            'boolean'
        );

        $report = (new MigrationVerifier($this->db))->run();

        $this->assertSame('fail', $this->check($report, 'file_links_integrity')['status']);
    }

    public function testOrphanFileLinkFails(): void
    {
        $this->seedHealthy();
        // Legacy v4 data is not FK-enforced — simulate an orphan file_id that a
        // migration could carry over.
        $this->db->exec('PRAGMA foreign_keys = OFF');
        $this->db->query(
            'INSERT INTO bdus_file_links (file_id, table_name, record_id, sort) VALUES (?, ?, ?, ?)',
            [999, 'items', 1, 0],
            'boolean'
        );
        $this->db->exec('PRAGMA foreign_keys = ON');

        $report = (new MigrationVerifier($this->db))->run();

        $this->assertSame('fail', $this->check($report, 'file_links_integrity')['status']);
    }

    public function testRsUnresolvedIdFails(): void
    {
        $this->seedHealthy();
        // items has ids 1,2 — second=999 does not resolve.
        $this->db->query(
            'INSERT INTO bdus_rs (tb, first, second, relation) VALUES (?, ?, ?, ?)',
            ['items', 1, 999, 1],
            'boolean'
        );

        $report = (new MigrationVerifier($this->db))->run();

        $this->assertSame('fail', $this->check($report, 'rs_integrity')['status']);
    }

    public function testRsBackupTableWarns(): void
    {
        $this->seedHealthy();
        $this->db->exec('CREATE TABLE bdus_rs_v4_backup (id INTEGER PRIMARY KEY AUTOINCREMENT)');

        $report = (new MigrationVerifier($this->db))->run();

        $this->assertSame('warn', $this->check($report, 'rs_backup_table')['status']);
        // A warning alone does not fail the run.
        $this->assertTrue($report['summary']['ok']);
    }

    public function testLegacyViewWarns(): void
    {
        $this->seedHealthy();
        $this->db->exec('CREATE VIEW items_v AS SELECT id FROM items');

        $report = (new MigrationVerifier($this->db))->run();

        $c = $this->check($report, 'legacy_views');
        $this->assertSame('warn', $c['status']);
        $this->assertContains('items_v', $c['items']);
    }

    public function testBaselineRowCountConservationPasses(): void
    {
        $this->seedHealthy();

        $baseline = $this->makeDb('base_ok');
        $baseline->exec('CREATE TABLE items (id INTEGER PRIMARY KEY AUTOINCREMENT)');
        $baseline->exec('INSERT INTO items (id) VALUES (1), (2)'); // same count as live

        $report = (new MigrationVerifier($this->db, ['baseline' => $baseline]))->run();

        $this->assertTrue($report['baseline_used']);
        $this->assertSame('pass', $this->check($report, 'row_count_conservation')['status']);
    }

    public function testBaselineRowCountLossFails(): void
    {
        $this->seedHealthy(); // live items = 2 rows

        $baseline = $this->makeDb('base_loss');
        $baseline->exec('CREATE TABLE items (id INTEGER PRIMARY KEY AUTOINCREMENT)');
        $baseline->exec('INSERT INTO items (id) VALUES (1), (2), (3), (4)'); // 4 in v4 → 2 now

        $report = (new MigrationVerifier($this->db, ['baseline' => $baseline]))->run();

        $c = $this->check($report, 'row_count_conservation');
        $this->assertSame('fail', $c['status']);
        $this->assertStringContainsString('missing', implode("\n", $c['items']));
    }

    public function testBaselineDetectsPluginSplitConservation(): void
    {
        $this->markAllMigrationsApplied();
        // Live: original kept its own tenant's rows + one twin for the other.
        $this->db->exec('CREATE TABLE m_x (id INTEGER PRIMARY KEY AUTOINCREMENT, id_link INTEGER)');
        $this->db->exec('CREATE TABLE m_x_places (id INTEGER PRIMARY KEY AUTOINCREMENT, id_link INTEGER)');
        $this->db->exec('INSERT INTO m_x (id_link) VALUES (1), (2)');
        $this->db->exec('INSERT INTO m_x_places (id_link) VALUES (10)');

        $baseline = $this->makeDb('base_split');
        $baseline->exec('CREATE TABLE m_x (id INTEGER PRIMARY KEY AUTOINCREMENT, table_link TEXT, id_link INTEGER)');
        $baseline->exec("INSERT INTO m_x (table_link, id_link) VALUES ('items', 1), ('items', 2), ('places', 10)");

        $report = (new MigrationVerifier($this->db, ['baseline' => $baseline]))->run();

        $this->assertSame('pass', $this->check($report, 'row_count_conservation')['status']);
    }

    public function testBaselinePluginSplitWithLostRowsFails(): void
    {
        $this->markAllMigrationsApplied();
        // Live: original kept 2 rows, but the 'places' tenant rows vanished (no twin).
        $this->db->exec('CREATE TABLE m_x (id INTEGER PRIMARY KEY AUTOINCREMENT, id_link INTEGER)');
        $this->db->exec('INSERT INTO m_x (id_link) VALUES (1), (2)');

        $baseline = $this->makeDb('base_split_loss');
        $baseline->exec('CREATE TABLE m_x (id INTEGER PRIMARY KEY AUTOINCREMENT, table_link TEXT, id_link INTEGER)');
        $baseline->exec("INSERT INTO m_x (table_link, id_link) VALUES ('items', 1), ('items', 2), ('places', 10)");

        $report = (new MigrationVerifier($this->db, ['baseline' => $baseline]))->run();

        $this->assertSame('fail', $this->check($report, 'row_count_conservation')['status']);
    }

    public function testSystemTableConservationWarnsOnUserCountChange(): void
    {
        $this->seedHealthy();
        $this->db->query(
            'INSERT INTO bdus_users (name, email, password, privilege) VALUES (?, ?, ?, ?)',
            ['A', 'a@example.org', 'x', 1],
            'boolean'
        );

        $baseline = $this->makeDb('base_users');
        (new Manage($baseline))->createTable('bdus_users');
        (new Manage($baseline))->createTable('bdus_rs');
        // baseline has 0 users, live has 1

        $report = (new MigrationVerifier($this->db, ['baseline' => $baseline]))->run();

        $c = $this->check($report, 'system_table_conservation');
        $this->assertSame('warn', $c['status']);
        $this->assertStringContainsString('bdus_users', implode("\n", $c['items']));
    }
}
