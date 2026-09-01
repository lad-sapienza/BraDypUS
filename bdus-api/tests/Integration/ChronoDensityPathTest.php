<?php

declare(strict_types=1);

namespace Tests\Integration;

use Tests\Support\BdusTestCase;

/**
 * Tests for the configurable `chrono_density_path` (multi-hop chronological
 * distribution):
 *   - Bdus\Controllers\Chrono::related() following a configured chain
 *   - Bdus\Controllers\Config::save_tb_data() validation of the chain
 *
 * Chain used: items (root) -> reviews (hop 1) -> review_notes (hop 2, leaf,
 * fuzzy_date active). review_notes is a table introduced only by this test
 * class, not part of BdusTestCase's shared fixtures.
 */
class ChronoDensityPathTest extends BdusTestCase
{
    private const ROOT_TB = 'items';
    private const MID_TB  = 'reviews';
    private const LEAF_TB = 'review_notes';

    /** @var array<string,string> path => original content, for fixture cleanup */
    private static array $fixtureSnapshot = [];
    private static string $fixtureDir;

    private static int $reviewId1;
    private static int $reviewId2;
    private static int $noteId1;
    private static int $noteId2;

    /**
     * review_notes is a brand-new table name (not just new columns on an
     * existing fixture table) — the file-based test Config persists every
     * setTable() straight to tests/fixtures/cfg/*.json (see
     * project_test_conventions.md pitfall #8 / RadiocarbonCtrlTest). Snapshot
     * and restore so this class's fixtures don't leak into other test classes.
     */
    public static function setUpBeforeClass(): void
    {
        parent::setUpBeforeClass();

        self::$fixtureDir      = __DIR__ . '/../fixtures/cfg';
        self::$fixtureSnapshot = [];
        foreach (glob(self::$fixtureDir . '/*.json') as $file) {
            self::$fixtureSnapshot[$file] = file_get_contents($file);
        }

        // review_notes: leaf table, one hop past 'reviews'.
        static::$db->execInTransaction('
            CREATE TABLE review_notes (
                id               INTEGER PRIMARY KEY AUTOINCREMENT,
                review_ref       INTEGER,
                note             TEXT,
                chrono_from      INTEGER,
                chrono_to        INTEGER,
                chrono_label     VARCHAR(200),
                chrono_certainty INTEGER,
                chrono_period    VARCHAR(200)
            )
        ');

        // FK relations: reviews -> items, review_notes -> reviews
        static::$db->query(
            'INSERT INTO bdus_cfg_relations (from_tb, from_col, to_tb, to_col, on_delete)
             VALUES (?, ?, ?, ?, ?)',
            [self::MID_TB, 'item_ref', self::ROOT_TB, 'id', 'RESTRICT'],
            'boolean'
        );
        static::$db->query(
            'INSERT INTO bdus_cfg_relations (from_tb, from_col, to_tb, to_col, on_delete)
             VALUES (?, ?, ?, ?, ?)',
            [self::LEAF_TB, 'review_ref', self::MID_TB, 'id', 'RESTRICT'],
            'boolean'
        );

        // Enable fuzzy_date on the leaf table only — 'reviews' (mid) deliberately
        // has none, so the automatic-fallback test can tell the two apart.
        $tbData               = static::$cfg->get('tables.' . self::LEAF_TB) ?: [];
        $tbData['name']       = self::LEAF_TB;
        $tbData['fuzzy_date'] = true;
        static::$cfg->setTable($tbData);

        // Two reviews on item 1 already exist in the shared seed (alice, bob).
        self::$reviewId1 = (int) static::$db->query(
            "SELECT id FROM reviews WHERE reviewer = 'alice' AND item_ref = 1",
            [],
            'read'
        )[0]['id'];
        self::$reviewId2 = (int) static::$db->query(
            "SELECT id FROM reviews WHERE reviewer = 'bob' AND item_ref = 1",
            [],
            'read'
        )[0]['id'];

        // Notes with chrono data on both reviews of item 1.
        self::$noteId1 = (int) static::$db->query(
            'INSERT INTO review_notes (review_ref, note, chrono_from, chrono_to, chrono_certainty)
             VALUES (?, ?, ?, ?, ?)',
            [self::$reviewId1, 'note-a', -50, 50, 1],
            'id'
        );
        self::$noteId2 = (int) static::$db->query(
            'INSERT INTO review_notes (review_ref, note, chrono_from, chrono_to, chrono_certainty)
             VALUES (?, ?, ?, ?, ?)',
            [self::$reviewId2, 'note-b', 100, 200, 2],
            'id'
        );
    }

    public static function tearDownAfterClass(): void
    {
        foreach (glob(self::$fixtureDir . '/*.json') as $file) {
            if (!isset(self::$fixtureSnapshot[$file])) {
                unlink($file);
            }
        }
        foreach (self::$fixtureSnapshot as $file => $content) {
            file_put_contents($file, $content);
        }
        parent::tearDownAfterClass();
    }

    private function setPath(array $path): void
    {
        $tbData                         = static::$cfg->get('tables.' . self::ROOT_TB) ?: [];
        $tbData['name']                 = self::ROOT_TB;
        $tbData['chrono_density_path']  = $path;
        static::$cfg->setTable($tbData);
    }

    private function tableSaveBody(array $chronoPath): array
    {
        return [
            'name'                 => self::ROOT_TB,
            'label'                => 'Items',
            'is_plugin'            => '',
            'order'                => 'id',
            'id_field'             => 'id',
            'preview'              => ['id'],
            'chrono_density_path'  => $chronoPath,
        ];
    }

    // ── Chrono::related() with a configured path ──────────────────────────────

    public function testMultiHopReturnsLeafTableRecords(): void
    {
        $this->setPath([self::MID_TB, self::LEAF_TB]);

        $this->setPrivilege(30);
        $ctrl = $this->makeController(
            'Bdus\\Controllers\\Chrono',
            ['tb' => self::ROOT_TB, 'id' => '1']
        );
        $res = $this->callController($ctrl, 'related');

        $this->assertSame('success', $res['status']);
        $this->assertCount(1, $res['sources']);
        $this->assertSame(self::LEAF_TB, $res['sources'][0]['tb_id']);

        $ids = array_column($res['sources'][0]['records'], 'id');
        $this->assertContains(self::$noteId1, $ids);
        $this->assertContains(self::$noteId2, $ids);
    }

    public function testMultiHopFilterIsIdIn(): void
    {
        $this->setPath([self::MID_TB, self::LEAF_TB]);

        $this->setPrivilege(30);
        $ctrl = $this->makeController(
            'Bdus\\Controllers\\Chrono',
            ['tb' => self::ROOT_TB, 'id' => '1']
        );
        $res = $this->callController($ctrl, 'related');

        $filter = $res['sources'][0]['filter'];
        $this->assertArrayHasKey('id', $filter);
        $this->assertArrayHasKey('_in', $filter['id']);

        $ids      = $filter['id']['_in'];
        $expected = [self::$noteId1, self::$noteId2];
        sort($ids);
        sort($expected);
        $this->assertSame($expected, $ids);
    }

    public function testMultiHopEmptyWhenRootHasNoLinkedRecords(): void
    {
        $this->setPath([self::MID_TB, self::LEAF_TB]);

        // item 3 (Gamma item) has no reviews at all.
        $this->setPrivilege(30);
        $ctrl = $this->makeController(
            'Bdus\\Controllers\\Chrono',
            ['tb' => self::ROOT_TB, 'id' => '3']
        );
        $res = $this->callController($ctrl, 'related');
        $this->assertSame('success', $res['status']);
        $this->assertEmpty($res['sources']);
    }

    public function testAutomaticFallbackWhenNoPathConfigured(): void
    {
        $this->setPath([]);

        // Automatic 1-hop looks at 'reviews' directly — it has no fuzzy_date
        // active in this class, so the automatic branch must return empty.
        $this->setPrivilege(30);
        $ctrl = $this->makeController(
            'Bdus\\Controllers\\Chrono',
            ['tb' => self::ROOT_TB, 'id' => '1']
        );
        $res = $this->callController($ctrl, 'related');
        $this->assertSame('success', $res['status']);
        $this->assertEmpty($res['sources']);
    }

    // ── Config::save_tb_data validation ────────────────────────────────────────

    public function testSaveAcceptsValidPath(): void
    {
        $this->setPrivilege(1);
        $ctrl = $this->makeController(
            'Bdus\\Controllers\\Config',
            [],
            $this->tableSaveBody([self::MID_TB, self::LEAF_TB])
        );
        $res = $this->callController($ctrl, 'save_tb_data');
        $this->assertSame('success', $res['status']);
    }

    public function testSaveRejectsUnrelatedHop(): void
    {
        // 'categories' has no FK relation pointing at 'items' in bdus_cfg_relations.
        $this->setPrivilege(1);
        $ctrl = $this->makeController(
            'Bdus\\Controllers\\Config',
            [],
            $this->tableSaveBody(['categories'])
        );
        $res = $this->callController($ctrl, 'save_tb_data');
        $this->assertSame('error',                       $res['status']);
        $this->assertSame('invalid_chrono_density_path', $res['code']);
    }

    public function testSaveRejectsLastHopWithoutFuzzyDate(): void
    {
        // 'reviews' (mid table) never has fuzzy_date active in this class.
        $this->setPrivilege(1);
        $ctrl = $this->makeController(
            'Bdus\\Controllers\\Config',
            [],
            $this->tableSaveBody([self::MID_TB])
        );
        $res = $this->callController($ctrl, 'save_tb_data');
        $this->assertSame('error',                       $res['status']);
        $this->assertSame('invalid_chrono_density_path', $res['code']);
    }

    public function testSaveRejectsRepeatedTable(): void
    {
        $this->setPrivilege(1);
        $ctrl = $this->makeController(
            'Bdus\\Controllers\\Config',
            [],
            $this->tableSaveBody([self::MID_TB, self::MID_TB])
        );
        $res = $this->callController($ctrl, 'save_tb_data');
        $this->assertSame('error',                       $res['status']);
        $this->assertSame('invalid_chrono_density_path', $res['code']);
    }

    public function testSaveRequiresSuperAdmin(): void
    {
        $this->setPrivilege(11);
        $ctrl = $this->makeController(
            'Bdus\\Controllers\\Config',
            [],
            $this->tableSaveBody([self::MID_TB, self::LEAF_TB])
        );
        $res = $this->callController($ctrl, 'save_tb_data');
        $this->setPrivilege(1);
        $this->assertSame('error',                $res['status']);
        $this->assertSame('not_enough_privilege', $res['code']);
    }
}
