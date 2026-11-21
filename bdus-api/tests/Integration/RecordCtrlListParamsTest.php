<?php

namespace Tests\Integration;

use Tests\Support\BdusTestCase;

/**
 * Directus-style list parameters of GET|POST /api/records/{tb}: `limit` (-1 = all
 * up to a cap), `offset`, `sort=a,-b`, `search` alone, `meta=`.
 *
 * Fixture facts: items 1..5 = Alpha(active) Beta(inactive) Gamma(active)
 * Delta(pending) Epsilon(active).
 */
class RecordCtrlListParamsTest extends BdusTestCase
{
    private int $group = 0;

    protected function tearDown(): void
    {
        putenv('BRADYPUS_API_MAX_ROWS');
        if ($this->group) {
            static::$db->exec('DELETE FROM bdus_user_groups');
            static::$db->exec('DELETE FROM bdus_group_rules');
            static::$db->exec('DELETE FROM bdus_groups');
            static::$db->exec('DELETE FROM bdus_users WHERE id = 51');
            $this->group = 0;
        }
        $this->setPrivilege(1);
    }

    private function list(array $get = [], array $post = []): array
    {
        $get = array_merge(['tb' => 'items'], $get);
        $res = $this->callController($this->makeController('Bdus\\Controllers\\Record', $get, $post), 'getRecords');
        $this->assertSame('success', $res['status'], json_encode($res));
        return $res;
    }

    /** @return int[] ids in response order */
    private function ids(array $res): array
    {
        return array_map(fn($r) => (int) $r['id'], $res['data']);
    }

    // ── limit / offset ────────────────────────────────────────────────────────

    public function testLimitIsAnAliasOfPerPage(): void
    {
        $res = $this->list(['limit' => 2]);

        $this->assertSame([1, 2], $this->ids($res));
        $this->assertSame(5, $res['total']);
        $this->assertArrayNotHasKey('truncated', $res, 'no cap involved: the response is as before');
    }

    public function testLimitMinusOneReturnsEverything(): void
    {
        $res = $this->list(['limit' => -1]);

        $this->assertSame([1, 2, 3, 4, 5], $this->ids($res));
        $this->assertFalse($res['truncated']);
    }

    public function testLimitMinusOneIsCutAtTheCapAndSaysSo(): void
    {
        putenv('BRADYPUS_API_MAX_ROWS=3');

        $res = $this->list(['limit' => -1]);

        $this->assertSame([1, 2, 3], $this->ids($res));
        $this->assertTrue($res['truncated']);
        $this->assertSame(5, $res['total']);
    }

    public function testLimitAboveTheCapIsCutToo(): void
    {
        putenv('BRADYPUS_API_MAX_ROWS=4');

        $res = $this->list(['limit' => 100]);

        $this->assertCount(4, $res['data']);
        $this->assertTrue($res['truncated']);
    }

    public function testPerPageStillWinsOverLimitAndKeepsItsOwnCeiling(): void
    {
        $this->assertCount(1, $this->list(['per_page' => 1, 'limit' => 4])['data']);
        $this->assertCount(5, $this->list(['per_page' => 9999])['data']);   // 200 max, 5 rows exist
    }

    public function testNonsenseLimitIsIgnored(): void
    {
        $this->assertCount(5, $this->list(['limit' => 'abc'])['data']);
        $this->assertCount(5, $this->list(['limit' => 0])['data']);
    }

    public function testOffsetReplacesPage(): void
    {
        $this->assertSame([4, 5], $this->ids($this->list(['limit' => 2, 'offset' => 3])));
        $this->assertSame([4, 5], $this->ids($this->list(['limit' => 2, 'offset' => 3, 'page' => 1])));
    }

    public function testPageStillWorksWithoutOffset(): void
    {
        $this->assertSame([3, 4], $this->ids($this->list(['per_page' => 2, 'page' => 2])));
    }

    // ── sort ──────────────────────────────────────────────────────────────────

    public function testSortDescendingWithAMinus(): void
    {
        $this->assertSame([5, 4, 3, 2, 1], $this->ids($this->list(['sort' => '-id'])));
    }

    public function testSortOnSeveralFields(): void
    {
        // status asc, then name desc: active (Gamma, Epsilon, Alpha), inactive, pending
        $this->assertSame([3, 5, 1, 2, 4], $this->ids($this->list(['sort' => 'status,-name'])));
    }

    public function testSortAcceptsAnArrayInThePostBody(): void
    {
        $res = $this->callController(
            $this->makeController('Bdus\\Controllers\\Record', ['tb' => 'items'], ['sort' => ['status', '-name']]),
            'getRecords'
        );

        $this->assertSame([3, 5, 1, 2, 4], $this->ids($res));
    }

    public function testSortFieldWinsOverSort(): void
    {
        $res = $this->list(['sort_field' => 'name', 'sort_dir' => 'asc', 'sort' => '-name']);

        $this->assertSame([1, 2, 4, 5, 3], $this->ids($res));   // Alpha Beta Delta Epsilon Gamma
    }

