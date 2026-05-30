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

    private function qfr(array $extra = [], bool $preview = true): \SQL\QueryFromRequest
    {
        $request = array_merge(['tb' => self::TB, 'type' => 'all'], $extra);
        return new \SQL\QueryFromRequest(static::$db, static::$cfg, $request, $preview);
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
        [$where] = $q->getWhereClause();
        $this->assertStringNotContainsStringIgnoringCase('drop', $where);
    }

    // ══════════════════════════════════════════════════════════════════════
    // type = filter — main table
    // ══════════════════════════════════════════════════════════════════════

    public function testFilterEqMainTable(): void
    {
        $q = $this->qfr(['type' => 'filter', 'filter' => ['status' => ['_eq' => 'active']]]);
        $this->assertSame(3, $q->getTotal());
    }

    public function testFilterIcontainsMainTable(): void
    {
        $q = $this->qfr(['type' => 'filter', 'filter' => ['name' => ['_icontains' => 'item']]]);
        $this->assertSame(3, $q->getTotal()); // Alpha item, Beta item, Gamma item
    }

    public function testFilterAndGroup(): void
    {
        $q = $this->qfr(['type' => 'filter', 'filter' => [
            '_and' => [
                ['status' => ['_eq' => 'active']],
                ['name'   => ['_icontains' => 'item']],
            ],
        ]]);
        $this->assertSame(2, $q->getTotal()); // Alpha & Gamma (both active + "item")
    }

    public function testFilterOrGroup(): void
    {
        $q = $this->qfr(['type' => 'filter', 'filter' => [
            '_or' => [
                ['status' => ['_eq' => 'inactive']],
                ['status' => ['_eq' => 'pending']],
            ],
        ]]);
        $this->assertSame(2, $q->getTotal()); // Beta (inactive) + Delta (pending)
    }

    public function testFilterEmptyOperator(): void
    {
        // All 5 items have a non-null non-empty description
        $q = $this->qfr(['type' => 'filter', 'filter' => ['description' => ['_nempty' => true]]]);
        $this->assertSame(5, $q->getTotal());
    }

    public function testFilterEmptyReturnsNone(): void
    {
        // No item has an empty description
        $q = $this->qfr(['type' => 'filter', 'filter' => ['description' => ['_empty' => true]]]);
        $this->assertSame(0, $q->getTotal());
    }

    // ══════════════════════════════════════════════════════════════════════
    // type = filter — cross-table (plugin)
    // ══════════════════════════════════════════════════════════════════════

    public function testFilterPluginEqFindsParentRecord(): void
    {
        // Items fixture: item 1 has tags 'tag-a' and 'tag-b'
        $q = $this->qfr(['type' => 'filter', 'filter' => [
            'tags' => ['label' => ['_eq' => 'tag-a']],
        ]]);
        $this->assertSame(1, $q->getTotal());
    }

    public function testFilterPluginIcontainsFindsParentRecord(): void
    {
        $q = $this->qfr(['type' => 'filter', 'filter' => [
            'tags' => ['label' => ['_icontains' => 'tag']],
        ]]);
        $this->assertSame(1, $q->getTotal()); // only item 1 has tags
    }

    public function testFilterPluginNoMatchReturnsZero(): void
    {
        $q = $this->qfr(['type' => 'filter', 'filter' => [
            'tags' => ['label' => ['_eq' => 'nonexistent']],
        ]]);
        $this->assertSame(0, $q->getTotal());
    }

    public function testFilterInvalidPluginThrows(): void
    {
        $this->expectException(\SQL\Filter\FilterException::class);
        $this->qfr(['type' => 'filter', 'filter' => [
            'nonexistent_plugin' => ['label' => ['_eq' => 'x']],
        ]]);
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
