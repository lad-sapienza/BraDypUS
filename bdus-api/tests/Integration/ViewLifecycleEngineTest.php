<?php

namespace Tests\Integration;

use Adbar\Dot;
use Config\Activate;
use Config\Config;
use Config\LoadFromDB;
use Config\ToDB;
use DB\DB;
use DB\Inspect;
use DB\System\Manage;
use Monolog\Handler\NullHandler;
use Monolog\Logger;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

/**
 * #53 on every engine: activation of tables (with a foreign key) and views, the
 * writability probe, writing through an updatable view, and the table life cycle
 * (extra.active stored as JSON text).
 *
 * SQLite always runs. PostgreSQL and MariaDB run when their test containers are
 * reachable (`test.sh --unit --db=pgsql|mysql`). Isolation: PostgreSQL gets its
 * own schema; MariaDB has no schema to spare, so the test skips itself if the
 * database already holds BraDypUS configuration tables (it never touches them).
 */
class ViewLifecycleEngineTest extends TestCase
{
    private const CFG_TABLES = ['bdus_cfg_tables', 'bdus_cfg_fields', 'bdus_cfg_relations', 'bdus_cfg_templates', 'bdus_cfg_indexes'];

    private ?DB $db = null;
    private string $engine = '';
    private bool $createdCfgTables = false;

    public static function engines(): array
    {
        return InspectObjectsEngineTest::engines();
    }

    private function connect(array $cfg): void
    {
        $log = new Logger('t');
        $log->pushHandler(new NullHandler());
        try {
            $db = new DB('vl_' . $cfg['db_engine'], $cfg);
            $db->setLog($log);
            $db->query('SELECT 1');
        } catch (\Throwable $e) {
            $this->markTestSkipped("{$cfg['db_engine']} not reachable — run via test.sh --unit --db=...");
        }
        $this->db     = $db;
        $this->engine = $cfg['db_engine'];

        if ($this->engine === 'pgsql') {
            $db->exec('DROP SCHEMA IF EXISTS vl_test CASCADE');
            $db->exec('CREATE SCHEMA vl_test');
            $db->exec('SET search_path TO vl_test');
        }
        if ($this->engine === 'mysql' && (new Inspect($db))->tableExists('bdus_cfg_tables')) {
            $this->db = null;
            $this->markTestSkipped('the database already holds BraDypUS configuration: not touched');
        }

        $manage = new Manage($db);
        foreach (self::CFG_TABLES as $t) {
            $manage->createTable($t);
        }
        $this->createdCfgTables = true;

        $auto = ['sqlite' => 'INTEGER PRIMARY KEY AUTOINCREMENT', 'mysql' => 'INTEGER PRIMARY KEY AUTO_INCREMENT', 'pgsql' => 'SERIAL PRIMARY KEY'][$this->engine];
        $db->exec("CREATE TABLE vl_sites (id $auto, creator INTEGER, name VARCHAR(40), dated DATE)");
        $db->exec("CREATE TABLE vl_finds (id $auto, creator INTEGER, label VARCHAR(40), site_id INTEGER,
                   CONSTRAINT vl_fk_site FOREIGN KEY (site_id) REFERENCES vl_sites(id) ON DELETE CASCADE)");
        $db->exec('CREATE TABLE vl_noid (code VARCHAR(10), note VARCHAR(20))');
        // not updatable on any engine (DISTINCT) → read-only
        $db->exec('CREATE VIEW vl_v_ro AS SELECT DISTINCT id, name FROM vl_sites');
        // simple view on one table → updatable on PostgreSQL / MariaDB
        $db->exec('CREATE VIEW vl_v_rw AS SELECT id, creator, name FROM vl_sites');
        if ($this->engine === 'sqlite') {
            $db->exec('CREATE TRIGGER vl_v_rw_i INSTEAD OF INSERT ON vl_v_rw BEGIN INSERT INTO vl_sites (id,creator,name) VALUES (NEW.id,NEW.creator,NEW.name); END');
            $db->exec('CREATE TRIGGER vl_v_rw_u INSTEAD OF UPDATE ON vl_v_rw BEGIN UPDATE vl_sites SET name = NEW.name WHERE id = OLD.id; END');
            $db->exec('CREATE TRIGGER vl_v_rw_d INSTEAD OF DELETE ON vl_v_rw BEGIN DELETE FROM vl_sites WHERE id = OLD.id; END');
        }
        $db->exec('CREATE VIEW vl_v_noid AS SELECT name FROM vl_sites');

        ToDB::upsertTable($db, ['name' => 'existing', 'label' => 'Existing']);
    }

    protected function tearDown(): void
    {
        if ($this->db === null) {
            return;
        }
        $db = $this->db;
        foreach (['vl_v_ro', 'vl_v_rw', 'vl_v_noid'] as $v) {
            $db->exec("DROP VIEW IF EXISTS $v");
        }
        foreach (['vl_finds', 'vl_sites', 'vl_noid'] as $t) {
            $db->exec("DROP TABLE IF EXISTS $t");
        }
        if ($this->engine === 'pgsql') {
            $db->exec('DROP SCHEMA IF EXISTS vl_test CASCADE');
        } elseif ($this->createdCfgTables) {
            foreach (self::CFG_TABLES as $t) {
                $db->exec("DROP TABLE IF EXISTS $t");
            }
        }
    }

