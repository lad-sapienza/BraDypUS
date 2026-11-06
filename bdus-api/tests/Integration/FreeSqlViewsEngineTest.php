<?php

namespace Tests\Integration;

use Auth\CurrentUser;
use Bdus\Controllers\FreeSql;
use DB\DB;
use DB\Inspect;
use Monolog\Handler\NullHandler;
use Monolog\Logger;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

/**
 * "Run SQL" (super admin) is the way to create the views BraDypUS then registers.
 * Does CREATE VIEW / CREATE TRIGGER / DROP VIEW work through it on every engine?
 * (MySQL/MariaDB commit by themselves after DDL, which a wrapping transaction must survive.)
 *
 * SQLite always runs; PostgreSQL and MariaDB when their test containers are reachable.
 */
class FreeSqlViewsEngineTest extends TestCase
{
    private ?DB $db = null;
    private string $engine = '';

    public static function engines(): array
    {
        return InspectObjectsEngineTest::engines();
    }

    private function connect(array $cfg): void
    {
        $log = new Logger('t');
        $log->pushHandler(new NullHandler());
        try {
            $db = new DB('fs_' . $cfg['db_engine'], $cfg);
            $db->setLog($log);
            $db->query('SELECT 1');
        } catch (\Throwable $e) {
            $this->markTestSkipped("{$cfg['db_engine']} not reachable — run via test.sh --unit --db=...");
        }
        $this->db     = $db;
        $this->engine = $cfg['db_engine'];
        CurrentUser::set(['id' => 1, 'name' => 'A', 'email' => 'a@example.com', 'privilege' => 1, 'app' => 't']);

        $db->exec('DROP VIEW IF EXISTS fs_v');
        $db->exec('DROP TABLE IF EXISTS fs_base');
        $auto = ['sqlite' => 'INTEGER PRIMARY KEY AUTOINCREMENT', 'mysql' => 'INTEGER PRIMARY KEY AUTO_INCREMENT', 'pgsql' => 'SERIAL PRIMARY KEY'][$this->engine];
        $db->exec("CREATE TABLE fs_base (id $auto, creator INTEGER, name VARCHAR(30))");
    }

    protected function tearDown(): void
    {
        if ($this->db !== null) {
            $this->db->exec('DROP VIEW IF EXISTS fs_v');
            $this->db->exec('DROP TABLE IF EXISTS fs_base');
        }
    }

    private function execSql(string $sql): array
    {
        $ctrl = new FreeSql([], ['sql' => $sql], ['sql' => $sql]);
        $ctrl->setDB($this->db);
        $log = new Logger('t');
        $log->pushHandler(new NullHandler());
        $ctrl->setLog($log);
        ob_start();
        try {
            $ctrl->runSql();
            $out = ob_get_clean();
        } catch (\Throwable $e) {
            ob_end_clean();
            throw $e;
        }
        return json_decode($out, true, 512, JSON_THROW_ON_ERROR);
    }

    #[DataProvider('engines')]
    public function testCreateAndDropAViewThroughRunSql(array $cfg): void
    {
        $this->connect($cfg);
        $inspect = new Inspect($this->db);

        $res = $this->execSql('CREATE VIEW fs_v AS SELECT id, creator, name FROM fs_base');
        $this->assertSame('success', $res['status'], $this->engine . ': ' . json_encode($res));
        $this->assertTrue($inspect->isView('fs_v'), 'the view exists');

        $res = $this->execSql('DROP VIEW fs_v');
        $this->assertSame('success', $res['status'], $this->engine . ': ' . json_encode($res));
        $this->assertFalse($inspect->tableExists('fs_v'), 'the view is gone');
    }

    public function testCreateAnInsteadOfTriggerThroughRunSqlOnSqlite(): void
    {
        $this->connect(['db_engine' => 'sqlite', 'db_path' => ':memory:']);
        $this->assertSame('success', $this->execSql('CREATE VIEW fs_v AS SELECT id, creator, name FROM fs_base')['status']);

        // a trigger body holds semicolons: it is still ONE statement
        $res = $this->execSql("CREATE TRIGGER fs_v_ins INSTEAD OF INSERT ON fs_v\nBEGIN\n  INSERT INTO fs_base (id, creator, name) VALUES (NEW.id, NEW.creator, NEW.name);\nEND");
        $this->assertSame('success', $res['status'], json_encode($res));

        foreach ((new Inspect($this->db))->listObjects() as $o) {
            if ($o['name'] === 'fs_v') {
                $this->assertSame('trigger', $o['view_writable']);
            }
        }
    }

    /** A trigger created through Run SQL fires: one statement per call, and no DELIMITER (a client-side keyword). */
    #[DataProvider('engines')]
    public function testCreateATriggerThroughRunSql(array $cfg): void
    {
        $this->connect($cfg);
        $sqls = match ($this->engine) {
            'sqlite' => ["CREATE TRIGGER fs_up BEFORE INSERT ON fs_base BEGIN SELECT 1; END"],
            'mysql'  => ["CREATE TRIGGER fs_up BEFORE INSERT ON fs_base FOR EACH ROW BEGIN SET NEW.name = UPPER(NEW.name); END"],
            'pgsql'  => [
                "CREATE FUNCTION fs_up_fn() RETURNS trigger AS \$\$ BEGIN NEW.name := upper(NEW.name); RETURN NEW; END; \$\$ LANGUAGE plpgsql",
                "CREATE TRIGGER fs_up BEFORE INSERT ON fs_base FOR EACH ROW EXECUTE FUNCTION fs_up_fn()",
            ],
        };
        try {
            foreach ($sqls as $sql) {
                $res = $this->execSql($sql);
                $this->assertSame('success', $res['status'], $this->engine . ': ' . json_encode($res));
            }
            if ($this->engine !== 'sqlite') {
                $this->db->exec("INSERT INTO fs_base (name) VALUES ('abc')");
                $this->assertSame('ABC', $this->db->query('SELECT name FROM fs_base', [], 'read')[0]['name'], 'the trigger fired');
            }
        } finally {
            if ($this->engine === 'pgsql') {
                $this->db->exec('DROP FUNCTION IF EXISTS fs_up_fn() CASCADE');
            }
        }
    }

    /** What happens when two statements arrive in one call, per engine (documented in the guide). */
    #[DataProvider('engines')]
    public function testTwoStatementsInOneCall(array $cfg): void
    {
        $this->connect($cfg);
        $res = $this->execSql("CREATE VIEW fs_v AS SELECT id, name FROM fs_base; CREATE VIEW fs_v2 AS SELECT id FROM fs_base");
        $inspect = new Inspect($this->db);
        fwrite(STDERR, sprintf("\n[two statements] %s: status=%s first=%s second=%s\n", $this->engine, $res['status'],
            $inspect->tableExists('fs_v') ? 'created' : 'no', $inspect->tableExists('fs_v2') ? 'created' : 'no'));
        $this->db->exec('DROP VIEW IF EXISTS fs_v2');
        $this->assertContains($res['status'], ['success', 'error']);
    }
}
