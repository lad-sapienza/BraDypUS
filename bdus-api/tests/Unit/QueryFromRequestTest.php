<?php

namespace Tests\Unit;

use Tests\Support\BdusTestCase;

/**
 * Tests for QueryFromRequest — the SQL-building layer.
 *
 * Covers: type=all, fast, advanced, sqlExpert; edge-cases introduced
 * by recent bug-fixes (empty WHERE, empty adv, missing table prefix).
 */
class QueryFromRequestTest extends BdusTestCase
{
    private const TB = 'items';

    // ── Helpers ───────────────────────────────────────────────────────────

    private function qfr(array $extra = [], bool $preview = true): \QueryFromRequest
    {
        $request = array_merge(['tb' => self::TB, 'type' => 'all'], $extra);
        return new \QueryFromRequest(static::$db, static::$cfg, $request, $preview);
    }

    // ══════════════════════════════════════════════════════════════════════
    // type = all
    // ══════════════════════════════════════════════════════════════════════

    public function testAllReturnsCorrectTotal(): void
    {
        $q = $this->qfr();
        $this->assertSame(5, $q->getTotal());
    }

    public function testAllReturnsResults(): void
    {
        $q = $this->qfr();
        $q->setLimit(0, 10);
        $rows = $q->getResults();
        $this->assertCount(5, $rows);
        $this->assertArrayHasKey('name', $rows[0]);
    }

    public function testAllPreviewFieldsAreSubset(): void
    {
        $q     = $this->qfr(['type' => 'all'], true);
        $q->setLimit(0, 1);
        $row   = $q->getResults()[0];
        // preview = [id, name, status] — no description
        $this->assertArrayHasKey('id', $row);
        $this->assertArrayHasKey('name', $row);
        $this->assertArrayNotHasKey('description', $row);
    }

    // ══════════════════════════════════════════════════════════════════════
    // type = fast
    // ══════════════════════════════════════════════════════════════════════

    public function testFastSearchFindsMatchingRows(): void
    {
        $q = $this->qfr(['type' => 'fast', 'string' => 'Alpha']);
        $this->assertSame(1, $q->getTotal());
    }

    public function testFastSearchIsCaseInsensitiveLike(): void
    {
        // SQLite LIKE is case-insensitive for ASCII by default
        $q = $this->qfr(['type' => 'fast', 'string' => 'item']);
        $this->assertSame(3, $q->getTotal()); // Alpha, Beta, Gamma
    }

    public function testFastSearchEmptyStringReturnsAll(): void
    {
        $q = $this->qfr(['type' => 'fast', 'string' => '']);
        // Empty string: LIKE '%%' matches everything
        $this->assertSame(5, $q->getTotal());
    }

    // ══════════════════════════════════════════════════════════════════════
    // type = sqlExpert
    // ══════════════════════════════════════════════════════════════════════

    public function testSqlExpertFiltersCorrectly(): void
    {
        $q = $this->qfr(['type' => 'sqlExpert', 'querytext' => "status = 'active'", 'join' => '']);
        $this->assertSame(3, $q->getTotal());
    }

    /** @link https://github.com/lad-sapienza/bradypus — fix: empty querytext → WHERE () crash */
    public function testSqlExpertEmptyQuerytextReturnsAll(): void
    {
        $q = $this->qfr(['type' => 'sqlExpert', 'querytext' => '', 'join' => '']);
        // Must not throw; must return all 5 rows
        $this->assertSame(5, $q->getTotal());
    }

    public function testSqlExpertStripsUnsafeKeywords(): void
    {
        // makeSafeStatement should strip DROP/DELETE/etc.
        $q = $this->qfr(['type' => 'sqlExpert', 'querytext' => "1=1; drop table items", 'join' => '']);
        $where = $q->getWhere();
        $this->assertStringNotContainsStringIgnoringCase('drop', $where);
    }