    private function cfg(): Config
    {
        return new Config(new Dot(), __DIR__ . '/../fixtures/cfg/', $this->db);
    }

    #[DataProvider('engines')]
    public function testActivationOfTablesAndViews(array $cfgDb): void
    {
        $this->connect($cfgDb);

        // objects that cannot be activated say why
        $byName = array_column((new Inspect($this->db))->listObjects(), null, 'name');
        $cfg    = $this->cfg();
        $this->assertSame('missing_id_column', Activate::reason($byName['vl_noid'], ['existing']));
        $this->assertSame('missing_id_column', Activate::reason($byName['vl_v_noid'], ['existing']));

        // tables: the foreign key becomes a relation only once the target is configured
        $r = Activate::activate($this->db, $cfg, 'vl_finds');
        $this->assertSame('success', $r['status'], json_encode($r));
        $this->assertSame(0, $r['relations_created']);
        $this->assertSame('target_not_configured', $r['relations_skipped'][0]['reason']);
        ToDB::setActive($this->db, 'vl_finds', false);   // go back, as a user would: remove and redo
        ToDB::deleteTable($this->db, 'vl_finds');

        $this->assertSame('success', Activate::activate($this->db, $this->cfg(), 'vl_sites')['status']);
        $r = Activate::activate($this->db, $this->cfg(), 'vl_finds');
        $this->assertSame(1, $r['relations_created'], json_encode($r));
        $rel = $this->db->query("SELECT * FROM bdus_cfg_relations WHERE from_tb = 'vl_finds'", [], 'read');
        $this->assertSame('vl_sites', $rel[0]['to_tb']);
        $this->assertSame('CASCADE', $rel[0]['on_delete']);

        // field types come from the SQL types
        $cfg = $this->cfg();
        $this->assertSame('date', $cfg->get('tables.vl_sites.fields.dated.type'));
        $this->assertSame('text', $cfg->get('tables.vl_sites.fields.name.type'));

        // views: a read-only one and a writable one (probe on PG/MariaDB, triggers on SQLite)
        $this->assertSame('success', Activate::activate($this->db, $this->cfg(), 'vl_v_ro')['status']);
        $this->assertSame('success', Activate::activate($this->db, $this->cfg(), 'vl_v_rw')['status']);
        $cfg = $this->cfg();
        $this->assertTrue((bool) $cfg->get('tables.vl_v_ro.is_view'));
        $this->assertSame('none', $cfg->get('tables.vl_v_ro.view_writable'));
        $this->assertSame($this->engine === 'sqlite' ? 'trigger' : 'probe', $cfg->get('tables.vl_v_rw.view_writable'));
        $this->assertTrue((new Inspect($this->db))->isView('vl_v_ro'), 'the view is left alone');
    }

    #[DataProvider('engines')]
    public function testWritingThroughAWritableView(array $cfgDb): void
    {
        $this->connect($cfgDb);
        if ($this->engine === 'sqlite') {
            $this->markTestSkipped('SQLite is covered by ViewWriteTest (Persist reads the id with MAX(id))');
        }
        $db = $this->db;
        $db->exec("INSERT INTO vl_sites (name) VALUES ('a'), ('b')");

        $id = (int) $db->query("INSERT INTO vl_v_rw (creator, name) VALUES (1, 'through the view')", [], 'id');
        $real = (int) $db->query("SELECT MAX(id) AS m FROM vl_sites", [], 'read')[0]['m'];
        $this->assertSame($real, $id, 'the new id through the view is the right one');

        $db->query("UPDATE vl_v_rw SET name = 'renamed' WHERE id = ?", [$id], 'boolean');
        $this->assertSame('renamed', $db->query('SELECT name FROM vl_sites WHERE id = ?', [$id], 'read')[0]['name']);

        $db->query('DELETE FROM vl_v_rw WHERE id = ?', [$id], 'boolean');
        $this->assertSame([], $db->query('SELECT id FROM vl_sites WHERE id = ?', [$id], 'read'));

        // the DISTINCT view is not updatable and the probe said so
        $this->expectException(\Throwable::class);
        $db->query("UPDATE vl_v_ro SET name = 'x' WHERE id = 1", [], 'boolean');
    }

    #[DataProvider('engines')]
    public function testDeactivationStoredAsJsonRoundTrips(array $cfgDb): void
    {
        $this->connect($cfgDb);
        $this->assertSame('success', Activate::activate($this->db, $this->cfg(), 'vl_sites')['status']);

        $this->assertTrue(ToDB::setActive($this->db, 'vl_sites', false));
        $this->assertNotContains('vl_sites', $this->cfg()->get('tables.*.name'));
        $this->assertArrayHasKey('vl_sites', LoadFromDB::inactiveTables($this->db));

        $r = Activate::activate($this->db, $this->cfg(), 'vl_sites');       // reactivation
        $this->assertSame('table_reactivated', $r['code']);
        $this->assertContains('vl_sites', $this->cfg()->get('tables.*.name'));
        $this->assertSame([], LoadFromDB::inactiveTables($this->db));

        $this->assertFalse(ToDB::setActive($this->db, 'no_such_table', false));
    }
}
