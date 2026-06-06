<?php

namespace Tests\Integration;

use Tests\Support\BdusTestCase;
use SQL\Filter\JsonFilter;
use SQL\Filter\FilterException;

/**
 * Tests for the "backlinked table" filter path in JsonFilter.
 *
 * When a related table is not a plugin of the main table but IS referenced
 * in the main table's backlinks config as "{refTb}:{viaTb}:{fkCol}", the
 * filter generates an explicit-FK subquery:
 *
 *   main.id IN (SELECT {fkCol} FROM {viaTb} WHERE {conditions})
 *
 * Fixture setup (see BdusTestCase):
 *   - items.backlinks = ["other:reviews:item_ref"]
 *   - reviews table: id, item_ref, reviewer, content, rating
 *   - item 1 reviewed by alice (rating 5) and bob (rating 4)
 *   - item 2 reviewed by bob (rating 3)
 *   - item 3 no reviews
 */
class JsonFilterBacklinkTest extends BdusTestCase
{
    private JsonFilter $f;

    protected function setUp(): void
    {
        $this->f = new JsonFilter(static::$cfg, 'items');
    }

    // ── SQL generation ────────────────────────────────────────────────────────

    public function testBacklinkFilterGeneratesCorrectSql(): void
    {
        [$sql, $vals] = $this->f->toSql([
            'reviews' => ['reviewer' => ['_eq' => 'alice']],
        ]);

        // Must use the explicit FK column (item_ref), not the plugin convention
        $this->assertStringContainsString('items.id IN', $sql);
        $this->assertStringContainsString('SELECT item_ref FROM reviews', $sql);
        $this->assertStringContainsString('reviewer = ?', $sql);
        $this->assertSame(['alice'], $vals);

        // Must NOT use the plugin-style table_link / id_link convention
        $this->assertStringNotContainsString('table_link', $sql);
        $this->assertStringNotContainsString('id_link', $sql);
    }

    public function testBacklinkFilterWithIcontains(): void
    {
        [$sql, $vals] = $this->f->toSql([
            'reviews' => ['content' => ['_icontains' => 'good']],
        ]);

        $this->assertStringContainsString('SELECT item_ref FROM reviews', $sql);
        $this->assertStringContainsString('content LIKE ?', $sql);
        $this->assertSame(['%good%'], $vals);
    }

    public function testBacklinkFilterWithGte(): void
    {
        [$sql, $vals] = $this->f->toSql([
            'reviews' => ['rating' => ['_gte' => '4']],
        ]);

        $this->assertStringContainsString('SELECT item_ref FROM reviews', $sql);
        $this->assertStringContainsString('rating >= ?', $sql);
        $this->assertSame(['4'], $vals);
    }

    public function testBacklinkFilterMultipleConditionsOnSameTable(): void
    {
        [$sql, $vals] = $this->f->toSql([
            'reviews' => [
                'reviewer' => ['_eq'  => 'alice'],
                'rating'   => ['_gte' => '4'],
            ],
        ]);

        $this->assertStringContainsString('SELECT item_ref FROM reviews', $sql);
        $this->assertStringContainsString('reviewer = ?', $sql);
        $this->assertStringContainsString('rating >= ?', $sql);
        $this->assertContains('alice', $vals);
        $this->assertContains('4', $vals);
    }

    public function testBacklinkEmptyConditionsReturnsAll(): void
    {
        [$sql, $vals] = $this->f->toSql(['reviews' => []]);
        $this->assertSame('1=1', $sql);
        $this->assertEmpty($vals);
    }

    public function testBacklinkUnknownFieldThrows(): void
    {
        $this->expectException(FilterException::class);
        $this->f->toSql(['reviews' => ['nonexistent' => ['_eq' => 'x']]]);
    }

    public function testUnknownTableNotInPluginOrBacklinkThrows(): void
    {
        $this->expectException(FilterException::class);
        $this->f->toSql(['completely_unknown_table' => ['reviewer' => ['_eq' => 'x']]]);
    }

    // ── Plugin path still works ────────────────────────────────────────────────

