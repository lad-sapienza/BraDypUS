<?php

namespace Tests\Integration;

use PHPUnit\Framework\TestCase;
use DB\DB;
use Config\Config;
use Record\Read;
use SQL\Filter\JsonFilter;
use Adbar\Dot;
use Monolog\Logger;
use Monolog\Handler\NullHandler;

/**
 * Backlink `filter` must select exactly the records `tot` counts.
 *
 * Plugin-bridged backlinks (places ← manuscripts via m_msplaces.place) emit a
 * short relational filter `{ m_msplaces: { place: { _eq: id } } }`; anything
 * else falls back to a clean `{ id: { _in: [...] } }` (deduplicated, numeric
 * only, capped with `filter_truncated`).
 */
class RecordReadBacklinkFilterTest extends TestCase
{
    private DB $db;

    protected function setUp(): void
    {
        $log = new Logger('test');
        $log->pushHandler(new NullHandler());

        $this->db = new DB('test_backlink_filter', ['db_engine' => 'sqlite', 'db_path' => ':memory:']);
        $this->db->setLog($log);
        $this->db->exec('CREATE TABLE places (id INTEGER PRIMARY KEY AUTOINCREMENT, name TEXT)');
        $this->db->exec('CREATE TABLE manuscripts (id INTEGER PRIMARY KEY AUTOINCREMENT, sigla TEXT)');
        $this->db->exec('CREATE TABLE m_msplaces (
            id INTEGER PRIMARY KEY AUTOINCREMENT, table_link TEXT, id_link INTEGER, place INTEGER)');
        $this->db->exec("INSERT INTO places (name) VALUES ('Rome'), ('Paris')");
        for ($i = 1; $i <= 5; $i++) {
            $this->db->exec("INSERT INTO manuscripts (sigla) VALUES ('M$i')");
        }
        // place 1: ms 1,2,3 (ms 2 twice), plus a NULL id_link row; place 2: ms 4
        $this->db->exec("INSERT INTO m_msplaces (table_link, id_link, place) VALUES
            ('manuscripts', 1, 1), ('manuscripts', 2, 1), ('manuscripts', 2, 1),
            ('manuscripts', 3, 1), ('manuscripts', NULL, 1), ('manuscripts', 4, 2)");
    }

    private function cfg(array $overrides = []): Config
    {
        $overrides += [
            'tables.places.backlinks'       => ['manuscripts:m_msplaces:place'],
            'tables.places.label'           => 'Places',
            'tables.manuscripts.label'      => 'Manuscripts',
            'tables.manuscripts.id_field'   => 'sigla',
            'tables.manuscripts.plugin'     => ['m_msplaces'],
            'tables.manuscripts.fields'     => [['name' => 'sigla']],
            'tables.m_msplaces.fields'      => [['name' => 'place']],
            'tables.m_msplaces.plugin_of'   => 'manuscripts',
        ];
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

    /** Counts the records a filter selects on $tb, via the real JsonFilter. */
    private function totalFor(Config $cfg, string $tb, array $filter): int
    {
        [$where, $vals] = (new JsonFilter($cfg, $tb))->toSql($filter);
        return (int) $this->db->query("SELECT count(*) AS n FROM {$tb} WHERE {$where}", $vals)[0]['n'];
    }

    public function testPluginBacklinkEmitsRelationalFilterMatchingTot(): void
    {
        $cfg = $this->cfg();
        foreach ([1 => 3, 2 => 1] as $placeId => $expected) {
            $bl = (new Read($placeId, null, 'places', $this->db, $cfg))->getBackLinks()['manuscripts'];

            $this->assertSame(['m_msplaces' => ['place' => ['_eq' => $placeId]]], $bl['filter']);
            $this->assertSame($expected, (int) $bl['tot']);
            $this->assertSame((int) $bl['tot'], $this->totalFor($cfg, 'manuscripts', $bl['filter']));
            $this->assertArrayNotHasKey('filter_truncated', $bl);
        }
    }

    public function testFallbackIdListIsCleanedAndDeduplicated(): void
    {
        // Bridge not declared as plugin of the referenced table → id list fallback
        $cfg = $this->cfg(['tables.manuscripts.plugin' => []]);
        $bl  = (new Read(1, null, 'places', $this->db, $cfg))->getBackLinks()['manuscripts'];

        $ids = $bl['filter']['id']['_in'];
        sort($ids);
        $this->assertSame([1, 2, 3], $ids);          // no NULL, no duplicate of 2
        $this->assertSame(3, (int) $bl['tot']);
        $this->assertSame((int) $bl['tot'], $this->totalFor($cfg, 'manuscripts', $bl['filter']));
        $this->assertArrayNotHasKey('filter_truncated', $bl);
    }

    public function testFallbackDropsEmptyAndNonNumericIds(): void
    {
        $this->db->exec("INSERT INTO m_msplaces (table_link, id_link, place) VALUES
            ('manuscripts', '', 1), ('manuscripts', 'abc', 1)");
        $cfg = $this->cfg(['tables.manuscripts.plugin' => []]);
        $bl  = (new Read(1, null, 'places', $this->db, $cfg))->getBackLinks()['manuscripts'];

        $ids = $bl['filter']['id']['_in'];
        sort($ids);
        $this->assertSame([1, 2, 3], $ids);
    }

    public function testFallbackIsTruncatedWhenTooLong(): void
    {
        $this->db->exec('DELETE FROM m_msplaces');
        $this->db->beginTransaction();
        for ($i = 1; $i <= 250; $i++) {
            $this->db->exec("INSERT INTO manuscripts (sigla) VALUES ('X$i')");
            $this->db->exec("INSERT INTO m_msplaces (table_link, id_link, place) VALUES ('manuscripts', " . (5 + $i) . ", 1)");
        }
        $this->db->commit();

        $cfg = $this->cfg(['tables.manuscripts.plugin' => []]);
        $bl  = (new Read(1, null, 'places', $this->db, $cfg))->getBackLinks()['manuscripts'];

        $this->assertSame(250, (int) $bl['tot']);
        $this->assertCount(200, $bl['filter']['id']['_in']);
        $this->assertTrue($bl['filter_truncated']);
    }
}
