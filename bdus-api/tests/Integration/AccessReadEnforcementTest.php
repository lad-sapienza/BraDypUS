<?php

namespace Tests\Integration;

use Tests\Support\BdusTestCase;

/**
 * Read-side enforcement of group rules (#78): a scoped principal (tier 39 plus a
 * group allowed to read only `items` with status = active, i.e. ids 1, 3, 5)
 * must see the same rows through every list-like entry point, and nothing
 * through a table it has no rule for.
 */
class AccessReadEnforcementTest extends BdusTestCase
{
    private const ENTER   = 39;
    private const VISIBLE = [1, 3, 5];

    protected function setUp(): void
    {
        $g = (int) static::$db->query("INSERT INTO bdus_groups (name) VALUES ('Reviewers')", [], 'id');
        static::$db->query(
            "INSERT INTO bdus_group_rules (group_id, table_name, action, filter) VALUES (?, 'items', 'read', ?)",
            [$g, json_encode(['status' => ['_eq' => 'active']])],
            'boolean'
        );
        static::$db->exec("INSERT INTO bdus_users (id, name, email, password, privilege) VALUES (50, 'rev', 'rev@example.com', 'x', 39)");
        static::$db->exec("INSERT INTO bdus_user_groups (user_id, group_id) VALUES (50, {$g})");
        $this->actAs(self::ENTER, 50);
    }

    protected function tearDown(): void
    {
        static::$db->exec('DELETE FROM bdus_user_groups');
        static::$db->exec('DELETE FROM bdus_group_rules');
        static::$db->exec('DELETE FROM bdus_groups');
        static::$db->exec('DELETE FROM bdus_users WHERE id = 50');
        $this->setPrivilege(1);
    }

    private function actAs(int $tier, int $id): void
    {
        \Auth\CurrentUser::set(['id' => $id, 'name' => 'rev', 'email' => 'rev@example.com', 'privilege' => $tier, 'app' => 'test']);
    }

    /** getRecords with only what the caller passes (plus tb and page size). */
    private function list(array $get = [], array $post = []): array
    {
        $ctrl = $this->makeController(
            'Bdus\\Controllers\\Record',
            array_merge(['tb' => 'items', 'per_page' => 50], $get),
            $post
        );
        return $this->callController($ctrl, 'getRecords');
    }

    private function ids(array $res): array
    {
        $ids = array_map('intval', array_column($res['data'], 'id'));
        sort($ids);
        return $ids;
    }

    // ── Lists and search ──────────────────────────────────────────────────────

    public function testListShowsOnlyReadableRowsAndCountsThem(): void
    {
        $res = $this->list();

        $this->assertSame(self::VISIBLE, $this->ids($res));
        $this->assertSame(3, $res['total']);
    }

    public function testFastSearchCannotReachHiddenRows(): void
    {
        // 'Beta item' (id 2) is inactive: not readable.
        $this->assertSame([], $this->ids($this->list(['search_type' => 'fast', 'search' => 'Beta'])));
        $this->assertSame([1], $this->ids($this->list(['search_type' => 'fast', 'search' => 'Alpha'])));
    }

    public function testFiltersCannotWidenTheScope(): void
    {
        $inactive = $this->list(['search_type' => 'filter', 'filter' => json_encode(['status' => ['_eq' => 'inactive']])]);
        $this->assertSame([], $this->ids($inactive));

        // An OR that includes the hidden rows must still be cut by the rule.
        $either = $this->list([], [
            'search_type' => 'filter',
            'filter'      => ['_or' => [['status' => ['_eq' => 'active']], ['status' => ['_eq' => 'inactive']]]],
        ]);
        $this->assertSame(self::VISIBLE, $this->ids($either));
    }

    public function testSqlExpertIsStillAdminOnly(): void
    {
        $res = $this->list([], ['search_type' => 'sqlExpert', 'querytext' => '1=1', 'join' => '']);

        $this->assertSame('error', $res['status']);
        $this->assertStringContainsString('not_enough_privilege', $res['detail']);
        $this->assertArrayNotHasKey('data', $res);
    }

    public function testNoRuleMeansNoAccessToOtherTables(): void
    {
        $ctrl = $this->makeController('Bdus\\Controllers\\Record', ['tb' => 'tags', 'search_type' => 'all']);
        $res  = $this->callController($ctrl, 'getRecords');

        $this->assertSame('not_enough_privilege', $res['code']);
    }

    // ── Export ────────────────────────────────────────────────────────────────

    public function testExportContainsOnlyReadableRows(): void
    {
        $ctrl = $this->makeController('Bdus\\Controllers\\Record', ['tb' => 'items', 'format' => 'csv']);
        ob_start();
        $ctrl->exportRecords();
        $out = ob_get_clean();

        $this->assertStringContainsString('Alpha item', $out);
        $this->assertStringNotContainsString('Beta item', $out);
        $this->assertStringNotContainsString('Delta thing', $out);
    }

