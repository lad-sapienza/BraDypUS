<?php

namespace Tests\Integration;

use Tests\Support\BdusTestCase;

/**
 * `fields` on GET|POST /api/records/{tb}: columns plus related rows nested in
 * each row (Directus style), see \Record\Expand.
 *
 * Fixture facts: items 1..5; item 1 has two tags (plugin, id_link) and points to
 * category 1 (Ceramics) through the lookup cat_ref; item 2 points to category 2
 * (Metal); items 3-5 have no tags and no category.
 */
class RecordCtrlFieldsExpandTest extends BdusTestCase
{
    private int $group = 0;

    protected function tearDown(): void
    {
        if ($this->group) {
            static::$db->exec('DELETE FROM bdus_user_groups');
            static::$db->exec('DELETE FROM bdus_group_rules');
            static::$db->exec('DELETE FROM bdus_groups');
            static::$db->exec('DELETE FROM bdus_users WHERE id = 51');
            $this->group = 0;
        }
        static::$db->exec("DELETE FROM tags WHERE id > 2");
        $this->setPrivilege(1);
    }

    private function list(array $get = [], array $post = []): array
    {
        $get = array_merge(['tb' => 'items'], $get);
        return $this->callController($this->makeController('Bdus\\Controllers\\Record', $get, $post), 'getRecords');
    }

    /** @return array<int, array> rows keyed by id */
    private function byId(array $res): array
    {
        $this->assertSame('success', $res['status'], json_encode($res));
        $out = [];
        foreach ($res['data'] as $row) {
            $out[(int) $row['id']] = $row;
        }
        return $out;
    }

    // ── no `fields`: nothing changes ──────────────────────────────────────────

    public function testWithoutFieldsTheListIsUnchanged(): void
    {
        $plain = $this->list();
        $rows  = $this->byId($plain);

        $this->assertArrayNotHasKey('tags', $rows[1]);
        $this->assertCount(5, $rows);
        $this->assertSame(['status', 'total', 'fields', 'data', 'can_add', 'can'], array_keys($plain));
    }

    // ── plugin rows ───────────────────────────────────────────────────────────

    public function testPluginRowsAreNestedUnderTheTableName(): void
    {
        $rows = $this->byId($this->list(['fields' => 'id,name,tags.*']));

        $this->assertSame('Alpha item', $rows[1]['name']);
        $this->assertCount(2, $rows[1]['tags']);
        $this->assertSame(['tag-a', 'tag-b'], array_column($rows[1]['tags'], 'label'));
        $this->assertSame([], $rows[3]['tags'], 'a record without plugin rows gets an empty list');
        $this->assertArrayNotHasKey('status', $rows[1], 'only the columns asked for');
    }

    public function testNamedColumnsOfARelationAreTheOnlyOnesReturnedPlusId(): void
    {
        $rows = $this->byId($this->list(['fields' => 'name,tags.label']));

        foreach ($rows[1]['tags'] as $tag) {
            $this->assertEqualsCanonicalizing(['id', 'label'], array_keys($tag));
        }
    }

    public function testAliasRenamesTheKey(): void
    {
        $rows = $this->byId($this->list(['fields' => 'name,summary:tags.label']));

        $this->assertArrayHasKey('summary', $rows[1]);
        $this->assertArrayNotHasKey('tags', $rows[1]);
        $this->assertCount(2, $rows[1]['summary']);
    }

    public function testOnlyRelationsReturnsIdAndTheRelations(): void
    {
        $rows = $this->byId($this->list(['fields' => 'tags.*']));

        $this->assertEqualsCanonicalizing(['id', 'tags'], array_keys($rows[1]));
    }

    public function testFieldsAlsoAcceptedInThePostBodyAsArray(): void
    {
        $rows = $this->byId($this->list([], ['fields' => ['name', 'tags.label']]));

        $this->assertCount(2, $rows[1]['tags']);
    }

    // ── lookup (id_from_tb) ───────────────────────────────────────────────────

    public function testLookupIsReplacedByTheReferencedRow(): void
    {
        $rows = $this->byId($this->list(['fields' => 'name,cat_ref.*']));

        $this->assertSame('Ceramics', $rows[1]['cat_ref']['name']);
        $this->assertEquals(1, $rows[1]['cat_ref']['id']);
        $this->assertSame('Metal', $rows[2]['cat_ref']['name']);
        $this->assertArrayNotHasKey('@cat_ref', $rows[1], 'the object replaces the id and its label');
        $this->assertEmpty($rows[3]['cat_ref'] ?? null, 'no category: nothing to expand');
    }

