<?php

namespace Tests\Integration;

use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use DB\DB;
use DB\Inspect;
use Monolog\Logger;
use Monolog\Handler\NullHandler;

/**
 * Inspect::isView / tableExists / getAllTables / listObjects on every engine.
 *
 * SQLite always runs (in memory). PostgreSQL and MariaDB run only when the
 * test containers are reachable (`test.sh --unit --db=pgsql|mysql`, see
 * docker-compose.test.{pg,mysql}.yml) and skip themselves otherwise.
 * PHPUnit does NOT switch engine by itself: without these live connections a
 * `--db=` run says nothing about the drivers.
 */
class InspectObjectsEngineTest extends TestCase
{
    public static function engines(): array
    {
        return [
            'sqlite' => [['db_engine' => 'sqlite', 'db_path' => ':memory:']],
            'pgsql'  => [['db_engine' => 'pgsql', 'db_host' => 'postgres', 'db_port' => 5432,
                          'db_name' => 'bdus_test', 'db_username' => 'bdus', 'db_password' => 'bdus_test_pw']],
            'mysql'  => [['db_engine' => 'mysql', 'db_host' => 'mariadb', 'db_port' => 3306,
                          'db_name' => 'bdus_test', 'db_username' => 'bdus', 'db_password' => 'bdus_test_pw']],
        ];
    }

    private function connect(array $cfg): DB
    {
        $log = new Logger('t');
        $log->pushHandler(new NullHandler());
        try {
            $db = new DB('inspect_objects_' . $cfg['db_engine'], $cfg);
            $db->setLog($log);
            $db->query('SELECT 1');
        } catch (\Throwable $e) {
            $this->markTestSkipped("{$cfg['db_engine']} not reachable — run via test.sh --unit --db=...");
        }
        foreach (['io_v_plain', 'io_v_nopk'] as $v) { $db->exec("DROP VIEW IF EXISTS $v"); }
        foreach (['io_t_child', 'io_t_plain', 'io_t_other'] as $t) { $db->exec("DROP TABLE IF EXISTS $t"); }
        $db->exec('CREATE TABLE io_t_plain (id INTEGER PRIMARY KEY, creator INTEGER, name VARCHAR(50))');
        $db->exec('CREATE TABLE io_t_other (code VARCHAR(10), note VARCHAR(50))');
        $db->exec('CREATE TABLE io_t_child (id INTEGER PRIMARY KEY, parent_id INTEGER, other VARCHAR(5),
                   CONSTRAINT io_fk_parent FOREIGN KEY (parent_id) REFERENCES io_t_plain(id) ON DELETE CASCADE)');
        $db->exec('CREATE VIEW io_v_plain AS SELECT id, creator, name FROM io_t_plain');
        $db->exec('CREATE VIEW io_v_nopk AS SELECT code, note FROM io_t_other');
        return $db;
    }

    private function cleanup(DB $db): void
    {
        foreach (['io_v_plain', 'io_v_nopk'] as $v) { $db->exec("DROP VIEW IF EXISTS $v"); }
        foreach (['io_t_child', 'io_t_plain', 'io_t_other'] as $t) { $db->exec("DROP TABLE IF EXISTS $t"); }
    }

    #[DataProvider('engines')]
    public function testViewDetectionAndTableLists(array $cfg): void
    {
        $db = $this->connect($cfg);
        try {
            $i = new Inspect($db);
            $this->assertTrue($i->isView('io_v_plain'));
            $this->assertFalse($i->isView('io_t_plain'));
            $this->assertTrue($i->tableExists('io_v_plain'));
            $this->assertTrue($i->tableExists('io_t_plain'));
            $this->assertContains('io_t_plain', $i->getAllTables());
            $this->assertNotContains('io_v_plain', $i->getAllTables());
        } finally {
            $this->cleanup($db);
        }
    }

