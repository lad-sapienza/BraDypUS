<?php

declare(strict_types=1);

namespace Tests\Integration;

use Tests\Support\BdusTestCase;
use SQL\Filter\JsonFilter;
use SQL\Filter\FilterException;

/**
 * Tests for the chrono filter extension in JsonFilter.
 *
 * Covers:
 *   - _chrono_overlap SQL generation and bound values
 *   - chrono_* field allow-list in validateField (active / inactive plugin)
 *   - Error cases (wrong field, wrong value shape)
 *   - Full API round-trip: records with normal / ante-quem / post-quem / undated
 *     dates filtered by _chrono_overlap
 *   - Filtering by chrono_certainty (regular _eq operator on a chrono field)
 */
class ChronoFilterTest extends BdusTestCase
{
    private const TB = 'items';

    /** IDs seeded in setUpBeforeClass for round-trip tests. */
    private static int $idNormal;    // from=-400, to=-300  (4th century BCE)
    private static int $idAnteQuem;  // from=null, to=-300  (ante quem -300)
    private static int $idPostQuem;  // from=-400, to=null  (post quem -400)
    private static int $idUndated;   // from=null, to=null
    private static int $idOutside;   // from=-200, to=-100  (no overlap with test window)
    private static int $idAnteEarly; // from=null, to=-450  (ante quem -450, outside window)
    private static int $idPostLate;  // from=-200, to=null  (post quem -200, outside window)

    public static function setUpBeforeClass(): void
    {
        parent::setUpBeforeClass();

        // Add chrono_* columns directly via DB\Alter (same as activateFuzzyDate does)
        $alter = new \DB\Alter(static::$db);
        $existing = (new \DB\Inspect(static::$db))->tableColumns(self::TB);
        foreach (['chrono_from INTEGER', 'chrono_to INTEGER', 'chrono_label VARCHAR(200)',
                  'chrono_certainty VARCHAR(10)', 'chrono_period VARCHAR(200)'] as $colDef) {
            [$col] = explode(' ', $colDef);
            if (!in_array($col, $existing, true)) {
                $alter->addFld(self::TB, $col, substr($colDef, strlen($col) + 1));
            }
        }

        // Set fuzzy_date=true in config
        $tbData = static::$cfg->get('tables.' . self::TB) ?: [];
        $tbData['name']       = self::TB;
        $tbData['fuzzy_date'] = true;
        static::$cfg->setTable($tbData);

        // Seed records directly via SQL
        self::$idNormal    = static::insertChrono(-400, -300, 'certain');
        self::$idAnteQuem  = static::insertChrono(null, -300, 'probable');
        self::$idPostQuem  = static::insertChrono(-400, null, 'possible');
        self::$idUndated   = static::insertChrono(null, null, null);
        self::$idOutside   = static::insertChrono(-200, -100, 'certain');
        self::$idAnteEarly = static::insertChrono(null, -450, 'certain');
        self::$idPostLate  = static::insertChrono(-200, null, 'certain');
    }

    private static function insertChrono(?int $from, ?int $to, ?string $certainty): int
    {
        return (int) static::$db->query(
            'INSERT INTO ' . self::TB . ' (creator, name, chrono_from, chrono_to, chrono_certainty)
             VALUES (?, ?, ?, ?, ?)',
            [1, "chrono-seed-{$from}-{$to}", $from, $to, $certainty],
            'id'
        );
    }

    // ── SQL generation — _chrono_overlap ─────────────────────────────────────

    public function testChronoOverlapSqlStructure(): void
    {
        $f = new JsonFilter(static::$cfg, self::TB);
        [$sql, $vals] = $f->toSql(['chrono_from' => ['_chrono_overlap' => [-400, -300]]]);

        // Must contain all three branches
        $this->assertStringContainsString('items.chrono_from <= ?', $sql);
        $this->assertStringContainsString('items.chrono_to >= ?',   $sql);
        $this->assertStringContainsString('items.chrono_from IS NULL', $sql);
        $this->assertStringContainsString('items.chrono_to IS NULL',   $sql);
        $this->assertStringContainsString(' OR ', $sql);
    }

    public function testChronoOverlapBoundValues(): void
    {
        $f = new JsonFilter(static::$cfg, self::TB);
        [$sql, $vals] = $f->toSql(['chrono_from' => ['_chrono_overlap' => [-400, -300]]]);

        // Values: high, low, low, high  (4 placeholders)
        $this->assertSame([-300, -400, -400, -300], $vals);
    }

    public function testChronoOverlapWindowReflected(): void
    {
        $f = new JsonFilter(static::$cfg, self::TB);
        [$sql, $vals] = $f->toSql(['chrono_from' => ['_chrono_overlap' => [-200, -100]]]);
        $this->assertSame([-100, -200, -200, -100], $vals);
    }

    // ── Field validation ──────────────────────────────────────────────────────

    public function testChronoFromAllowedWhenPluginActive(): void
    {
        $f = new JsonFilter(static::$cfg, self::TB);
        // Should not throw
        [$sql] = $f->toSql(['chrono_from' => ['_null' => true]]);
        $this->assertStringContainsString('chrono_from IS NULL', $sql);
    }

    public function testChronoLabelAllowedWhenPluginActive(): void
    {
        $f = new JsonFilter(static::$cfg, self::TB);
        [$sql, $vals] = $f->toSql(['chrono_label' => ['_icontains' => 'BCE']]);
        $this->assertStringContainsString('chrono_label LIKE ?', $sql);
        $this->assertSame(['%BCE%'], $vals);
    }

