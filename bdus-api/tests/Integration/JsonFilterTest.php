<?php

namespace Tests\Integration;

use Tests\Support\BdusTestCase;
use SQL\Filter\JsonFilter;
use SQL\Filter\FilterException;

/**
 * Tests for SQL\Filter\JsonFilter.
 *
 * Validates that every supported operator, logical group, and edge case
 * produces the correct SQL fragment + bound values, and that security
 * checks (field allow-list, operator allow-list) throw FilterException.
 *
 * Also exercises the full round-trip via the API: GET /api/records/{tb}
 * with ?filter[field][_op]=value bracket-notation params.
 */
class JsonFilterTest extends BdusTestCase
{
    private JsonFilter $f;

    protected function setUp(): void
    {
        $this->f = new JsonFilter(static::$cfg, 'items');
    }

    // ── Empty filter ──────────────────────────────────────────────────────────

    public function testEmptyFilterReturnsAll(): void
    {
        [$sql, $vals] = $this->f->toSql([]);
        $this->assertSame('1=1', $sql);
        $this->assertEmpty($vals);
    }

    // ── Scalar operators ──────────────────────────────────────────────────────

    public function testEq(): void
    {
        [$sql, $vals] = $this->f->toSql(['name' => ['_eq' => 'Alpha']]);
        $this->assertStringContainsString('items.name = ?', $sql);
        $this->assertSame(['Alpha'], $vals);
    }

    public function testNeq(): void
    {
        [$sql, $vals] = $this->f->toSql(['status' => ['_neq' => 'inactive']]);
        $this->assertStringContainsString('items.status != ?', $sql);
        $this->assertSame(['inactive'], $vals);
    }

    public function testLt(): void
    {
        [$sql, $vals] = $this->f->toSql(['score' => ['_lt' => '5']]);
        $this->assertStringContainsString('items.score < ?', $sql);
        $this->assertSame(['5'], $vals);
    }

    public function testLte(): void
    {
        [$sql, $vals] = $this->f->toSql(['score' => ['_lte' => '10']]);
        $this->assertStringContainsString('items.score <= ?', $sql);
    }

    public function testGt(): void
    {
        [$sql, $vals] = $this->f->toSql(['score' => ['_gt' => '3']]);
        $this->assertStringContainsString('items.score > ?', $sql);
    }

    public function testGte(): void
    {
        [$sql, $vals] = $this->f->toSql(['score' => ['_gte' => '7']]);
        $this->assertStringContainsString('items.score >= ?', $sql);
    }

    // ── LIKE operators ────────────────────────────────────────────────────────

    public function testContains(): void
    {
        [$sql, $vals] = $this->f->toSql(['name' => ['_contains' => 'Alpha']]);
        $this->assertStringContainsString('items.name LIKE ?', $sql);
        $this->assertSame(['%Alpha%'], $vals);
    }

    public function testIcontains(): void
    {
        [$sql, $vals] = $this->f->toSql(['name' => ['_icontains' => 'alpha']]);
        $this->assertStringContainsString('items.name LIKE ?', $sql);
        $this->assertSame(['%alpha%'], $vals);
    }

    public function testNcontains(): void
    {
        [$sql, $vals] = $this->f->toSql(['name' => ['_ncontains' => 'excluded']]);
        $this->assertStringContainsString('items.name NOT LIKE ?', $sql);
        $this->assertSame(['%excluded%'], $vals);
    }

    public function testStartsWith(): void
    {
        [$sql, $vals] = $this->f->toSql(['name' => ['_starts_with' => 'Al']]);
        $this->assertStringContainsString('items.name LIKE ?', $sql);
        $this->assertSame(['Al%'], $vals);
    }

    public function testEndsWith(): void
    {
        [$sql, $vals] = $this->f->toSql(['name' => ['_ends_with' => 'pha']]);
        $this->assertStringContainsString('items.name LIKE ?', $sql);
        $this->assertSame(['%pha'], $vals);
    }

    // ── IN / NOT IN ───────────────────────────────────────────────────────────

    public function testIn(): void
    {
        [$sql, $vals] = $this->f->toSql(['status' => ['_in' => ['active', 'pending']]]);
        $this->assertMatchesRegularExpression('/items\.status\s+IN\s*\(\s*\?,\s*\?\s*\)/', $sql);
        $this->assertSame(['active', 'pending'], $vals);
    }