    public function testLookupWithSelectedColumnOnly(): void
    {
        $rows = $this->byId($this->list(['fields' => 'cat_ref.name']));

        $this->assertEqualsCanonicalizing(['id', 'name'], array_keys($rows[1]['cat_ref']));
    }

    public function testAliasedLookupKeepsTheRawId(): void
    {
        $rows = $this->byId($this->list(['fields' => 'cat_ref,cat:cat_ref.name']));

        $this->assertEquals(1, $rows[1]['cat_ref']);
        $this->assertSame('Ceramics', $rows[1]['cat']['name']);
    }

    // ── bad requests ──────────────────────────────────────────────────────────

    public function testUnknownRelationIsRefused(): void
    {
        $res = $this->list(['fields' => 'nope.*']);

        $this->assertSame('error', $res['status']);
        $this->assertSame('invalid_parameters', $res['code']);
    }

    public function testNestingDeeperThanOneLevelIsRefused(): void
    {
        $res = $this->list(['fields' => 'cat_ref.sub.name']);

        $this->assertSame('invalid_parameters', $res['code']);
    }

    public function testAliasClashingWithAColumnIsRefused(): void
    {
        $res = $this->list(['fields' => 'name:tags.label']);

        $this->assertSame('invalid_parameters', $res['code']);
    }

    public function testNestedRowsAreCapped(): void
    {
        // One recursive insert: MAX_NESTED_ROWS + 1 plugin rows on item 2.
        $n = \Record\Expand::MAX_NESTED_ROWS + 1;
        static::$db->exec(
            "WITH RECURSIVE c(x) AS (SELECT 1 UNION ALL SELECT x + 1 FROM c WHERE x < {$n})
             INSERT INTO tags (label, id_link) SELECT 'bulk', 2 FROM c"
        );

        $res = $this->list(['fields' => 'tags.*']);

        $this->assertSame('too_many_nested_rows', $res['code']);
    }

    // ── permissions ───────────────────────────────────────────────────────────

    private function reviewer(?array $itemsFilter, ?array $categoryFilter = null, bool $categoryRule = false): void
    {
        $this->group = (int) static::$db->query("INSERT INTO bdus_groups (name) VALUES ('Reviewers')", [], 'id');
        static::$db->exec("INSERT INTO bdus_users (id, name, email, password, privilege) VALUES (51, 'rev', 'rev@example.com', 'x', 39)");
        static::$db->exec("INSERT INTO bdus_user_groups (user_id, group_id) VALUES (51, {$this->group})");
        $rule = fn(string $tb, ?array $f) => static::$db->query(
            'INSERT INTO bdus_group_rules (group_id, table_name, action, filter) VALUES (?, ?, ?, ?)',
            [$this->group, $tb, 'read', $f === null ? null : json_encode($f)],
            'boolean'
        );
        $rule('items', $itemsFilter);
        if ($categoryRule) {
            $rule('categories', $categoryFilter);
        }
        \Auth\CurrentUser::set(['id' => 51, 'name' => 'rev', 'email' => 'rev@example.com', 'privilege' => 39, 'app' => 'test']);
    }

    public function testLookupStaysARawIdWithoutReadAccessToTheReferencedTable(): void
    {
        $this->reviewer(['status' => ['_eq' => 'active']]);   // items 1, 3, 5; nothing on categories

        $rows = $this->byId($this->list(['fields' => 'name,cat_ref.*']));

        $this->assertSame([1, 3, 5], array_keys($rows));
        $this->assertEquals(1, $rows[1]['cat_ref'], 'raw id, nothing leaked from categories');
    }

    public function testLookupExpandsOnlyRowsTheGroupMayRead(): void
    {
        $this->reviewer(null, ['name' => ['_eq' => 'Ceramics']], true);   // all items, category Ceramics only

        $rows = $this->byId($this->list(['fields' => 'name,cat_ref.*']));

        $this->assertSame('Ceramics', $rows[1]['cat_ref']['name']);
        $this->assertEquals(2, $rows[2]['cat_ref'], 'Metal is outside the group: raw id');
    }

    public function testPluginRowsFollowTheRecordsTheGroupMayRead(): void
    {
        $this->reviewer(['status' => ['_eq' => 'active']]);

        $rows = $this->byId($this->list(['fields' => 'name,tags.*']));

        $this->assertArrayNotHasKey(2, $rows);
        $this->assertCount(2, $rows[1]['tags']);
    }
}
