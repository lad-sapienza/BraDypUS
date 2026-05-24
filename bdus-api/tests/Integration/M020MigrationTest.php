<?php

namespace Tests\Integration;

use PHPUnit\Framework\TestCase;
use DB\DB;
use DB\System\Manage;
use DB\System\Migrations\M020_DeduplicateRelations;
use Monolog\Logger;
use Monolog\Handler\NullHandler;

/**
 * Tests for M020_DeduplicateRelations.
 *
 * Each test gets a fresh in-memory SQLite DB so that the UNIQUE index
 * created by M020 does not interfere across tests.
 *
 * Covers:
 *  - Bidirectional duplicate removal (A→B + B→A → keep lowest id)
 *  - Same-direction duplicate removal (two A→B rows → keep lowest id)
 *  - Idempotency (second run on already-clean data is a no-op)
 *  - Non-duplicate pairs are preserved unchanged
 *  - Multiple bidirectional pairs all deduplicated in one pass
 */
class M020MigrationTest extends TestCase
{
    private DB     $db;
    private Manage $manage;

    protected function setUp(): void
    {
        $log = new Logger('test');
        $log->pushHandler(new NullHandler());
        $this->db     = new DB('test_m020_' . uniqid(), ['db_engine' => 'sqlite', 'db_path' => ':memory:']);
        $this->db->setLog($log);
        $this->manage = new Manage($this->db);
        $this->manage->createTable('bdus_cfg_relations');
    }

    private function insertRel(string $from, string $to, string $fld = '[]'): void
    {
        $this->db->query(
            'INSERT INTO bdus_cfg_relations (from_tb, to_tb, fld, sort) VALUES (?,?,?,0)',
            [$from, $to, $fld],
            'boolean'
        );
    }

    private function countRels(): int
    {
        $r = $this->db->query('SELECT COUNT(*) AS c FROM bdus_cfg_relations', [], 'read');
        return (int) ($r[0]['c'] ?? 0);
    }

    private function relPairs(): array
    {
        $rows = $this->db->query('SELECT from_tb, to_tb FROM bdus_cfg_relations ORDER BY id', [], 'read') ?: [];
        return array_map(fn($r) => $r['from_tb'] . '→' . $r['to_tb'], $rows);
    }

    // ── Bidirectional duplicate removal ───────────────────────────────────────

    public function testRemovesBidirectionalDuplicate(): void
    {
        $this->insertRel('tombe', 'us');    // id=1 (lower → keep)
        $this->insertRel('us',    'tombe'); // id=2 (higher → drop)

        M020_DeduplicateRelations::run($this->manage);

        $this->assertSame(1, $this->countRels());
        $this->assertSame(['tombe→us'], $this->relPairs());
    }

    public function testKeepsNonDuplicatePairs(): void
    {
        $this->insertRel('tombe',  'us');
        $this->insertRel('tombe',  'periodi');
        $this->insertRel('us',     'periodi');

        M020_DeduplicateRelations::run($this->manage);

        // No bidirectional duplicates → all 3 rows preserved.
        $this->assertSame(3, $this->countRels());
    }

    // ── Same-direction duplicate removal ──────────────────────────────────────

    public function testRemovesSameDirectionDuplicate(): void
    {
        // The shared Manage::createTable() now includes the UNIQUE(from_tb,to_tb)
        // constraint, so we cannot insert duplicates through the normal path.
        // Create a separate bare table (no UNIQUE index) to simulate the
        // pre-M020 state that the migration is meant to repair.
        $bare = new DB('test_m020_same_' . uniqid(), ['db_engine' => 'sqlite', 'db_path' => ':memory:']);
        $bare->setLog((new \Monolog\Logger('t'))->pushHandler(new \Monolog\Handler\NullHandler()) ?: new \Monolog\Logger('t'));
        $bare->query(
            'CREATE TABLE bdus_cfg_relations
             (id INTEGER PRIMARY KEY AUTOINCREMENT, from_tb TEXT NOT NULL,
              to_tb TEXT NOT NULL, fld TEXT, sort INTEGER)',
            [], 'boolean'
        );
        $bare->query('INSERT INTO bdus_cfg_relations (from_tb,to_tb,fld,sort) VALUES (?,?,?,?)', ['tombe','us','[]',0], 'boolean');
        $bare->query('INSERT INTO bdus_cfg_relations (from_tb,to_tb,fld,sort) VALUES (?,?,?,?)', ['tombe','us','[]',1], 'boolean');

        $manage = new Manage($bare);
        M020_DeduplicateRelations::run($manage);

        $r    = $bare->query('SELECT COUNT(*) AS c FROM bdus_cfg_relations', [], 'read');
        $rows = $bare->query('SELECT from_tb, to_tb FROM bdus_cfg_relations ORDER BY id', [], 'read') ?: [];
        $pairs = array_map(fn($x) => $x['from_tb'] . '→' . $x['to_tb'], $rows);

        $this->assertSame(1, (int) ($r[0]['c'] ?? 0));
        $this->assertSame(['tombe→us'], $pairs);
    }

    // ── Idempotency ───────────────────────────────────────────────────────────

    public function testIsIdempotent(): void
    {
        $this->insertRel('tombe',  'us');
        $this->insertRel('tombe',  'periodi');

        M020_DeduplicateRelations::run($this->manage); // first run
        $after1 = $this->countRels();

        M020_DeduplicateRelations::run($this->manage); // second run (no-op)
        $after2 = $this->countRels();

        $this->assertSame($after1, $after2, 'Second run must not change row count');
    }

    // ── Multiple bidirectional pairs ──────────────────────────────────────────

    public function testRemovesMultipleBidirectionalPairs(): void
    {
        $this->insertRel('a', 'b');
        $this->insertRel('b', 'a'); // duplicate of a→b
        $this->insertRel('c', 'd');
        $this->insertRel('d', 'c'); // duplicate of c→d
        $this->insertRel('e', 'f'); // no reverse → keep

        M020_DeduplicateRelations::run($this->manage);

        $this->assertSame(3, $this->countRels());
        $pairs = $this->relPairs();
        $this->assertContains('a→b', $pairs);
        $this->assertContains('c→d', $pairs);
        $this->assertContains('e→f', $pairs);
    }
}
