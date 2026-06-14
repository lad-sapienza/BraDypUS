<?php

declare(strict_types=1);

namespace Tests\Integration;

use Tests\Support\BdusTestCase;

/**
 * Tests for GET /api/chrono/timeline  (Bdus\Controllers\Chrono::timeline).
 *
 * Covers:
 *   - Returns success with all fuzzy_date tables
 *   - Groups records by table
 *   - Normalises legacy string chrono_certainty to integer
 *   - Year-range filter (from / to)
 *   - Per-table filter (tb[])
 *   - Empty result when no records match
 *   - Tables without fuzzy_date are excluded
 *   - Requires authentication (privilege check)
 */
class ChronoTimelineTest extends BdusTestCase
{
    private const TB = 'items';

    private static int $idEarly;    // chrono: -700 → -500 (Ferro), certain
    private static int $idRoman;    // chrono: -50  →  200 (Romano), probable
    private static int $idLate;     // chrono:  300 →  450 (Tardoantico), certain
    private static int $idPostQuem; // chrono: -100 →  null (post quem), uncertain
    private static int $idUndated;  // no chrono data at all

    public static function setUpBeforeClass(): void
    {
        parent::setUpBeforeClass();

        // Add chrono_* columns to the items fixture table
        $alter   = new \DB\Alter(static::$db);
        $inspect = new \DB\Inspect(static::$db);
        $existing = $inspect->tableColumns(self::TB);

        foreach ([
            'chrono_from INTEGER',
            'chrono_to INTEGER',
            'chrono_label VARCHAR(200)',
            'chrono_certainty VARCHAR(10)',
            'chrono_period VARCHAR(200)',
        ] as $colDef) {
            [$col] = explode(' ', $colDef);
            if (!in_array($col, $existing, true)) {
                $alter->addFld(self::TB, $col, substr($colDef, strlen($col) + 1));
            }
        }

        // Enable fuzzy_date in config for items
        $tbData               = static::$cfg->get('tables.' . self::TB) ?: [];
        $tbData['name']       = self::TB;
        $tbData['fuzzy_date'] = true;
        static::$cfg->setTable($tbData);

        // Seed records
        self::$idEarly    = self::insertRecord(-700, -500, 1, 'Ferro',         'R-EARLY');
        self::$idRoman    = self::insertRecord(-50,   200, 2, 'Romano',        'R-ROMAN');
        self::$idLate     = self::insertRecord(300,   450, 1, 'Tardoantico',   'R-LATE');
        self::$idPostQuem = self::insertRecord(-100, null, 3, null,            'R-POST');
        self::$idUndated  = self::insertRecord(null, null, null, null,         'R-UNDATED');
    }

    public static function tearDownAfterClass(): void
    {
        // Restore fuzzy_date to false so other test classes are not affected
        $tbData               = static::$cfg->get('tables.' . self::TB) ?: [];
        $tbData['name']       = self::TB;
        $tbData['fuzzy_date'] = false;
        static::$cfg->setTable($tbData);
        parent::tearDownAfterClass();
    }

    private static function insertRecord(
        ?int $from, ?int $to, ?int $certainty,
        ?string $period, string $name
    ): int {
        return (int) static::$db->query(
            'INSERT INTO ' . self::TB
            . ' (creator, name, chrono_from, chrono_to, chrono_certainty, chrono_period)'
            . ' VALUES (?, ?, ?, ?, ?, ?)',
            [1, $name, $from, $to, $certainty, $period],
            'id'
        );
    }

    // ── Authentication ────────────────────────────────────────────────────────

    public function testRequiresAuth(): void
    {
        $this->setPrivilege(99); // no privilege
        $ctrl = $this->makeController('Bdus\\Controllers\\Chrono', []);
        $res  = $this->callController($ctrl, 'timeline');
        $this->assertSame('error', $res['status']);
        $this->assertSame('not_enough_privilege', $res['code']);
    }

    // ── Basic response structure ──────────────────────────────────────────────

    public function testReturnsSuccess(): void
    {
        $this->setPrivilege(30); // read
        $ctrl = $this->makeController('Bdus\\Controllers\\Chrono', []);
        $res  = $this->callController($ctrl, 'timeline');
        $this->assertSame('success', $res['status']);
        $this->assertArrayHasKey('tables', $res);
        $this->assertIsArray($res['tables']);
    }

    public function testGroupedByTable(): void
    {
        $this->setPrivilege(30);
        $ctrl   = $this->makeController('Bdus\\Controllers\\Chrono', []);
        $res    = $this->callController($ctrl, 'timeline');
        $tbIds  = array_column($res['tables'], 'tb_id');
        $this->assertContains(self::TB, $tbIds);
    }

    public function testRecordStructure(): void
    {
        $this->setPrivilege(30);
        $ctrl  = $this->makeController('Bdus\\Controllers\\Chrono', []);
        $res   = $this->callController($ctrl, 'timeline');
        $group = array_values(array_filter($res['tables'], fn($g) => $g['tb_id'] === self::TB))[0];
        $rec   = $group['records'][0];
        $this->assertArrayHasKey('id',           $rec);
        $this->assertArrayHasKey('label',        $rec);
        $this->assertArrayHasKey('from',         $rec);
        $this->assertArrayHasKey('to',           $rec);
        $this->assertArrayHasKey('chrono_label', $rec);
        $this->assertArrayHasKey('certainty',    $rec);
        $this->assertArrayHasKey('period',       $rec);
    }

