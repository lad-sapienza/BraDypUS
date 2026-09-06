<?php

namespace Tests\Integration;

use PHPUnit\Framework\TestCase;
use DB\DB;
use Config\Config;
use Record\Read;
use Adbar\Dot;
use Monolog\Logger;
use Monolog\Handler\NullHandler;

/**
 * Covers the runtime half of #48 Bug A: Record\Read must not let a stale
 * link / backlink target — a relation pointing at a table that no longer
 * exists, e.g. the pre-v5 `geodata` relation an incomplete v4→v5 upgrade
 * leaves behind — abort the whole record read. It skips the entry instead.
 *
 * The permanent fix removes such rows (M043_DropDanglingCfgRelations) and stops
 * Config\LoadFromDB from ever surfacing them as links; this guard is the last
 * line of defence for config that predates relations or was hand-edited.
 *
 * A/B: without the guard in getLinks()/getBackLinks() these tests fail with
 * "SQLSTATE[HY000]: no such table: geodata / ghost_bridge".
 */
class RecordReadStaleLinkTest extends TestCase
{
    private DB $db;

    protected function setUp(): void
    {
        $log = new Logger('test');
        $log->pushHandler(new NullHandler());

        $this->db = new DB('test_stale_link', ['db_engine' => 'sqlite', 'db_path' => ':memory:']);
        $this->db->setLog($log);
        $this->db->exec('CREATE TABLE places (id INTEGER PRIMARY KEY AUTOINCREMENT, name TEXT)');
        $this->db->exec("INSERT INTO places (name) VALUES ('Rome')");
    }

    /** A Config whose per-key getters can be overridden; everything else delegates. */
    private function stubConfig(array $overrides): Config
    {
        return new class(new Dot(), __DIR__ . '/../fixtures/cfg/', $overrides) extends Config {
            private array $ovr;
            public function __construct(Dot $dot, string $path, array $ovr)
            {
                parent::__construct($dot, $path, null);
                $this->ovr = $ovr;
            }
            public function get(string $key = '*', string $filter_key = null, string $filter_val = null)
            {
                return array_key_exists($key, $this->ovr)
                    ? $this->ovr[$key]
                    : parent::get($key, $filter_key, $filter_val);
            }
        };
    }

    public function testGetLinksSkipsLinkToMissingTableInsteadOfThrowing(): void
    {
        $cfg = $this->stubConfig([
            'tables.places.link' => [
                ['other_tb' => 'geodata', 'fld' => [['my' => 'id', 'other' => 'id_link']]],
            ],
        ]);

        $read = new Read(1, null, 'places', $this->db, $cfg);

        $this->assertSame([], $read->getLinks());
    }

    public function testGetLinksKeepsValidLinkAlongsideAStaleOne(): void
    {
        $this->db->exec('CREATE TABLE finds (id INTEGER PRIMARY KEY AUTOINCREMENT, place_id INTEGER)');
        $this->db->exec('INSERT INTO finds (place_id) VALUES (1), (1)');

        $cfg = $this->stubConfig([
            'tables.places.fields' => [
                'id'   => ['name' => 'id',   'label' => 'ID'],
                'name' => ['name' => 'name', 'label' => 'Name'],
            ],
            'tables.places.link' => [
                ['other_tb' => 'geodata', 'fld' => [['my' => 'id', 'other' => 'id_link']]],
                ['other_tb' => 'finds',   'fld' => [['my' => 'id', 'other' => 'place_id']]],
            ],
            'tables.finds.label' => 'Finds',
        ]);

        $read  = new Read(1, null, 'places', $this->db, $cfg);
        $links = $read->getLinks();

        $this->assertArrayNotHasKey('geodata', $links);
        $this->assertArrayHasKey('finds', $links);
        $this->assertSame(2, $links['finds']['tot']);
    }

    public function testGetBackLinksSkipsMissingBridgeTableInsteadOfThrowing(): void
    {
        $cfg = $this->stubConfig([
            'tables.places.backlinks' => ['sites:ghost_bridge:place_ref'],
        ]);

        $read = new Read(1, null, 'places', $this->db, $cfg);

        $this->assertSame([], $read->getBackLinks());
    }
}
