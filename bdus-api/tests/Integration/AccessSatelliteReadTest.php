<?php

namespace Tests\Integration;

use Tests\Support\BdusTestCase;

/**
 * Single-record and satellite reads under group rules (#78). The reviewer is a
 * tier-39 user in a group that reads `items` with status = active (ids 1, 3, 5)
 * and nothing else, unless a test adds a rule.
 *
 * Fixture facts used: item 1 has a manual link to item 2 and an RS relation
 * 1→2 (item 2 is inactive, hence hidden); item 1 points to category 1 through
 * the lookup field cat_ref; tags are a plugin of items.
 */
class AccessSatelliteReadTest extends BdusTestCase
{
    private int $group;

    protected function setUp(): void
    {
        $this->group = (int) static::$db->query("INSERT INTO bdus_groups (name) VALUES ('Reviewers')", [], 'id');
        $this->rule('items', ['status' => ['_eq' => 'active']]);
        static::$db->exec("INSERT INTO bdus_users (id, name, email, password, privilege) VALUES (51, 'rev', 'rev@example.com', 'x', 39)");
        static::$db->exec("INSERT INTO bdus_user_groups (user_id, group_id) VALUES (51, {$this->group})");
        $this->actAs(39);
    }

    protected function tearDown(): void
    {
        static::$db->exec('DELETE FROM bdus_user_groups');
        static::$db->exec('DELETE FROM bdus_group_rules');
        static::$db->exec('DELETE FROM bdus_groups');
        static::$db->exec('DELETE FROM bdus_users WHERE id = 51');
        static::$db->exec("DELETE FROM bdus_userlinks WHERE id_two = 3");
        static::$db->exec("DELETE FROM bdus_versions WHERE tb = 'items'");
        $this->setPrivilege(1);
    }

    private function actAs(int $tier): void
    {
        \Auth\CurrentUser::set(['id' => 51, 'name' => 'rev', 'email' => 'rev@example.com', 'privilege' => $tier, 'app' => 'test']);
    }

    private function rule(string $table, ?array $filter = null, string $action = 'read'): void
    {
        static::$db->query(
            'INSERT INTO bdus_group_rules (group_id, table_name, action, filter) VALUES (?, ?, ?, ?)',
            [$this->group, $table, $action, $filter === null ? null : json_encode($filter)],
            'boolean'
        );
    }

    private function call(string $class, string $method, array $get = [], array $post = []): array
    {
        return $this->callController($this->makeController("Bdus\\Controllers\\{$class}", $get, $post), $method);
    }

    private function record(int $id): array
    {
        return $this->call('Record', 'getRecord', ['tb' => 'items', 'id' => $id]);
    }

    private function version(int $rowid): int
    {
        static::$db->query(
            "INSERT INTO bdus_versions (userid, time, tb, rowid, content, editsql, editvalues, operation)
             VALUES (1, ?, 'items', ?, '{\"core\":{},\"plugins\":{}}', '', '', 'update')",
            [time(), $rowid], 'boolean'
        );
        return (int) static::$db->query('SELECT MAX(id) AS m FROM bdus_versions', [], 'read')[0]['m'];
    }

    // ── getRecord ─────────────────────────────────────────────────────────────

    public function testReadableRecordIsReturned(): void
    {
        $res = $this->record(1);

        $this->assertSame('success', $res['status']);
        $this->assertEquals(1, $res['metadata']['rec_id']);
    }

    public function testRecordOutsideTheScopeIsRefusedLikeAForbiddenOne(): void
    {
        $this->assertSame('not_enough_privilege', $this->record(2)['code']);
    }

    public function testRecordOfATableWithoutARuleIsRefused(): void
    {
        $this->assertSame('not_enough_privilege', $this->call('Record', 'getRecord', ['tb' => 'tags', 'id' => 1])['code']);
    }

    public function testManualLinksToHiddenRecordsAreOmitted(): void
    {
        static::$db->exec("INSERT INTO bdus_userlinks (tb_one, id_one, tb_two, id_two, sort) VALUES ('items', 1, 'items', 3, 2)");

        $targets = array_map(fn($l) => (int) $l['ref_id'], array_values((array) $this->record(1)['manualLinks']));

        $this->assertSame([3], $targets); // 1→2 is hidden, 1→3 stays
    }

    public function testRsRelationsToHiddenNeighboursAreOmitted(): void
    {
        $this->assertSame([], (array) $this->record(1)['rs']);
    }

    public function testAdminStillSeesLinksAndRelations(): void
    {
        $this->setPrivilege(1);
        $res = $this->record(1);

        $this->assertNotEmpty((array) $res['manualLinks']);
        $this->assertNotEmpty((array) $res['rs']);
    }

    public function testLookupLabelNeedsReadAccessToTheReferencedTable(): void
    {
        $this->assertNull($this->record(1)['core']['cat_ref']['val_label']);

        $this->rule('categories');
        $this->assertSame('Ceramics', $this->record(1)['core']['cat_ref']['val_label']);
    }

    public function testPluginRowsFollowTheRecord(): void
    {
        $tags = $this->record(1)['plugins']['tags']['data'] ?? [];

        $this->assertCount(2, $tags);
    }