    public function testSortNullsPutsEmptyValuesWhereAsked(): void
    {
        // cat_ref is a lookup: items 1 (Ceramics) and 2 (Metal) have one, 3-5 do not
        $this->assertSame([1, 2, 3, 4, 5], $this->ids($this->list(['sort_field' => 'cat_ref', 'sort_nulls' => 'last'])));
        $this->assertSame([3, 4, 5, 1, 2], $this->ids($this->list(['sort_field' => 'cat_ref', 'sort_nulls' => 'first'])));
        $this->assertSame([2, 1, 5, 4, 3], $this->ids($this->list(['sort_field' => 'cat_ref', 'sort_dir' => 'desc', 'sort_nulls' => 'last'])));
        $this->assertSame([5, 4, 3, 2, 1], $this->ids($this->list(['sort_field' => 'cat_ref', 'sort_dir' => 'desc', 'sort_nulls' => 'first'])));
    }

    public function testSortNullsAppliesToEveryKeyOfAMultiSort(): void
    {
        $this->assertSame([2, 1, 3, 5, 4], $this->ids($this->list(['sort' => '-cat_ref,-name', 'sort_nulls' => 'last'])));
    }

    public function testUnknownSortNullsValueChangesNothing(): void
    {
        $this->assertSame(
            $this->ids($this->list(['sort_field' => 'cat_ref'])),
            $this->ids($this->list(['sort_field' => 'cat_ref', 'sort_nulls' => 'sideways']))
        );
    }

    public function testUnknownSortFieldsAreIgnored(): void
    {
        $this->assertSame([1, 2, 3, 4, 5], $this->ids($this->list(['sort' => 'nope,-drop;table'])));
        $this->assertSame([3, 5, 1, 2, 4], $this->ids($this->list(['sort' => 'nope,status,-name'])));
    }

    public function testIdIsSortableEvenWhenTheConfigDoesNotListIt(): void
    {
        // A migrated table may not declare `id` as a field: sort=-id must still sort by it.
        $cfg = new class(static::$cfg) extends \Config\Config {
            public function __construct(private $inner) {}
            public function get(string $key = '*', string $filter_key = null, string $filter_val = null)
            {
                $v = $this->inner->get($key, $filter_key, $filter_val);
                if ($key === 'tables.items.fields.*.name' && is_array($v)) {
                    unset($v['id']);
                }
                return $v;
            }
        };
        $ctrl = $this->makeController('Bdus\\Controllers\\Record', ['tb' => 'items', 'sort' => '-id']);
        $ctrl->setCfg($cfg);

        $this->assertSame([5, 4, 3, 2, 1], $this->ids($this->callController($ctrl, 'getRecords')));

        $ctrl = $this->makeController('Bdus\\Controllers\\Record', ['tb' => 'items', 'sort' => 'status,-id']);
        $ctrl->setCfg($cfg);
        $this->assertSame([5, 3, 1, 2, 4], $this->ids($this->callController($ctrl, 'getRecords')));
    }

    public function testSortAppliesToTheIdsOnlyListToo(): void
    {
        $res = $this->list(['sort' => '-id', 'ids_only' => 1]);

        $this->assertSame([5, 4, 3, 2, 1], $res['ids']);
    }

    // ── search ────────────────────────────────────────────────────────────────

    public function testSearchAloneIsAFastSearch(): void
    {
        $this->assertSame([1], $this->ids($this->list(['search' => 'Alpha'])));
    }

    public function testSearchIsIgnoredWhenTheTypeSaysSo(): void
    {
        // what the app sends for "no search": nothing may narrow the list
        $this->assertCount(5, $this->list(['search_type' => 'all', 'search' => 'Alpha'])['data']);
    }

    // ── meta ──────────────────────────────────────────────────────────────────

    public function testMetaIsAbsentUnlessAsked(): void
    {
        $this->assertArrayNotHasKey('meta', $this->list());
    }

    public function testMetaCountsTheTableAndTheFilter(): void
    {
        $res = $this->list([
            'filter' => ['status' => ['_eq' => 'active']],
            'meta'   => 'total_count,filter_count',
        ]);

        $this->assertSame(['total_count' => 5, 'filter_count' => 3], $res['meta']);
        $this->assertSame(3, $res['total'], 'total keeps meaning what it meant');
    }

    public function testMetaWildcardAndSingleValue(): void
    {
        $this->assertSame(['filter_count' => 5], $this->list(['meta' => 'filter_count'])['meta']);
        $this->assertSame(['total_count' => 5, 'filter_count' => 5], $this->list(['meta' => '*'])['meta']);
        $this->assertArrayNotHasKey('meta', $this->list(['meta' => 'bogus']));
    }

    public function testTotalCountOnlyCountsTheRowsTheGroupMayRead(): void
    {
        $this->group = (int) static::$db->query("INSERT INTO bdus_groups (name) VALUES ('Reviewers')", [], 'id');
        static::$db->exec("INSERT INTO bdus_users (id, name, email, password, privilege) VALUES (51, 'rev', 'rev@example.com', 'x', 39)");
        static::$db->exec("INSERT INTO bdus_user_groups (user_id, group_id) VALUES (51, {$this->group})");
        static::$db->query(
            'INSERT INTO bdus_group_rules (group_id, table_name, action, filter) VALUES (?, ?, ?, ?)',
            [$this->group, 'items', 'read', json_encode(['status' => ['_eq' => 'active']])],
            'boolean'
        );
        \Auth\CurrentUser::set(['id' => 51, 'name' => 'rev', 'email' => 'rev@example.com', 'privilege' => 39, 'app' => 'test']);

        $res = $this->list(['meta' => 'total_count']);

        $this->assertSame(3, $res['meta']['total_count'], 'inactive and pending rows must not be counted');
    }
}