    public function testPluginPathUnchanged(): void
    {
        [$sql, $vals] = $this->f->toSql([
            'tags' => ['label' => ['_eq' => 'tag-a']],
        ]);

        // Plugin path: must use id_link / table_link convention
        $this->assertStringContainsString('SELECT id_link FROM tags', $sql);
        $this->assertStringContainsString('table_link = ?', $sql);
        $this->assertStringContainsString('label = ?', $sql);
        $this->assertContains('items', $vals); // table_link bind value
        $this->assertContains('tag-a', $vals);
    }

    // ── Full round-trip via API ───────────────────────────────────────────────

    public function testApiFilterByReviewerReturnsCorrectItems(): void
    {
        $ctrl = $this->makeController(
            'Bdus\\Controllers\\Record',
            ['tb' => 'items', 'filter' => ['reviews' => ['reviewer' => ['_eq' => 'alice']]]]
        );
        $res = $this->callController($ctrl, 'getRecords');

        $this->assertSame('success', $res['status']);
        $this->assertCount(1, $res['data'], 'Only item 1 has a review by alice');

        $names = array_column(array_map(
            fn($row) => ['name' => $row['name']['val'] ?? $row['name'] ?? null],
            $res['data']
        ), 'name');
        $this->assertContains('Alpha item', $names);
    }

    public function testApiFilterByReviewerBobReturnsTwoItems(): void
    {
        $ctrl = $this->makeController(
            'Bdus\\Controllers\\Record',
            ['tb' => 'items', 'filter' => ['reviews' => ['reviewer' => ['_eq' => 'bob']]]]
        );
        $res = $this->callController($ctrl, 'getRecords');

        $this->assertSame('success', $res['status']);
        $this->assertCount(2, $res['data'], 'Items 1 and 2 both have reviews by bob');
    }

    public function testApiFilterByHighRatingReturnsCorrectItems(): void
    {
        $ctrl = $this->makeController(
            'Bdus\\Controllers\\Record',
            ['tb' => 'items', 'filter' => ['reviews' => ['rating' => ['_gte' => '5']]]]
        );
        $res = $this->callController($ctrl, 'getRecords');

        $this->assertSame('success', $res['status']);
        $this->assertCount(1, $res['data'], 'Only item 1 has a review with rating >= 5');
    }

    public function testApiFilterNoMatchReturnsEmpty(): void
    {
        $ctrl = $this->makeController(
            'Bdus\\Controllers\\Record',
            ['tb' => 'items', 'filter' => ['reviews' => ['reviewer' => ['_eq' => 'charlie']]]]
        );
        $res = $this->callController($ctrl, 'getRecords');

        $this->assertSame('success', $res['status']);
        $this->assertCount(0, $res['data'], 'No items reviewed by charlie');
    }

    public function testApiCombineBacklinkWithMainField(): void
    {
        // filter: items named "Alpha item" AND reviewed by alice
        $ctrl = $this->makeController(
            'Bdus\\Controllers\\Record',
            ['tb' => 'items', 'filter' => [
                'name'    => ['_eq' => 'Alpha item'],
                'reviews' => ['reviewer' => ['_eq' => 'alice']],
            ]]
        );
        $res = $this->callController($ctrl, 'getRecords');

        $this->assertSame('success', $res['status']);
        $this->assertCount(1, $res['data']);
    }

    // ── Two-hop: plugin → plugin_of parent (Caso 2 analogue) ─────────────────
    //
    // Fixture: tags is a plugin of items (table_link/id_link convention),
    // AND tags.plugin_of = 'items'.  So filter[tags][items][status][_eq]=active
    // is the test-fixture analogue of filter[m_msplaces][manuscripts][palimpsest][_eq]=1.
    //
    // SQL generated (plugin path):
    //   items.id IN (
    //     SELECT id_link FROM tags
    //     WHERE table_link = ?            ← 'items' (from buildPluginSubquery)
    //       AND table_link = ?            ← 'items' (from buildNestedCondition, redundant but harmless)
    //       AND id_link IN (SELECT id FROM items WHERE status = ?)
    //   )

    public function testNestedPluginOfGeneratesCorrectSql(): void
    {
        [$sql, $vals] = $this->f->toSql([
            'tags' => ['items' => ['status' => ['_eq' => 'active']]],
        ]);

        // Must contain the id_link IN (...) nested subquery
        $this->assertStringContainsString('SELECT id_link FROM tags', $sql);
        $this->assertStringContainsString(
            'id_link IN (SELECT id FROM items WHERE status = ?)',
            $sql
        );
        $this->assertContains('active', $vals);

        // Must NOT treat 'items' as a bare field on tags
        $this->assertStringNotContainsString('tags.items', $sql);
    }