    // ── Field options and used values ─────────────────────────────────────────

    public function testLookupOptionsAreEmptyWithoutAccessAndRowFilteredWithIt(): void
    {
        $get = ['tb' => 'items', 'fld' => 'cat_ref'];
        $this->assertSame([], $this->call('Record', 'getFieldOptions', $get)['options']);

        $this->rule('categories', ['macro' => ['_eq' => 'Artefacts']]);
        $labels = array_column($this->call('Record', 'getFieldOptions', $get)['options'], 'label');
        sort($labels);

        $this->assertSame(['Ceramics', 'Metal'], $labels); // 'Charcoal' is an Ecofact
    }

    public function testUsedValuesComeFromReadableRowsOnly(): void
    {
        $res = $this->call('Search', 'getUsedValues', ['tb' => 'items', 'fld' => 'status']);

        $this->assertSame(['active'], $res['values']);
        $this->assertSame(
            'not_enough_privilege',
            $this->call('Search', 'getUsedValues', ['tb' => 'tags', 'fld' => 'label'])['code']
        );
    }

    // ── Link candidates ───────────────────────────────────────────────────────

    public function testLinkCandidatesAreRowFiltered(): void
    {
        $res = $this->call('Record', 'searchLinkCandidates', ['tb' => 'items']);
        $ids = array_map('intval', array_column($res['data'], 'id'));
        sort($ids);

        $this->assertSame([1, 3, 5], $ids);
        $this->assertSame(
            'not_enough_privilege',
            $this->call('Record', 'searchLinkCandidates', ['tb' => 'tags'])['code']
        );
    }

    // ── RS matrix ─────────────────────────────────────────────────────────────

    public function testRsMatrixHidesNodesAndRelationsOutsideTheScope(): void
    {
        $res = $this->call('Record', 'getRsMatrix', ['tb' => 'items']);

        $nodes = array_map(fn($n) => (int) $n['db_id'], $res['nodes']);
        sort($nodes);
        $this->assertSame([1, 3, 5], $nodes);
        $this->assertSame([], $res['relations']);
    }

    // ── History ───────────────────────────────────────────────────────────────

    public function testVersionsOfReadableRecordsOnly(): void
    {
        $this->version(1);
        $this->version(2);

        $this->assertCount(1, $this->call('Record', 'getVersions', ['tb' => 'items', 'id' => 1])['versions']);
        $this->assertSame('not_enough_privilege', $this->call('Record', 'getVersions', ['tb' => 'items', 'id' => 2])['code']);
    }

    public function testVersionDiffOfAHiddenRecordIsRefused(): void
    {
        $visible = $this->version(1);
        $hidden  = $this->version(2);

        $this->assertArrayHasKey('version', $this->call('Record', 'getVersionDiff', ['id' => $visible]));
        $this->assertSame('not_enough_privilege', $this->call('Record', 'getVersionDiff', ['id' => $hidden])['code']);
    }

    public function testDeletedRecordsNeedUnrestrictedReadOnTheTable(): void
    {
        $this->assertSame('not_enough_privilege', $this->call('Record', 'getDeletedRecords', ['tb' => 'items'])['code']);

        static::$db->exec('DELETE FROM bdus_group_rules');
        $this->rule('items'); // an unfiltered rule makes the principal's read unrestricted
        $this->assertArrayHasKey('deleted', $this->call('Record', 'getDeletedRecords', ['tb' => 'items']));
    }

    // ── Table lists and chrono ────────────────────────────────────────────────

    public function testListTablesShowsOnlyReadableTables(): void
    {
        $names = array_column($this->call('Home', 'listTables')['tables'], 'name');

        $this->assertSame(['items'], $names);
    }

    public function testEnterTierWithoutRulesListsNoTables(): void
    {
        static::$db->exec('DELETE FROM bdus_group_rules');

        $this->assertSame([], $this->call('Home', 'listTables')['tables']);
    }

    public function testWaitingUsersCannotEnter(): void
    {
        $this->actAs(40);

        $this->assertSame('not_enough_privilege', $this->call('Home', 'listTables')['code']);
    }

    public function testAssemblageSourcesAreFilteredToReadableTables(): void
    {
        $res = $this->call('AssemblageAnalysis', 'getSources');

        $this->assertSame(['items'], array_column($res['tables'], 'name'));
    }

    public function testChronoRequiresSomeReadableTable(): void
    {
        static::$db->exec('DELETE FROM bdus_group_rules');

        $this->assertSame('not_enough_privilege', $this->call('Chrono', 'timeline')['code']);
    }

    // ── Unchanged for everyone else ───────────────────────────────────────────

    public function testWritersAndAdminsAreUnaffected(): void
    {
        foreach ([1, 10, 20, 30] as $tier) {
            $this->actAs($tier);
            $this->assertSame('success', $this->record(2)['status'], "tier $tier");
            $this->assertCount(5, $this->call('Record', 'searchLinkCandidates', ['tb' => 'items'])['data'], "tier $tier");
            $this->assertCount(5, $this->call('Record', 'getRsMatrix', ['tb' => 'items'])['nodes'], "tier $tier");
        }
    }
}