    // ── Undated records are excluded ──────────────────────────────────────────

    public function testUndatedRecordExcluded(): void
    {
        $this->setPrivilege(30);
        $ctrl   = $this->makeController('Bdus\\Controllers\\Chrono', []);
        $res    = $this->callController($ctrl, 'timeline');
        $group  = array_values(array_filter($res['tables'], fn($g) => $g['tb_id'] === self::TB))[0];
        $ids    = array_column($group['records'], 'id');
        $this->assertNotContains(self::$idUndated, $ids);
    }

    // ── Post-quem record is included ─────────────────────────────────────────

    public function testPostQuemRecordIncluded(): void
    {
        $this->setPrivilege(30);
        $ctrl   = $this->makeController('Bdus\\Controllers\\Chrono', []);
        $res    = $this->callController($ctrl, 'timeline');
        $group  = array_values(array_filter($res['tables'], fn($g) => $g['tb_id'] === self::TB))[0];
        $ids    = array_column($group['records'], 'id');
        $this->assertContains(self::$idPostQuem, $ids);
    }

    // ── Year-range filter ─────────────────────────────────────────────────────

    public function testFromFilterExcludesEarlierRecords(): void
    {
        $this->setPrivilege(30);
        // from=0: records whose chrono_to < 0 should be excluded → idEarly (-700/-500)
        $ctrl  = $this->makeController('Bdus\\Controllers\\Chrono', ['from' => '0']);
        $res   = $this->callController($ctrl, 'timeline');
        $group = array_values(array_filter($res['tables'], fn($g) => $g['tb_id'] === self::TB));
        if (empty($group)) {
            // group may be absent if no records match — that also satisfies the assertion
            $this->assertTrue(true);
            return;
        }
        $ids = array_column($group[0]['records'], 'id');
        $this->assertNotContains(self::$idEarly, $ids);
    }

    public function testFromFilterIncludesOverlappingRecord(): void
    {
        $this->setPrivilege(30);
        // from=0: idRoman (-50/200) overlaps → included
        $ctrl  = $this->makeController('Bdus\\Controllers\\Chrono', ['from' => '0']);
        $res   = $this->callController($ctrl, 'timeline');
        $group = array_values(array_filter($res['tables'], fn($g) => $g['tb_id'] === self::TB))[0];
        $ids   = array_column($group['records'], 'id');
        $this->assertContains(self::$idRoman, $ids);
    }

    public function testToFilterExcludesLaterRecords(): void
    {
        $this->setPrivilege(30);
        // to=-600: idRoman (-50/200) and idLate (300/450) should be excluded
        $ctrl  = $this->makeController('Bdus\\Controllers\\Chrono', ['to' => '-600']);
        $res   = $this->callController($ctrl, 'timeline');
        $group = array_values(array_filter($res['tables'], fn($g) => $g['tb_id'] === self::TB));
        if (empty($group)) {
            $this->assertTrue(true);
            return;
        }
        $ids = array_column($group[0]['records'], 'id');
        $this->assertNotContains(self::$idRoman, $ids);
        $this->assertNotContains(self::$idLate,  $ids);
    }

    public function testNoMatchReturnsEmptyTables(): void
    {
        $this->setPrivilege(30);
        // to=-800 is before all chrono_from values in this test (-700 is the earliest),
        // so no record passes the condition chrono_from <= -800.
        $ctrl = $this->makeController('Bdus\\Controllers\\Chrono', ['to' => '-800']);
        $res  = $this->callController($ctrl, 'timeline');
        $this->assertSame('success', $res['status']);
        $this->assertEmpty($res['tables']);
    }

    // ── Per-table filter ─────────────────────────────────────────────────────

    public function testTableFilterRestrictsToRequestedTable(): void
    {
        $this->setPrivilege(30);
        $ctrl = $this->makeController('Bdus\\Controllers\\Chrono', ['tb' => self::TB]);
        $res  = $this->callController($ctrl, 'timeline');
        $this->assertSame('success', $res['status']);
        $this->assertCount(1, $res['tables']);
        $this->assertSame(self::TB, $res['tables'][0]['tb_id']);
    }

    // ── Certainty normalisation ───────────────────────────────────────────────

    public function testCertaintyIsInteger(): void
    {
        $this->setPrivilege(30);
        $ctrl  = $this->makeController('Bdus\\Controllers\\Chrono', []);
        $res   = $this->callController($ctrl, 'timeline');
        $group = array_values(array_filter($res['tables'], fn($g) => $g['tb_id'] === self::TB))[0];
        foreach ($group['records'] as $rec) {
            $this->assertIsInt($rec['certainty']);
            $this->assertContains($rec['certainty'], [1, 2, 3]);
        }
    }

    // ── Tables without fuzzy_date are excluded ───────────────────────────────

    public function testNonFuzzyTableAbsent(): void
    {
        $this->setPrivilege(30);
        $ctrl  = $this->makeController('Bdus\\Controllers\\Chrono', []);
        $res   = $this->callController($ctrl, 'timeline');
        $tbIds = array_column($res['tables'], 'tb_id');
        // 'reviews' and 'categories' fixtures have no fuzzy_date
        $this->assertNotContains('reviews',    $tbIds);
        $this->assertNotContains('categories', $tbIds);
    }
}