    public function testExportOfAnotherTableIsRefused(): void
    {
        $ctrl = $this->makeController('Bdus\\Controllers\\Record', ['tb' => 'tags', 'format' => 'csv']);
        $res  = $this->callController($ctrl, 'exportRecords');

        $this->assertSame('not_enough_privilege', $res['code']);
    }

    // ── Charts ────────────────────────────────────────────────────────────────

    public function testChartAggregatesOnlyReadableRows(): void
    {
        $ctrl = $this->makeController('Bdus\\Controllers\\Chart', [], [
            'definition' => ['tb' => 'items', 'type' => 'metric', 'field' => 'id', 'function' => 'COUNT'],
        ]);
        $res = $this->callController($ctrl, 'getData');

        $this->assertSame('success', $res['status']);
        $this->assertEquals(3, (int) $res['value']);
    }

    public function testChartOnAnotherTableIsRefused(): void
    {
        $ctrl = $this->makeController('Bdus\\Controllers\\Chart', [], [
            'definition' => ['tb' => 'tags', 'type' => 'metric', 'field' => 'id', 'function' => 'COUNT'],
        ]);
        $res = $this->callController($ctrl, 'getData');

        $this->assertSame('not_enough_privilege', $res['code']);
    }

    public function testChartWithoutAnyPrivilegeIsRefused(): void
    {
        $this->actAs(40, 50);
        $ctrl = $this->makeController('Bdus\\Controllers\\Chart', [], [
            'definition' => ['tb' => 'items', 'type' => 'metric', 'field' => 'id', 'function' => 'COUNT'],
        ]);

        $this->assertSame('not_enough_privilege', $this->callController($ctrl, 'getData')['code']);
    }

    // ── GeoFace ───────────────────────────────────────────────────────────────

    public function testGeoJsonContainsOnlyReadableRows(): void
    {
        static::$db->exec("INSERT INTO bdus_geodata (table_link, id_link, geometry) VALUES ('items', 1, 'POINT(1 1)'), ('items', 2, 'POINT(2 2)')");
        try {
            $ctrl = $this->makeController('Bdus\\Controllers\\Geoface', ['tb' => 'items']);
            $res  = $this->callController($ctrl, 'getGeoJson');

            $this->assertSame('success', $res['status']);
            $linked = [];
            foreach ($res['geojson']['features'] as $f) {
                $linked[] = (int) ($f['properties']['id'] ?? $f['properties']['id_link'] ?? 0);
            }
            $this->assertNotContains(2, $linked);
            $this->assertContains(1, $linked);
        } finally {
            static::$db->exec("DELETE FROM bdus_geodata WHERE geometry IN ('POINT(1 1)', 'POINT(2 2)')");
        }
    }

    public function testGeoJsonOfAnotherTableIsRefused(): void
    {
        $ctrl = $this->makeController('Bdus\\Controllers\\Geoface', ['tb' => 'tags']);

        $this->assertSame('not_enough_privilege', $this->callController($ctrl, 'getGeoJson')['code']);
    }

    // ── Assemblage analysis ───────────────────────────────────────────────────

    public function testAssemblageCountsOnlyReadableRows(): void
    {
        $ctrl = $this->makeController('Bdus\\Controllers\\AssemblageAnalysis', [], [
            'definition' => [
                'source_tb' => 'items', 'char_field' => 'status', 'group_field' => 'lang_code', 'measure' => 'count',
            ],
        ]);
        $res = $this->callController($ctrl, 'getData');

        $this->assertSame('success', $res['status']);
        $total = 0;
        foreach ($res['data'] as $byChar) {
            $total += array_sum($byChar);
        }
        $this->assertEquals(3, $total);
        $this->assertNotContains('inactive', $res['chars']);
    }

    public function testAssemblageOnAnotherTableIsRefused(): void
    {
        $ctrl = $this->makeController('Bdus\\Controllers\\AssemblageAnalysis', [], [
            'definition' => ['source_tb' => 'tags', 'char_field' => 'name', 'group_field' => 'name', 'measure' => 'count'],
        ]);

        $this->assertSame('not_enough_privilege', $this->callController($ctrl, 'getData')['code']);
    }

    // ── Principals that must not be affected ──────────────────────────────────

    public function testAdminsAndReadersSeeEverything(): void
    {
        foreach ([1, 10, 30] as $tier) {
            $this->actAs($tier, 1);
            $res = $this->list();
            $this->assertSame([1, 2, 3, 4, 5], $this->ids($res), "tier $tier");
            $this->assertSame(5, $res['total']);
        }
    }

    public function testReaderWithAGroupStillSeesEveryRow(): void
    {
        // Rules only add; a reader's tier already grants read on all rows.
        $this->actAs(30, 50);

        $this->assertSame([1, 2, 3, 4, 5], $this->ids($this->list()));
    }
}
