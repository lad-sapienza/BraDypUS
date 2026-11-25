<?php

namespace Tests\Integration;

use DB\DB;
use DB\System\Manage;
use Monolog\Handler\NullHandler;
use Monolog\Logger;
use PHPUnit\Framework\TestCase;

/**
 * Manage::realignSequences() on a real PostgreSQL: rows inserted with explicit ids leave the
 * sequence behind, the next plain INSERT collides, and realigning fixes it.
 *
 * Runs only when the PostgreSQL test container is reachable (`test.sh --unit --db=pgsql`,
 * docker-compose.test.pg.yml) and skips itself otherwise.
 */
class RealignSequencesPgsqlTest extends TestCase
{
    private DB $db;

    protected function setUp(): void
    {
        $log = new Logger('t');
        $log->pushHandler(new NullHandler());
        try {
            $this->db = new DB('realign_seq_pgsql', [
                'db_engine' => 'pgsql', 'db_host' => 'postgres', 'db_port' => 5432,
                'db_name' => 'bdus_test', 'db_username' => 'bdus', 'db_password' => 'bdus_test_pw',
            ]);
            $this->db->setLog($log);
            $this->db->query('SELECT 1');
        } catch (\Throwable) {
            $this->markTestSkipped('pgsql not reachable — run via test.sh --unit --db=pgsql');
        }
        $this->cleanup();
        $this->db->exec('CREATE TABLE rs_seq_a (id SERIAL PRIMARY KEY, v TEXT)');
        $this->db->exec('CREATE TABLE rs_seq_b (id SERIAL PRIMARY KEY, v TEXT)');
        $this->db->exec('CREATE TABLE rs_seq_empty (id SERIAL PRIMARY KEY, v TEXT)');
        $this->db->exec('CREATE TABLE rs_seq_plain (id INTEGER PRIMARY KEY, v TEXT)');
    }

    protected function tearDown(): void
    {
        if (isset($this->db)) {
            $this->cleanup();
        }
    }

    private function cleanup(): void
    {
        foreach (['rs_seq_a', 'rs_seq_b', 'rs_seq_empty', 'rs_seq_plain'] as $t) {
            $this->db->exec("DROP TABLE IF EXISTS $t");
        }
    }

    public function testRealignsOnlyTheSequencesThatAreBehind(): void
    {
        // a: explicit ids, sequence untouched → behind
        $this->db->exec("INSERT INTO rs_seq_a (id, v) VALUES (1, 'x'), (2, 'y'), (7, 'z')");
        // b: ordinary inserts → already in step
        $this->db->exec("INSERT INTO rs_seq_b (v) VALUES ('x'), ('y')");
        $this->db->exec("INSERT INTO rs_seq_plain (id, v) VALUES (9, 'no sequence')");

        // The failure being repaired
        try {
            $this->db->exec("INSERT INTO rs_seq_a (v) VALUES ('boom')");
            $this->fail('expected the insert to collide');
        } catch (\DB\DBException $e) {
            $this->assertStringContainsString('duplicate key', $e->getMessage());
        }

        $moved  = (new Manage($this->db))->realignSequences();
        $tables = array_column($moved, 'table');

        $this->assertContains('rs_seq_a', $tables);
        $this->assertNotContains('rs_seq_b', $tables);
        $this->assertNotContains('rs_seq_empty', $tables);
        $this->assertNotContains('rs_seq_plain', $tables);

        $id = $this->db->query("INSERT INTO rs_seq_a (v) VALUES ('after')", [], 'id');
        $this->assertSame(8, (int) $id);

        // Idempotent
        $this->assertNotContains('rs_seq_a', array_column((new Manage($this->db))->realignSequences(), 'table'));
    }
}