    public function testNin(): void
    {
        [$sql, $vals] = $this->f->toSql(['status' => ['_nin' => ['inactive']]]);
        $this->assertStringContainsString('NOT IN', $sql);
        $this->assertSame(['inactive'], $vals);
    }

    public function testInEmptyProducesFalse(): void
    {
        [$sql, $vals] = $this->f->toSql(['status' => ['_in' => []]]);
        $this->assertStringContainsString('1=0', $sql);
        $this->assertEmpty($vals);
    }

    public function testNinEmptyProducesTrue(): void
    {
        [$sql, $vals] = $this->f->toSql(['status' => ['_nin' => []]]);
        $this->assertStringContainsString('1=1', $sql);
        $this->assertEmpty($vals);
    }

    // ── NULL checks ───────────────────────────────────────────────────────────

    public function testNullTrue(): void
    {
        [$sql, $vals] = $this->f->toSql(['description' => ['_null' => true]]);
        $this->assertStringContainsString('IS NULL', $sql);
        $this->assertEmpty($vals);
    }

    public function testNullFalse(): void
    {
        [$sql, $vals] = $this->f->toSql(['description' => ['_null' => false]]);
        $this->assertStringContainsString('IS NOT NULL', $sql);
        $this->assertEmpty($vals);
    }

    public function testNnullTrue(): void
    {
        [$sql, $vals] = $this->f->toSql(['description' => ['_nnull' => true]]);
        $this->assertStringContainsString('IS NOT NULL', $sql);
    }

    // ── _null / _nnull: URL string values ("true"/"false") ───────────────────

    public function testNullStringTrue(): void
    {
        // URL params arrive as strings: filter[field][_null]=true
        [$sql] = $this->f->toSql(['description' => ['_null' => 'true']]);
        $this->assertStringContainsString('IS NULL', $sql);
    }

    public function testNullStringFalse(): void
    {
        // URL params arrive as strings: filter[field][_null]=false → IS NOT NULL
        [$sql] = $this->f->toSql(['description' => ['_null' => 'false']]);
        $this->assertStringContainsString('IS NOT NULL', $sql);
    }

    public function testNullStringOne(): void
    {
        [$sql] = $this->f->toSql(['description' => ['_null' => '1']]);
        $this->assertStringContainsString('IS NULL', $sql);
    }

    public function testNullStringZero(): void
    {
        [$sql] = $this->f->toSql(['description' => ['_null' => '0']]);
        $this->assertStringContainsString('IS NOT NULL', $sql);
    }

    // ── BETWEEN ───────────────────────────────────────────────────────────────

    public function testBetween(): void
    {
        [$sql, $vals] = $this->f->toSql(['score' => ['_between' => ['3', '8']]]);
        $this->assertStringContainsString('BETWEEN ? AND ?', $sql);
        $this->assertSame(['3', '8'], $vals);
    }

    public function testBetweenWrongArityThrows(): void
    {
        $this->expectException(FilterException::class);
        $this->f->toSql(['score' => ['_between' => ['3']]]);
    }

    // ── id field ──────────────────────────────────────────────────────────────

    public function testIdFieldAllowed(): void
    {
        [$sql, $vals] = $this->f->toSql(['id' => ['_eq' => 1]]);
        $this->assertStringContainsString('items.id = ?', $sql);
        $this->assertSame([1], $vals);
    }

    // ── Implicit AND (multiple fields at root) ────────────────────────────────

    public function testImplicitAnd(): void
    {
        [$sql, $vals] = $this->f->toSql([
            'status' => ['_eq'       => 'active'],
            'name'   => ['_icontains'=> 'item'  ],
        ]);
        $this->assertStringContainsString('items.status = ?', $sql);
        $this->assertStringContainsString('items.name LIKE ?', $sql);
        $this->assertStringContainsString('AND', $sql);
        $this->assertCount(2, $vals);
    }

    // ── Explicit _and ─────────────────────────────────────────────────────────

    public function testExplicitAnd(): void
    {
        [$sql, $vals] = $this->f->toSql([
            '_and' => [
                ['status' => ['_eq' => 'active']],
                ['name'   => ['_eq' => 'Alpha item']],
            ],
        ]);
        $this->assertStringContainsString('AND', $sql);
        $this->assertCount(2, $vals);
    }

    // ── Explicit _or ─────────────────────────────────────────────────────────