    #[DataProvider('engines')]
    public function testListObjectsDescribesTablesAndViews(array $cfg): void
    {
        $db = $this->connect($cfg);
        try {
            $objs = [];
            foreach ((new Inspect($db))->listObjects() as $o) { $objs[$o['name']] = $o; }

            foreach (array_keys($objs) as $n) {
                $this->assertStringStartsNotWith('bdus_', $n, 'system tables must be excluded');
                $this->assertStringStartsNotWith('sqlite_', $n);
            }

            $t = $objs['io_t_plain'];
            $this->assertSame('table', $t['kind']);
            $this->assertNull($t['sql']);
            $this->assertNull($t['view_writable']);
            $this->assertTrue($t['has_id']);
            $this->assertTrue($t['has_creator']);
            $this->assertSame(['id', 'creator', 'name'], array_column($t['columns'], 'fld'));
            $this->assertTrue($t['columns'][0]['pk']);
            $this->assertFalse($t['columns'][2]['pk']);

            $v = $objs['io_v_plain'];
            $this->assertSame('view', $v['kind']);
            $this->assertNotEmpty($v['sql']);
            $this->assertStringContainsStringIgnoringCase('io_t_plain', $v['sql']);
            $this->assertContains($v['view_writable'], ['none', 'probe', 'trigger']);
            $this->assertTrue($v['has_id']);

            $n = $objs['io_v_nopk'];
            $this->assertFalse($n['has_id']);
            $this->assertFalse($n['has_creator']);
        } finally {
            $this->cleanup($db);
        }
    }

    #[DataProvider('engines')]
    public function testForeignKeysAreReadFromTheDatabase(array $cfg): void
    {
        $db = $this->connect($cfg);
        try {
            $i  = new Inspect($db);
            $fk = $i->foreignKeys('io_t_child');
            $this->assertCount(1, $fk);
            $this->assertSame('parent_id', $fk[0]['from_col']);
            $this->assertSame('io_t_plain', $fk[0]['to_tb']);
            $this->assertSame('id', $fk[0]['to_col']);
            $this->assertSame('CASCADE', $fk[0]['on_delete']);
            $this->assertContains($fk[0]['on_update'], ['NO ACTION', 'RESTRICT']);
            $this->assertSame([], $i->foreignKeys('io_t_plain'));
            $this->assertSame([], $i->foreignKeys('io_v_plain'), 'views have no FKs');
        } finally {
            $this->cleanup($db);
        }
    }

    /** SQLite-only: a view is writable only through INSTEAD OF triggers. */
    public function testSqliteViewWritableOnlyWithInsteadOfTrigger(): void
    {
        $db = $this->connect(['db_engine' => 'sqlite', 'db_path' => ':memory:']);
        $find = function () use ($db) {
            foreach ((new Inspect($db))->listObjects() as $o) {
                if ($o['name'] === 'io_v_plain') return $o['view_writable'];
            }
        };
        $this->assertSame('none', $find());
        $db->exec('CREATE TRIGGER io_v_plain_ins INSTEAD OF INSERT ON io_v_plain
                   BEGIN INSERT INTO io_t_plain (id, creator, name) VALUES (NEW.id, NEW.creator, NEW.name); END');
        $this->assertSame('trigger', $find());
    }

    /** SQLite-only: virtual tables and their shadow tables are not listed. */
    public function testSqliteVirtualAndShadowTablesAreExcluded(): void
    {
        $db = $this->connect(['db_engine' => 'sqlite', 'db_path' => ':memory:']);
        try {
            $db->exec('CREATE VIRTUAL TABLE io_fts USING fts5(body)');
        } catch (\Throwable $e) {
            $this->markTestSkipped('FTS5 not available in this SQLite build');
        }
        $names = array_column((new Inspect($db))->listObjects(), 'name');
        $this->assertNotContains('io_fts', $names);
        $this->assertNotContains('io_fts_data', $names);
        $this->assertContains('io_t_plain', $names);
    }
}
