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
        static::$db->exec("DELETE FROM bdus_geodata WHERE id >= 950");
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

    // ── inverse relation (rows of another table pointing here) ───────────────

    public function testInverseRelationListsTheRowsPointingAtTheRecord(): void
    {
        $rows = $this->byId($this->list(['tb' => 'categories', 'fields' => 'name,items.name']));

        $this->assertSame([[1, 'Alpha item']], array_map(
            fn($r) => [(int) $r['id'], $r['name']], $rows[1]['items']
        ));
        $this->assertSame('Beta item', $rows[2]['items'][0]['name']);
        $this->assertSame([], $rows[3]['items'], 'nothing points at it: an empty list');
    }

    public function testInverseRelationWithStarAndAlias(): void
    {
        $rows = $this->byId($this->list(['tb' => 'categories', 'fields' => 'name,pieces:items.*']));

        $this->assertArrayNotHasKey('items', $rows[1]);
        $this->assertEquals(1, $rows[1]['pieces'][0]['cat_ref']);
        $this->assertSame('active', $rows[1]['pieces'][0]['status']);
    }

    public function testInverseRelationDoesNotNeedThePointingColumnToBeAsked(): void
    {
        $rows = $this->byId($this->list(['tb' => 'categories', 'fields' => 'items.name']));

        $this->assertEqualsCanonicalizing(['id', 'name'], array_keys($rows[1]['items'][0]));
    }

    public function testInverseRelationColumnCanBeNamed(): void
    {
        $rows = $this->byId($this->list(['tb' => 'categories', 'fields' => 'items~cat_ref.name']));
        $this->assertCount(1, $rows[1]['items']);

        $res = $this->list(['tb' => 'categories', 'fields' => 'items~status.name']);
        $this->assertSame('invalid_parameters', $res['code']);
    }

    /** A Config whose `categories` links to `items` are replaced by $pairs. */
    private function cfgWithLinkPairs(array $pairs, string $other = 'items'): \Config\Config
    {
        return new class(static::$cfg, $pairs, $other) extends \Config\Config {
            public function __construct(private $inner, private array $pairs, private string $other) {}
            public function get(string $key = '*', string $filter_key = null, string $filter_val = null)
            {
                if ($key === 'tables.categories.link') {
                    return [['other_tb' => $this->other, 'fld' => $this->pairs]];
                }
                return $this->inner->get($key, $filter_key, $filter_val);
            }
        };
    }

    public function testSeveralPointingColumnsMustBeNamed(): void
    {
        $cfg = $this->cfgWithLinkPairs([
            ['my' => 'id', 'other' => 'cat_ref'], ['my' => 'id', 'other' => 'category'],
        ]);

        try {
            \Record\Expand::parse('items.name', static::$db, $cfg, 'categories');
            $this->fail('two columns, none named');
        } catch (\InvalidArgumentException $e) {
            $this->assertStringContainsString('items~cat_ref, items~category', $e->getMessage());
        }

        $x = \Record\Expand::parse('a:items~cat_ref.name,b:items~category.name', static::$db, $cfg, 'categories');
        $this->assertSame([], $x->mainColumns());
    }

    public function testCompositeReferencesAreNotOfferedAsInverseRelations(): void
    {
        $cfg = $this->cfgWithLinkPairs([['my' => 'name', 'other' => 'category']]);

        $this->expectExceptionMessage("Unknown relation 'items'");
        \Record\Expand::parse('items.name', static::$db, $cfg, 'categories');
    }

    public function testPluginTablesAreNotInverseRelations(): void
    {
        // tags is a plugin (of items): reached as a plugin, never as a table pointing here.
        $cfg = $this->cfgWithLinkPairs([['my' => 'id', 'other' => 'cat_ref']], 'tags');

        $this->expectExceptionMessage("Unknown relation 'tags'");
        \Record\Expand::parse('tags.label', static::$db, $cfg, 'categories');
    }

    public function testInverseRelationOmittedWithoutReadAccessAndFilteredByRows(): void
    {
        // The group reads categories, and items only when active (1, 3, 5): item 2 (Metal) is hidden.
        $this->reviewerOf('categories', null);
        $this->addRule('items', ['status' => ['_eq' => 'active']]);

        $rows = $this->byId($this->list(['tb' => 'categories', 'fields' => 'name,items.name']));
        $this->assertCount(1, $rows[1]['items']);
        $this->assertSame([], $rows[2]['items'], 'the only item pointing at Metal is outside the group');

        static::$db->exec("DELETE FROM bdus_group_rules WHERE table_name = 'items'");
        $rows = $this->byId($this->list(['tb' => 'categories', 'fields' => 'name,items.name']));
        $this->assertArrayNotHasKey('items', $rows[1], 'items not readable: the key is left out');
    }

    // ── geodata (bdus_geodata) ────────────────────────────────────────────────

    private function seedGeodata(): void
    {
        static::$db->exec(
            "INSERT INTO bdus_geodata (id, table_link, id_link, geometry) VALUES
                (950, 'items', 1, 'POINT(12.5 41.9)'),
                (951, 'items', 1, 'POINT(1 2)'),
                (952, 'items', 2, 'POINT(3 4)'),
                (953, 'categories', 1, 'POINT(9 9)')"
        );
    }

    public function testGeodataListsTheGeometriesOfEachRecord(): void
    {
        $this->seedGeodata();

        $rows = $this->byId($this->list(['fields' => 'name,geodata']));

        $this->assertCount(2, $rows[1]['geodata']);
        $this->assertSame(950, $rows[1]['geodata'][0]['id']);
        $this->assertSame('POINT(12.5 41.9)', $rows[1]['geodata'][0]['geometry']);
        $this->assertSame('Point', $rows[1]['geodata'][0]['geojson']['type']);
        $this->assertEquals([12.5, 41.9], $rows[1]['geodata'][0]['geojson']['coordinates']);
        $this->assertCount(1, $rows[2]['geodata']);
        $this->assertSame([], $rows[3]['geodata'], 'no geometry: an empty list');
    }

    public function testGeodataOfAnotherTableWithTheSameIdIsNotMixedIn(): void
    {
        $this->seedGeodata();   // 953 belongs to categories #1

        $rows = $this->byId($this->list(['fields' => 'geodata']));

        $this->assertSame([950, 951], array_column($rows[1]['geodata'], 'id'));
    }

    public function testGeodataPartsAndAlias(): void
    {
        $this->seedGeodata();

        $rows = $this->byId($this->list(['fields' => 'geo:geodata.geojson,geodata.geometry']));

        $this->assertEqualsCanonicalizing(['id', 'geojson'], array_keys($rows[1]['geo'][0]));
        $this->assertEqualsCanonicalizing(['id', 'geometry'], array_keys($rows[1]['geodata'][0]));
    }

    public function testGeodataNeedsAGeodataTable(): void
    {
        $res = $this->list(['tb' => 'categories', 'fields' => 'name,geodata']);

        $this->assertSame('invalid_parameters', $res['code']);
        $this->assertSame('invalid_parameters', $this->list(['fields' => 'geodata.nope'])['code']);
    }

    public function testGeodataFollowsTheRecordsTheGroupMayRead(): void
    {
        $this->seedGeodata();
        $this->reviewer(['status' => ['_eq' => 'active']]);   // items 1, 3, 5

        $rows = $this->byId($this->list(['fields' => 'geodata']));

        $this->assertSame([1, 3, 5], array_keys($rows));
        $this->assertCount(2, $rows[1]['geodata']);
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

    private function reviewerOf(string $table, ?array $filter): void
    {
        $this->group = (int) static::$db->query("INSERT INTO bdus_groups (name) VALUES ('Reviewers')", [], 'id');
        static::$db->exec("INSERT INTO bdus_users (id, name, email, password, privilege) VALUES (51, 'rev', 'rev@example.com', 'x', 39)");
        static::$db->exec("INSERT INTO bdus_user_groups (user_id, group_id) VALUES (51, {$this->group})");
        $this->addRule($table, $filter);
        \Auth\CurrentUser::set(['id' => 51, 'name' => 'rev', 'email' => 'rev@example.com', 'privilege' => 39, 'app' => 'test']);
    }

    private function addRule(string $table, ?array $filter): void
    {
        static::$db->query(
            'INSERT INTO bdus_group_rules (group_id, table_name, action, filter) VALUES (?, ?, ?, ?)',
            [$this->group, $table, 'read', $filter === null ? null : json_encode($filter)],
            'boolean'
        );
    }

    private function reviewer(?array $itemsFilter, ?array $categoryFilter = null, bool $categoryRule = false): void
    {
        $this->reviewerOf('items', $itemsFilter);
        if ($categoryRule) {
            $this->addRule('categories', $categoryFilter);
        }
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