    public function testChronoFieldRejectedWhenPluginInactive(): void
    {
        // tags table has no fuzzy_date plugin
        $f = new JsonFilter(static::$cfg, 'tags');
        $this->expectException(FilterException::class);
        $f->toSql(['chrono_from' => ['_null' => true]]);
    }

    // ── Error cases ───────────────────────────────────────────────────────────

    public function testChronoOverlapOnWrongFieldThrows(): void
    {
        $f = new JsonFilter(static::$cfg, self::TB);
        $this->expectException(FilterException::class);
        $this->expectExceptionMessageMatches('/chrono_from/');
        $f->toSql(['chrono_to' => ['_chrono_overlap' => [-400, -300]]]);
    }

    public function testChronoOverlapSingleValueThrows(): void
    {
        $f = new JsonFilter(static::$cfg, self::TB);
        $this->expectException(FilterException::class);
        $f->toSql(['chrono_from' => ['_chrono_overlap' => -400]]);
    }

    public function testChronoOverlapThreeElementArrayThrows(): void
    {
        $f = new JsonFilter(static::$cfg, self::TB);
        $this->expectException(FilterException::class);
        $f->toSql(['chrono_from' => ['_chrono_overlap' => [-400, -300, -200]]]);
    }

    // ── API round-trip — _chrono_overlap ─────────────────────────────────────

    /**
     * Window: [-350, -320].
     * Expected to match:
     *   - Normal    (-400 to -300): overlaps (range straddles window)         ✓
     *   - AnteQuem  (null to -300): -300 >= -350                              ✓
     *   - PostQuem  (-400 to null): -400 <= -320                              ✓
     * Expected NOT to match:
     *   - Undated   (null / null)                                             ✗
     *   - Outside   (-200 to -100): -200 > -320                              ✗
     *   - AnteEarly (null to -450): -450 < -350                              ✗
     *   - PostLate  (-200 to null): -200 > -320                              ✗
     */
    private function queryOverlap(int $low, int $high): array
    {
        $ctrl = $this->makeController(
            'Bdus\\Controllers\\Record',
            [
                'tb'          => self::TB,
                'search_type' => 'filter',
                'filter'      => json_encode([
                    'chrono_from' => ['_chrono_overlap' => [$low, $high]],
                ]),
            ]
        );
        $res = $this->callController($ctrl, 'getRecords');
        return array_column($res['data'] ?? [], 'id');
    }

    public function testOverlapIncludesNormalRangeRecord(): void
    {
        $ids = $this->queryOverlap(-350, -320);
        $this->assertContains(self::$idNormal, $ids);
    }

    public function testOverlapIncludesAnteQuemRecord(): void
    {
        $ids = $this->queryOverlap(-350, -320);
        $this->assertContains(self::$idAnteQuem, $ids);
    }

    public function testOverlapIncludesPostQuemRecord(): void
    {
        $ids = $this->queryOverlap(-350, -320);
        $this->assertContains(self::$idPostQuem, $ids);
    }

    public function testOverlapExcludesUndatedRecord(): void
    {
        $ids = $this->queryOverlap(-350, -320);
        $this->assertNotContains(self::$idUndated, $ids);
    }

    public function testOverlapExcludesOutsideRecord(): void
    {
        $ids = $this->queryOverlap(-350, -320);
        $this->assertNotContains(self::$idOutside, $ids);
    }

    public function testOverlapExcludesAnteQuemBeforeWindow(): void
    {
        // ante quem -450: to=-450 < low=-350 → no overlap
        $ids = $this->queryOverlap(-350, -320);
        $this->assertNotContains(self::$idAnteEarly, $ids);
    }

    public function testOverlapExcludesPostQuemAfterWindow(): void
    {
        // post quem -200: from=-200 > high=-320 → no overlap
        $ids = $this->queryOverlap(-350, -320);
        $this->assertNotContains(self::$idPostLate, $ids);
    }

    public function testWindowCoversAllNonUndated(): void
    {
        // Very wide window: all non-undated records must match
        $ids = $this->queryOverlap(-10000, 10000);
        $this->assertContains(self::$idNormal,    $ids);
        $this->assertContains(self::$idAnteQuem,  $ids);
        $this->assertContains(self::$idPostQuem,  $ids);
        $this->assertContains(self::$idOutside,   $ids);
        $this->assertContains(self::$idAnteEarly, $ids);
        $this->assertContains(self::$idPostLate,  $ids);
        $this->assertNotContains(self::$idUndated, $ids);
    }

    // ── Regular operator on chrono field ──────────────────────────────────────

    public function testFilterByChronoCertainty(): void
    {
        $ctrl = $this->makeController(
            'Bdus\\Controllers\\Record',
            [
                'tb'          => self::TB,
                'search_type' => 'filter',
                'filter'      => json_encode([
                    'chrono_certainty' => ['_eq' => 'probable'],
                ]),
            ]
        );
        $res = $this->callController($ctrl, 'getRecords');
        $ids = array_column($res['data'] ?? [], 'id');

        $this->assertContains(self::$idAnteQuem, $ids, 'ante quem seeded with probable');
        $this->assertNotContains(self::$idNormal, $ids);
    }
}
