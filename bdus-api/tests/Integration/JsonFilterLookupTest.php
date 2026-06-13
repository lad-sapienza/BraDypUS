<?php

namespace Tests\Integration;

use Tests\Support\BdusTestCase;
use SQL\Filter\JsonFilter;
use SQL\Filter\FilterException;

/**
 * Tests for the lookup-field (id_from_tb) traversal of SQL\Filter\JsonFilter.
 *
 * Fixture setup (see BdusTestCase):
 *   items.cat_ref → categories (id_from_tb), item 1 → Ceramics, item 2 → Metal
 *   tags.cat_ref  → categories (id_from_tb), tag-a → Ceramics
 *
 * Covered syntax:
 *   { cat_ref: { name: { _eq: 'Ceramics' } } }
 *   → items.cat_ref IN (SELECT id FROM categories WHERE name = ?)
 *
 *   { tags: { cat_ref: { name: { _eq: 'Ceramics' } } } }
 *   → items.id IN (SELECT id_link FROM tags WHERE table_link = ?
 *                  AND cat_ref IN (SELECT id FROM categories WHERE name = ?))
 */
class JsonFilterLookupTest extends BdusTestCase
{
    private JsonFilter $f;

    protected function setUp(): void
    {
        $this->f = new JsonFilter(static::$cfg, 'items');
    }

    // ── SQL shape: main-table lookup ──────────────────────────────────────────

    public function testLookupEq(): void
    {
        [$sql, $vals] = $this->f->toSql(['cat_ref' => ['name' => ['_eq' => 'Ceramics']]]);
        $this->assertStringContainsString('items.cat_ref IN (SELECT id FROM categories WHERE', $sql);
        $this->assertStringContainsString('categories.name = ?', $sql);
        $this->assertSame(['Ceramics'], $vals);
    }

    public function testLookupIcontains(): void
    {
        [$sql, $vals] = $this->f->toSql(['cat_ref' => ['name' => ['_icontains' => 'cera']]]);
        $this->assertStringContainsString('categories.name LIKE ?', $sql);
        $this->assertSame(['%cera%'], $vals);
    }

    public function testLookupOnSecondaryRefField(): void
    {
        // Any field of the referenced table can be used, not only its id_field
        [$sql, $vals] = $this->f->toSql(['cat_ref' => ['macro' => ['_eq' => 'Ecofacts']]]);
        $this->assertStringContainsString('categories.macro = ?', $sql);
        $this->assertSame(['Ecofacts'], $vals);
    }

    public function testLookupWithLogicalGroup(): void
    {
        [$sql, $vals] = $this->f->toSql(['cat_ref' => ['_or' => [
            ['name' => ['_eq' => 'Ceramics']],
            ['name' => ['_eq' => 'Metal']],
        ]]]);
        $this->assertStringContainsString('items.cat_ref IN (SELECT id FROM categories WHERE', $sql);
        $this->assertStringContainsString('OR', $sql);
        $this->assertSame(['Ceramics', 'Metal'], $vals);
    }

    public function testLookupEmptyConditionsReturnsAll(): void
    {
        [$sql, $vals] = $this->f->toSql(['cat_ref' => []]);
        $this->assertSame('1=1', $sql);
        $this->assertEmpty($vals);
    }

    // ── SQL shape: lookup inside a plugin subquery ────────────────────────────

    public function testPluginLookup(): void
    {
        [$sql, $vals] = $this->f->toSql([
            'tags' => ['cat_ref' => ['name' => ['_eq' => 'Ceramics']]],
        ]);
        $this->assertStringContainsString('items.id IN (SELECT id_link FROM tags WHERE table_link = ?', $sql);
        $this->assertStringContainsString('cat_ref IN (SELECT id FROM categories WHERE', $sql);
        $this->assertSame(['items', 'Ceramics'], $vals);
    }

    // ── Validation ────────────────────────────────────────────────────────────

    public function testLookupUnknownRefFieldThrows(): void
    {
        $this->expectException(FilterException::class);
        $this->f->toSql(['cat_ref' => ['nonexistent' => ['_eq' => 'x']]]);
    }

    public function testNestedObjectOnNonLookupFieldThrows(): void
    {
        // 'name' is a plain items field without id_from_tb: nested traversal is invalid
        $this->expectException(FilterException::class);
        $this->f->toSql(['name' => ['whatever' => ['_eq' => 'x']]]);
    }

    public function testLookupUnknownOperatorThrows(): void
    {
        $this->expectException(FilterException::class);
        $this->f->toSql(['cat_ref' => ['name' => ['_rawsql' => '1=1']]]);
    }

    // ── Direct conditions on the lookup column still work (id comparison) ────

    public function testDirectIdConditionOnLookupField(): void
    {
        [$sql, $vals] = $this->f->toSql(['cat_ref' => ['_eq' => 2]]);
        $this->assertStringContainsString('items.cat_ref = ?', $sql);
        $this->assertSame([2], $vals);
    }

    // ── Full round-trip via API ───────────────────────────────────────────────

    public function testApiLookupFindsRecord(): void
    {
        $ctrl = $this->makeController('Bdus\\Controllers\\Record', [
            'tb'     => 'items',
            'filter' => ['cat_ref' => ['name' => ['_eq' => 'Ceramics']]],
        ]);
        $res = $this->callController($ctrl, 'getRecords');

        $this->assertSame('success', $res['status']);
        $this->assertCount(1, $res['data']);
        $row = $res['data'][0];
        $this->assertSame('Alpha item', $row['name']['val'] ?? $row['name']);
    }

    public function testApiPluginLookupFindsParentRecord(): void
    {
        // tag-a (Ceramics) belongs to item 1
        $ctrl = $this->makeController('Bdus\\Controllers\\Record', [
            'tb'     => 'items',
            'filter' => ['tags' => ['cat_ref' => ['name' => ['_eq' => 'Ceramics']]]],
        ]);
        $res = $this->callController($ctrl, 'getRecords');

        $this->assertSame('success', $res['status']);
        $this->assertCount(1, $res['data']);
        $row = $res['data'][0];
        $this->assertSame('Alpha item', $row['name']['val'] ?? $row['name']);
    }

    public function testApiLookupNoMatchReturnsEmpty(): void
    {
        // Charcoal exists in categories but no item references it
        $ctrl = $this->makeController('Bdus\\Controllers\\Record', [
            'tb'     => 'items',
            'filter' => ['cat_ref' => ['name' => ['_eq' => 'Charcoal']]],
        ]);
        $res = $this->callController($ctrl, 'getRecords');

        $this->assertSame('success', $res['status']);
        $this->assertEmpty($res['data']);
    }
}