    public function testExplicitOr(): void
    {
        [$sql, $vals] = $this->f->toSql([
            '_or' => [
                ['status' => ['_eq' => 'active']],
                ['status' => ['_eq' => 'pending']],
            ],
        ]);
        $this->assertStringContainsString('OR', $sql);
        $this->assertCount(2, $vals);
    }

    // ── Nested _and inside _or ────────────────────────────────────────────────

    public function testNestedLogic(): void
    {
        [$sql, $vals] = $this->f->toSql([
            '_or' => [
                ['status' => ['_eq' => 'active'],  'name' => ['_icontains' => 'Alpha']],
                ['status' => ['_eq' => 'pending']],
            ],
        ]);
        $this->assertStringContainsString('OR', $sql);
        $this->assertStringContainsString('AND', $sql);
        $this->assertCount(3, $vals);
    }

    // ── Security: field allow-list ────────────────────────────────────────────

    public function testUnknownFieldThrows(): void
    {
        $this->expectException(FilterException::class);
        $this->f->toSql(['nonexistent_field' => ['_eq' => 'x']]);
    }

    public function testSqlInjectionInFieldNameThrows(): void
    {
        $this->expectException(FilterException::class);
        $this->f->toSql(["name; DROP TABLE items; --" => ['_eq' => 'x']]);
    }

    // ── Security: operator allow-list ────────────────────────────────────────

    public function testUnknownOperatorThrows(): void
    {
        $this->expectException(FilterException::class);
        $this->f->toSql(['name' => ['_rawsql' => '1=1']]);
    }

    // ── Full round-trip via API (GET with bracket notation) ───────────────────

    private const TB = 'items';

    private function get(array $extra = []): array
    {
        return array_merge(['tb' => self::TB], $extra);
    }

    public function testApiGetFilterEq(): void
    {
        $ctrl = $this->makeController('record_ctrl',
            $this->get(['filter' => ['status' => ['_eq' => 'active']]])
        );
        $res = $this->callController($ctrl, 'getRecords');

        $this->assertSame('success', $res['status']);
        $this->assertNotEmpty($res['data']);
        foreach ($res['data'] as $row) {
            $this->assertSame('active', $row['status']['val'] ?? $row['status'] ?? null);
        }
    }

    public function testApiGetFilterIcontains(): void
    {
        $ctrl = $this->makeController('record_ctrl',
            $this->get(['filter' => ['name' => ['_icontains' => 'item']]])
        );
        $res = $this->callController($ctrl, 'getRecords');

        $this->assertSame('success', $res['status']);
        $this->assertNotEmpty($res['data'], 'Should find records with "item" in name');
    }

    public function testApiGetFilterIn(): void
    {
        $ctrl = $this->makeController('record_ctrl',
            $this->get(['filter' => ['status' => ['_in' => ['active', 'pending']]]])
        );
        $res = $this->callController($ctrl, 'getRecords');

        $this->assertSame('success', $res['status']);
        $this->assertNotEmpty($res['data']);
    }

    public function testApiGetFilterImplicitAnd(): void
    {
        $ctrl = $this->makeController('record_ctrl',
            $this->get(['filter' => [
                'status' => ['_eq'        => 'active'],
                'name'   => ['_icontains' => 'Alpha' ],
            ]])
        );
        $res = $this->callController($ctrl, 'getRecords');

        $this->assertSame('success', $res['status']);
        $this->assertCount(1, $res['data']);
    }

    public function testApiGetFilterEmptyReturnsAll(): void
    {
        $ctrl = $this->makeController('record_ctrl', $this->get());
        $res  = $this->callController($ctrl, 'getRecords');

        $this->assertSame('success', $res['status']);
        $this->assertGreaterThanOrEqual(5, $res['total']);
    }

    public function testApiGetFilterUnknownFieldReturnsError(): void
    {
        $ctrl = $this->makeController('record_ctrl',
            $this->get(['filter' => ['nonexistent' => ['_eq' => 'x']]])
        );
        $res = $this->callController($ctrl, 'getRecords');

        $this->assertSame('error', $res['status']);
    }

    public function testApiQFieldnameStillWorks(): void
    {
        // q_fieldname=value shortcut now uses the filter type internally
        $ctrl = $this->makeController('record_ctrl',
            $this->get(['q_status' => 'active'])
        );
        $res = $this->callController($ctrl, 'getRecords');

        $this->assertSame('success', $res['status']);
    }
}