    public function testNestedPluginOfWithGte(): void
    {
        [$sql, $vals] = $this->f->toSql([
            'tags' => ['items' => ['score' => ['_gte' => '7']]],
        ]);

        $this->assertStringContainsString('SELECT id_link FROM tags', $sql);
        $this->assertStringContainsString('id_link IN (SELECT id FROM items WHERE score >= ?)', $sql);
        $this->assertContains('7', $vals);
    }

    public function testNestedWithLogicalAndGroup(): void
    {
        // Equivalent of: filter[m_msplaces][manuscripts][_and][][chronofrom][_gt]=599
        //                filter[m_msplaces][manuscripts][_and][][chronofrom][_lt]=700
        // Uses the plugin path (tags → items) to exercise the same code.
        [$sql, $vals] = $this->f->toSql([
            'tags' => [
                'items' => [
                    '_and' => [
                        ['score' => ['_gte' => '5']],
                        ['score' => ['_lte' => '10']],
                    ],
                ],
            ],
        ]);

        // _and logical group inside the nested (parent) table
        $this->assertStringContainsString('SELECT id_link FROM tags', $sql);
        $this->assertStringContainsString('id_link IN (SELECT id FROM items WHERE', $sql);
        $this->assertStringContainsString('AND', $sql);
        $this->assertContains('5', $vals);
        $this->assertContains('10', $vals);
    }

    public function testNestedWithOrGroup(): void
    {
        [$sql, $vals] = $this->f->toSql([
            'tags' => [
                'items' => [
                    '_or' => [
                        ['status' => ['_eq' => 'active']],
                        ['status' => ['_eq' => 'pending']],
                    ],
                ],
            ],
        ]);

        $this->assertStringContainsString('SELECT id_link FROM tags', $sql);
        $this->assertStringContainsString('id_link IN (SELECT id FROM items WHERE', $sql);
        $this->assertStringContainsString('OR', $sql);
        $this->assertContains('active', $vals);
        $this->assertContains('pending', $vals);
    }

    public function testNestedEmptyConditionsReturnsAll(): void
    {
        [$sql, $vals] = $this->f->toSql([
            'tags' => ['items' => []],
        ]);
        // buildNestedCondition with empty conditions returns '1=1'
        $this->assertStringContainsString('1=1', $sql);
        $this->assertEmpty($vals);
    }

    public function testNestedUnknownFieldOnParentThrows(): void
    {
        $this->expectException(FilterException::class);
        $this->f->toSql(['tags' => ['items' => ['nonexistent' => ['_eq' => 'x']]]]);
    }

    public function testNestedUnknownParentTableThrows(): void
    {
        $this->expectException(FilterException::class);
        // 'tags' plugin_of = 'items', not 'sources'
        $this->f->toSql(['tags' => ['sources' => ['title' => ['_eq' => 'x']]]]);
    }

    public function testApiNestedPluginOfFiltersCorrectly(): void
    {
        // filter[tags][items][status][_eq]=active
        // tags.id_link IN items where status=active: ids {1,3,5}
        // tags rows have id_link=1 → item 1 is returned (the only tagged active item)
        $ctrl = $this->makeController(
            'Bdus\\Controllers\\Record',
            ['tb' => 'items', 'filter' => [
                'tags' => ['items' => ['status' => ['_eq' => 'active']]],
            ]]
        );
        $res = $this->callController($ctrl, 'getRecords');

        $this->assertSame('success', $res['status']);
        // Only item 1 has tags AND status=active (tags only link to item 1)
        $this->assertCount(1, $res['data']);
    }

    public function testApiNestedPluginOfNoMatchReturnsEmpty(): void
    {
        // filter[tags][items][status][_eq]=pending
        // tags.id_link IN items where status=pending: {4}
        // No tags row has id_link=4 → empty result
        $ctrl = $this->makeController(
            'Bdus\\Controllers\\Record',
            ['tb' => 'items', 'filter' => [
                'tags' => ['items' => ['status' => ['_eq' => 'pending']]],
            ]]
        );
        $res = $this->callController($ctrl, 'getRecords');

        $this->assertSame('success', $res['status']);
        $this->assertCount(0, $res['data']);
    }
}
