<?php

namespace Tests\Integration;

use Tests\Support\BdusTestCase;
use Mcp\Context;
use Mcp\Server;

/**
 * Every MCP tool must show exactly what the REST API shows to the same principal
 * (decision D5: no "hidden from MCP" list, no second permission model). The
 * principal here is the one of AccessReadEnforcementTest: tier 39 (Enter) plus a
 * group that may read only `items` with status = active, i.e. ids 1, 3, 5.
 * Items 2 and 4 (inactive, pending) must be as if they did not exist; `categories`,
 * `reviews`, `tags` (plugin of items) have no rule of their own.
 */
class McpReadParityTest extends BdusTestCase
{
    private const VISIBLE = [1, 3, 5];

    private int $group = 0;

    protected function setUp(): void
    {
        $g = $this->group = (int) static::$db->query("INSERT INTO bdus_groups (name) VALUES ('Reviewers')", [], 'id');
        static::$db->query(
            "INSERT INTO bdus_group_rules (group_id, table_name, action, filter) VALUES (?, 'items', 'read', ?)",
            [$g, json_encode(['status' => ['_eq' => 'active']])],
            'boolean'
        );
        static::$db->exec("INSERT INTO bdus_users (id, name, email, password, privilege) VALUES (50, 'rev', 'rev@example.com', 'x', 39)");
        static::$db->exec("INSERT INTO bdus_user_groups (user_id, group_id) VALUES (50, {$g})");
        $this->actAs(39, 50);
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

    private function tool(string $name, array $args): array
    {
        \Auth\Authorization::setAccessProvider(fn() => \Auth\Access::forCurrentUser(static::$db, static::$cfg));
        $server = new Server(new Context(
            static::$db,
            static::$cfg,
            \Auth\Access::forCurrentUser(static::$db, static::$cfg),
            ['enabled' => true, 'write' => false, 'delete' => false, 'guide' => ''],
            static::$log
        ));
        [, $body] = $server->handle(json_encode([
            'jsonrpc' => '2.0', 'id' => 1, 'method' => 'tools/call',
            'params'  => ['name' => $name, 'arguments' => $args],
        ]));
        $this->assertArrayHasKey('result', $body, json_encode($body));
        return $body['result']['structuredContent'] + ['__isError' => $body['result']['isError']];
    }

    private function rest(array $get = [], array $post = []): array
    {
        $ctrl = $this->makeController('Bdus\\Controllers\\Record', array_merge(['tb' => 'items', 'per_page' => 50], $get), $post);
        return $this->callController($ctrl, 'getRecords');
    }

    private function ids(array $rows): array
    {
        $ids = array_map('intval', array_column($rows, 'id'));
        sort($ids);
        return $ids;
    }

    // ── records_read ──────────────────────────────────────────────────────────

    public function testRecordsReadShowsTheSameRowsAsTheRestList(): void
    {
        $mcp  = $this->tool('records_read', ['table' => 'items']);
        $rest = $this->rest();

        $this->assertSame(self::VISIBLE, $this->ids($mcp['rows']));
        $this->assertSame($this->ids($rest['data']), $this->ids($mcp['rows']));
        $this->assertSame(3, $mcp['total']);
        $this->assertSame($rest['total'], $mcp['total']);
    }

    public function testHiddenRowsCannotBeFoundByFilterSearchOrIdLookup(): void
    {
        $inactive = $this->tool('records_read', ['table' => 'items', 'filter' => ['status' => ['_eq' => 'inactive']]]);
        $this->assertSame([], $inactive['rows']);
        $this->assertSame(0, $inactive['total']);

        // an OR that includes the hidden rows is still cut by the rule
        $either = $this->tool('records_read', ['table' => 'items', 'filter' => ['_or' => [
            ['status' => ['_eq' => 'active']], ['status' => ['_eq' => 'inactive']],
        ]]]);
        $this->assertSame(self::VISIBLE, $this->ids($either['rows']));

        $this->assertSame([], $this->tool('records_read', ['table' => 'items', 'search' => 'Beta'])['rows']);
        $this->assertSame([1], $this->ids($this->tool('records_read', ['table' => 'items', 'search' => 'Alpha'])['rows']));

        $this->assertSame([], $this->tool('records_read', ['table' => 'items', 'filter' => ['id' => ['_eq' => 2]]])['rows']);
    }

    public function testNestedRowsRespectTheRulesOfTheirOwnTable(): void
    {
        // Item 1 has two tags (plugin rows: follow their host), a category (lookup)
        // and two reviews (rows that point at it). Only the plugin rows may come back:
        // `categories` and `reviews` have no rule for this group.
        $res = $this->tool('records_read', [
            'table'  => 'items',
            'filter' => ['id' => ['_eq' => 1]],
            'fields' => ['name', 'tags.*', 'cat_ref.*', 'reviews.*'],
        ]);
        $this->assertFalse($res['__isError'], json_encode($res));
        $row = $res['rows'][0];

        $this->assertCount(2, $row['tags'], json_encode($row));
        // `cat_ref` stays the stored id of the record's own column; the lookup row itself
        // ('Ceramics') and the reviews ('alice', 'bob') must not come with it.
        $this->assertFalse(is_array($row['cat_ref'] ?? null), json_encode($row['cat_ref'] ?? null));
        $json = json_encode($row);
        foreach (['Ceramics', 'alice', 'bob', 'Excellent'] as $secret) {
            $this->assertStringNotContainsString($secret, $json);
        }
        $this->assertEmpty($row['reviews'] ?? null, json_encode($row['reviews'] ?? null));
    }

    public function testTablesWithoutARuleDoNotExistForEveryTool(): void
    {
        $calls = [
            ['records_read', ['table' => 'reviews']],
            ['records_read', ['table' => 'categories']],
            ['aggregate', ['table' => 'reviews']],
            ['resolve', ['table' => 'categories', 'values' => ['x']]],
            ['history', ['table' => 'reviews', 'id' => 1]],
            ['vocabulary', ['table' => 'reviews', 'field' => 'reviewer']],
            ['schema', ['table' => 'reviews']],
            ['records_validate', ['table' => 'reviews', 'records' => [['core' => ['reviewer' => 'x']]]]],
        ];
        foreach ($calls as [$tool, $args]) {
            $res = $this->tool($tool, $args);
            $this->assertTrue($res['__isError'], $tool);
            $this->assertSame('unknown_table', $res['code'], $tool);
        }
    }

    public function testPluginTablesAreNeverASubject(): void
    {
        // `tags` is readable through its host, but only nested in it.
        foreach ([
            ['records_read', ['table' => 'tags']],
            ['aggregate', ['table' => 'tags']],
            ['resolve', ['table' => 'tags', 'values' => ['tag-a']]],
            ['history', ['table' => 'tags', 'id' => 1]],
            ['records_validate', ['table' => 'tags', 'records' => [['core' => ['label' => 'x', 'id_link' => 1]]]]],
        ] as [$tool, $args]) {
            $res = $this->tool($tool, $args);
            $this->assertSame('unknown_table', $res['code'] ?? null, $tool . ' ' . json_encode($res));
        }
    }

    // ── aggregate, resolve, history, vocabulary, schema ───────────────────────

    public function testAggregateCountsOnlyReadableRows(): void
    {
        $res = $this->tool('aggregate', ['table' => 'items', 'group_by' => ['status']]);
        $this->assertFalse($res['__isError'], json_encode($res));

        $byStatus = array_column($res['groups'], 'count', 'status');
        $this->assertSame(['active' => 3], $byStatus, json_encode($res['groups']));

        $hidden = $this->tool('aggregate', ['table' => 'items', 'filter' => ['status' => ['_eq' => 'inactive']]]);
        $this->assertSame(0, (int) ($hidden['groups'][0]['count'] ?? 0));
    }

    public function testResolveCannotRevealAHiddenRecord(): void
    {
        // The key field of `items` is `id`: 2 is hidden, 1 is readable.
        $res = $this->tool('resolve', ['table' => 'items', 'values' => [2, 1]]);

        $this->assertFalse($res['__isError'], json_encode($res));
        $this->assertSame('id', $res['key_field']);
        $this->assertSame(0, $res['results'][0]['count']);
        $this->assertSame([], $res['results'][0]['matches']);
        $this->assertSame(1, $res['results'][1]['count']);
    }

    public function testHistoryFollowsTheRowRule(): void
    {
        $hidden = $this->tool('history', ['table' => 'items', 'id' => 2]);
        $this->assertSame('not_enough_privilege', $hidden['code'] ?? null);

        $visible = $this->tool('history', ['table' => 'items', 'id' => 1]);
        $this->assertFalse($visible['__isError'], json_encode($visible));
    }

    public function testSchemaListsOnlyWhatTheGroupCanRead(): void
    {
        $res    = $this->tool('schema', []);
        $tables = array_column($res['tables'], 'table');

        $this->assertSame(['items'], $tables);
    }

    public function testVocabularyOfALookupDoesNotListRowsTheGroupCannotRead(): void
    {
        // items.cat_ref takes its values from `categories`, which this group cannot read.
        $res = $this->tool('vocabulary', ['table' => 'items', 'field' => 'cat_ref']);

        $this->assertFalse($res['__isError'], json_encode($res));
        $this->assertSame([], $res['values']);
    }

    // ── A principal with no rule at all ───────────────────────────────────────

    public function testAGroupKeySeesWhatTheGroupSeesAndNothingElse(): void
    {
        // An API key has no user: its group travels with it.
        \Auth\CurrentUser::set(['id' => 0, 'key_id' => 9, 'privilege' => 39, 'is_api_key' => true, 'group_id' => $this->group, 'name' => 'key']);

        $mcp  = $this->tool('records_read', ['table' => 'items']);
        $rest = $this->rest();
        $this->assertSame(self::VISIBLE, $this->ids($mcp['rows']));
        $this->assertSame($this->ids($rest['data']), $this->ids($mcp['rows']));

        $this->assertSame(['items'], array_column($this->tool('schema', [])['tables'], 'table'));
        $this->assertSame('not_enough_privilege', $this->tool('history', ['table' => 'items', 'id' => 2])['code'] ?? null);
        $this->assertSame('unknown_table', $this->tool('records_read', ['table' => 'reviews'])['code']);
    }

    public function testAKeyWithoutAGroupAtTierEnterSeesNothing(): void
    {
        \Auth\CurrentUser::set(['id' => 0, 'key_id' => 9, 'privilege' => 39, 'is_api_key' => true, 'name' => 'key']);

        $this->assertSame([], $this->tool('schema', [])['tables']);
        $this->assertSame('unknown_table', $this->tool('records_read', ['table' => 'items'])['code']);
    }

    public function testEnterTierWithoutAGroupSeesNothing(): void
    {
        static::$db->exec('DELETE FROM bdus_user_groups');

        $this->assertSame([], $this->tool('schema', [])['tables']);
        $this->assertSame('unknown_table', $this->tool('records_read', ['table' => 'items'])['code']);
    }

    public function testAReaderSeesEveryTableThroughTheSameRowsAsRest(): void
    {
        $this->actAs(30, 50);
        static::$db->exec('DELETE FROM bdus_user_groups');

        $mcp  = $this->tool('records_read', ['table' => 'items']);
        $rest = $this->rest();

        $this->assertSame(5, $mcp['total']);
        $this->assertSame($this->ids($rest['data']), $this->ids($mcp['rows']));
        $this->assertContains('items', array_column($this->tool('schema', [])['tables'], 'table'));
    }
}