    // ══════════════════════════════════════════════════════════════════════
    // type = advanced
    // ══════════════════════════════════════════════════════════════════════

    public function testAdvancedSearchSingleCondition(): void
    {
        $adv = [
            ['connector' => '', '(' => false, 'fld' => 'items:status', 'operator' => '=', 'value' => 'active', ')' => false],
        ];
        $q = $this->qfr(['type' => 'advanced', 'adv' => $adv]);
        $this->assertSame(3, $q->getTotal());
    }

    public function testAdvancedSearchMultipleConditionsAnd(): void
    {
        $adv = [
            ['connector' => '',    '(' => false, 'fld' => 'items:status', 'operator' => '=',    'value' => 'active', ')' => false],
            ['connector' => 'AND', '(' => false, 'fld' => 'items:name',   'operator' => 'LIKE', 'value' => 'item',   ')' => false],
        ];
        $q = $this->qfr(['type' => 'advanced', 'adv' => $adv]);
        // active + contains "item": Alpha, Beta(inactive), Gamma → only Alpha & Gamma
        $this->assertSame(2, $q->getTotal());
    }

    public function testAdvancedSearchIsEmpty(): void
    {
        $adv = [
            ['connector' => '', '(' => false, 'fld' => 'items:description', 'operator' => 'is_not_empty', 'value' => '', ')' => false],
        ];
        $q = $this->qfr(['type' => 'advanced', 'adv' => $adv]);
        $this->assertSame(5, $q->getTotal());
    }

    /** @link fix: empty adv array → empty WHERE → SQL exception */
    public function testAdvancedSearchEmptyAdvArrayReturnsAll(): void
    {
        $q = $this->qfr(['type' => 'advanced', 'adv' => []]);
        $this->assertSame(5, $q->getTotal());
    }

    /** @link fix: all rows have empty values → skipped → empty WHERE */
    public function testAdvancedSearchAllRowsSkippedReturnsAll(): void
    {
        $adv = [
            ['connector' => '', '(' => false, 'fld' => 'items:name', 'operator' => 'LIKE', 'value' => '', ')' => false],
        ];
        $q = $this->qfr(['type' => 'advanced', 'adv' => $adv]);
        $this->assertSame(5, $q->getTotal());
    }

    // ══════════════════════════════════════════════════════════════════════
    // Pagination & sorting
    // ══════════════════════════════════════════════════════════════════════

    public function testPaginationLimitsRows(): void
    {
        $q = $this->qfr();
        $q->setLimit(0, 2);
        $this->assertCount(2, $q->getResults());
    }

    public function testPaginationOffset(): void
    {
        $q1 = $this->qfr();
        $q1->setLimit(0, 3);
        $first3 = array_column($q1->getResults(), 'id');

        $q2 = $this->qfr();
        $q2->setLimit(3, 3);
        $next2 = array_column($q2->getResults(), 'id');

        $this->assertEmpty(array_intersect($first3, $next2), 'Pages must not overlap');
    }

    public function testSortAscDesc(): void
    {
        $q = $this->qfr();
        $q->setOrder('name', 'asc');
        $q->setLimit(0, 5);
        $asc = array_column($q->getResults(), 'name');

        $q2 = $this->qfr();
        $q2->setOrder('name', 'desc');
        $q2->setLimit(0, 5);
        $desc = array_column($q2->getResults(), 'name');

        $this->assertSame(array_reverse($asc), $desc);
    }

    // ══════════════════════════════════════════════════════════════════════
    // getFields
    // ══════════════════════════════════════════════════════════════════════

    public function testGetFieldsReturnsPreviewColumns(): void
    {
        $q      = $this->qfr(['type' => 'all'], true);
        $fields = $q->getFields();
        $this->assertArrayHasKey('name', $fields);
        $this->assertArrayHasKey('status', $fields);
        $this->assertArrayNotHasKey('description', $fields); // not in preview
    }
}
