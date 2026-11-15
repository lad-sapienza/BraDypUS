<?php

namespace Tests\Integration;

use Tests\Support\BdusTestCase;
use Mcp\Context;
use Mcp\Server;

/**
 * What MCP writes may and may not do for principals other than a super admin:
 * locks, group rules and row scope, self writers, values pinned by a rule, and
 * the plugin rows that travel with a record. Same fixture and the same set-up as
 * AccessWriteEnforcementTest (the REST side of these rules): MCP must reach the
 * same verdicts.
 *
 * Fixture items 1-5: active, inactive, active, pending, active. Lock on
 * `items` with status = inactive (item 2 is frozen). Principal: user 52.
 * Item 1 has two tag rows (plugin of items).
 */
class McpWriteRulesTest extends BdusTestCase
{
    protected function setUp(): void
    {
        static::$db->exec("INSERT INTO bdus_users (id, name, email, password, privilege) VALUES (52, 'w', 'w@example.com', 'x', 20)");
        static::$db->query(
            "INSERT INTO bdus_table_locks (table_name, filter) VALUES ('items', ?)",
            [json_encode(['status' => ['_eq' => 'inactive']])],
            'boolean'
        );
        $this->actAs(20);
        \Auth\Channel::set('mcp');
    }

    protected function tearDown(): void
    {
        \Auth\Channel::set(null);
        static::$db->exec('DELETE FROM bdus_user_groups');
        static::$db->exec('DELETE FROM bdus_group_rules');
        static::$db->exec('DELETE FROM bdus_groups');
        static::$db->exec('DELETE FROM bdus_table_locks');
        static::$db->exec('DELETE FROM bdus_users WHERE id = 52');
        static::$db->exec('DELETE FROM items WHERE id > 5');
        static::$db->exec('DELETE FROM tags');
        static::$db->exec("INSERT INTO tags (id, label, id_link, cat_ref) VALUES (1, 'tag-a', 1, 1), (2, 'tag-b', 1, NULL)");
        static::$db->exec("UPDATE items SET status = CASE id WHEN 1 THEN 'active' WHEN 2 THEN 'inactive' WHEN 3 THEN 'active' WHEN 4 THEN 'pending' ELSE 'active' END, description = name, creator = 'admin'");
        $this->setPrivilege(1);
    }

    // ── Helpers ───────────────────────────────────────────────────────────────

    private function actAs(int $tier, int $id = 52): void
    {
        \Auth\CurrentUser::set(['id' => $id, 'name' => 'w', 'email' => 'w@example.com', 'privilege' => $tier, 'app' => 'test']);
    }

    /** Puts the principal in a group with the rules [table, action, ?filter, ?enforce]. */
    private function inGroup(array $rules): void
    {
        $g = (int) static::$db->query("INSERT INTO bdus_groups (name) VALUES ('G')", [], 'id');
        static::$db->exec("INSERT INTO bdus_user_groups (user_id, group_id) VALUES (52, {$g})");
        foreach ($rules as $rule) {
            [$table, $action, $filter] = $rule;
            $enforce = $rule[3] ?? null;
            static::$db->query(
                'INSERT INTO bdus_group_rules (group_id, table_name, action, filter, enforce) VALUES (?, ?, ?, ?, ?)',
                [$g, $table, $action, $filter === null ? null : json_encode($filter), $enforce === null ? null : json_encode($enforce)],
                'boolean'
            );
        }
    }

    private function tool(string $name, array $args): array
    {
        \Auth\Authorization::setAccessProvider(fn() => \Auth\Access::forCurrentUser(static::$db, static::$cfg));
        $server = new Server(new Context(
            static::$db,
            static::$cfg,
            \Auth\Access::forCurrentUser(static::$db, static::$cfg),
            ['enabled' => true, 'write' => true, 'delete' => true, 'guide' => ''],
            static::$log,
            'test-signing-key'
        ));
        [, $body] = $server->handle(json_encode([
            'jsonrpc' => '2.0', 'id' => 1, 'method' => 'tools/call',
            'params'  => ['name' => $name, 'arguments' => $args],
        ]));
        $this->assertArrayHasKey('result', $body, json_encode($body));
        return $body['result']['structuredContent'] + ['__isError' => $body['result']['isError']];
    }

    /** What a well-behaved client does: validate, then write with the token. Returns [validation, write|null]. */
    private function change(array $records, string $tb = 'items'): array
    {
        $check = $this->tool('records_validate', ['table' => $tb, 'records' => $records]);
        if (empty($check['valid'])) {
            return [$check, null];
        }
        return [$check, $this->tool('records_write', ['table' => $tb, 'records' => $records, 'validation_token' => $check['validation_token']])];
    }

    private function update(int $id, array $core = ['description' => 'changed'], array $plugins = []): array
    {
        return $this->change([['id' => $id, 'core' => $core, 'plugins' => $plugins]]);
    }

    private function assertDone(array $res): void
    {
        $this->assertNotNull($res[1], 'refused at validation: ' . json_encode($res[0]));
        $this->assertFalse($res[1]['__isError'], json_encode($res[1]));
    }

    private function assertDenied(array $res): void
    {
        $this->assertNull($res[1], 'a refused record must not reach records_write');
        $this->assertFalse($res[0]['valid']);
        $this->assertNull($res[0]['validation_token']);
        $this->assertSame('not_enough_privilege', $res[0]['results'][0]['code'] ?? null, json_encode($res[0]));
    }

    /** A throw-away item (ids above 5 are removed in tearDown): tests that delete must never use items 1-5. */
    private function newItem(string $status = 'active', string $creator = 'admin'): int
    {
        return (int) static::$db->query(
            "INSERT INTO items (creator, name, status) VALUES (?, 'tmp', ?)",
            [$creator, $status],
            'id'
        );
    }

    private function field(int $id, string $fld = 'description'): ?string
    {
        return static::$db->query("SELECT {$fld} AS v FROM items WHERE id = ?", [$id], 'read')[0]['v'] ?? null;
    }

    private function rows(string $sql, array $p = []): int
    {
        return (int) static::$db->query($sql, $p, 'read')[0]['n'];
    }

    // ── Tier, locks ───────────────────────────────────────────────────────────

    public function testWriterChangesAFreeRecordButNotAFrozenOne(): void
    {
        $this->assertDone($this->update(1));
        $this->assertSame('changed', $this->field(1));

        $this->assertDenied($this->update(2));
        $this->assertNotSame('changed', $this->field(2));
    }

    public function testAdminsChangeFrozenRecords(): void
    {
        foreach ([10, 1] as $tier) {
            $this->actAs($tier, 1);
            $this->assertDone($this->update(2, ['description' => "by {$tier}"]));
            $this->assertSame("by {$tier}", $this->field(2));
        }
    }

    public function testAFrozenRecordGetsNoPluginRowsThroughMcp(): void
    {
        $res = $this->update(2, [], ['tags' => [['_isNew' => true, 'fields' => ['label' => 'new-x']]]]);

        $this->assertDenied($res);
        $this->assertSame(0, $this->rows("SELECT COUNT(*) AS n FROM tags WHERE label = 'new-x'"));
    }

    public function testAFrozenRecordCannotBeDeleted(): void
    {
        $res = $this->tool('records_delete', ['table' => 'items', 'ids' => [3, 2]]);

        $this->assertSame('delete_refused', $res['code']);
        $this->assertSame(2, $res['detail']['id']);
        $this->assertSame('not_enough_privilege', $res['detail']['code']);
        $this->assertSame(1, $this->rows('SELECT COUNT(*) AS n FROM items WHERE id = 3'), 'all or nothing: item 3 is still there');
    }

    public function testLockedRecordIsDeletableByAnAdmin(): void
    {
        $frozen = $this->newItem('inactive');
        $this->actAs(10, 1);
        $res = $this->tool('records_delete', ['table' => 'items', 'ids' => [$frozen]]);
        $this->assertFalse($res['__isError'], json_encode($res));
        $this->assertSame(0, $this->rows('SELECT COUNT(*) AS n FROM items WHERE id = ?', [$frozen]));
    }

    // ── Self writer ───────────────────────────────────────────────────────────

    public function testSelfWriterChangesOnlyOwnUnfrozenRecords(): void
    {
        static::$db->exec("UPDATE items SET creator = '52' WHERE id IN (1, 2)");
        $this->actAs(25);

        $this->assertDone($this->update(1));
        $this->assertDenied($this->update(2));   // own, but frozen
        $this->assertDenied($this->update(3));   // free, but not theirs
    }

    public function testSelfWriterDeletesOnlyOwnRecords(): void
    {
        $own = $this->newItem('active', '52');
        $this->actAs(25);

        $refused = $this->tool('records_delete', ['table' => 'items', 'ids' => [3]]);
        $this->assertSame('delete_refused', $refused['code']);
        $this->assertSame(1, $this->rows('SELECT COUNT(*) AS n FROM items WHERE id = 3'));

        $done = $this->tool('records_delete', ['table' => 'items', 'ids' => [$own]]);
        $this->assertFalse($done['__isError'], json_encode($done));
        $this->assertSame(0, $this->rows('SELECT COUNT(*) AS n FROM items WHERE id = ?', [$own]));
    }

    public function testClientCannotReassignTheCreatorOrTheIdOfARecord(): void
    {
        static::$db->exec("UPDATE items SET creator = '52' WHERE id = 1");
        $this->actAs(25);

        $this->assertDone($this->update(1, ['description' => 'mine', 'creator' => '7', 'id' => 99]));

        $this->assertSame('mine', $this->field(1));
        $this->assertSame('52', $this->field(1, 'creator'), 'creator is not a client field');
        $this->assertSame(0, $this->rows('SELECT COUNT(*) AS n FROM items WHERE id = 99'));
        $this->assertSame(1, $this->rows('SELECT COUNT(*) AS n FROM items WHERE id = 1'));
    }

    // ── Group rules ───────────────────────────────────────────────────────────

    public function testGroupRuleScopesUpdateToItsRows(): void
    {
        $this->actAs(39);
        $this->inGroup([
            ['items', 'read', null],
            ['items', 'update', ['status' => ['_in' => ['active', 'pending']]]],
        ]);

        $this->assertDone($this->update(1));
        $this->assertDone($this->update(4));    // pending: inside the rule
        $this->assertDenied($this->update(2));  // inactive: outside, and frozen
        $this->assertNotSame('changed', $this->field(2));
    }

    public function testNoRuleMeansNoWriteAndNoDelete(): void
    {
        $this->actAs(39);
        $this->inGroup([['items', 'read', null]]);

        $this->assertDenied($this->update(1));
        $this->assertDenied($this->change([['core' => ['name' => 'created without a rule']]]));
        $res = $this->tool('records_delete', ['table' => 'items', 'ids' => [1]]);
        $this->assertSame('not_enough_privilege', $res['code']);
    }

    public function testGroupRulesDecideCreateAndDeleteSeparately(): void
    {
        $this->actAs(39);
        $this->inGroup([
            ['items', 'read', null],
            ['items', 'create', null],
        ]);

        $created = $this->change([['core' => ['name' => 'by group', 'status' => 'active']]]);
        $this->assertDone($created);
        $newId = $created[1]['results'][0]['id'];
        $this->assertSame(1, $this->rows('SELECT COUNT(*) AS n FROM items WHERE id = ?', [$newId]));

        $this->assertDenied($this->update($newId));   // a create rule is not an update rule
        $res = $this->tool('records_delete', ['table' => 'items', 'ids' => [$newId]]);   // nor a delete rule
        $this->assertSame('not_enough_privilege', $res['code']);
        $this->assertSame(1, $this->rows('SELECT COUNT(*) AS n FROM items WHERE id = ?', [$newId]));
    }

    public function testGroupDeleteRuleScopesWhatCanBeDeleted(): void
    {
        $this->actAs(39);
        $this->inGroup([
            ['items', 'read', null],
            ['items', 'update', null],
            ['items', 'delete', ['status' => ['_eq' => 'active']]],
        ]);
        $inside  = $this->newItem('active');
        $outside = $this->newItem('pending');

        $out = $this->tool('records_delete', ['table' => 'items', 'ids' => [$inside, $outside]]);
        $this->assertSame('delete_refused', $out['code']);
        $this->assertSame($outside, $out['detail']['id']);
        $this->assertSame(2, $this->rows('SELECT COUNT(*) AS n FROM items WHERE id IN (?, ?)', [$inside, $outside]), 'all or nothing');

        $in = $this->tool('records_delete', ['table' => 'items', 'ids' => [$inside]]);
        $this->assertFalse($in['__isError'], json_encode($in));
        $this->assertSame(0, $this->rows('SELECT COUNT(*) AS n FROM items WHERE id = ?', [$inside]));
    }

    public function testADeleteRuleAloneDoesNotDeleteOnRestEither(): void
    {
        // The delete endpoint asks for the update right first; MCP shares that gate.
        $this->actAs(39);
        $this->inGroup([['items', 'read', null], ['items', 'delete', null]]);
        $id = $this->newItem('active');

        $rest = $this->callController(
            $this->makeController('Bdus\\Controllers\\Record', ['tb' => 'items', 'id' => $id]),
            'erase'
        );
        $this->assertSame('not_enough_privilege', $rest['code'], json_encode($rest));
        $mcp = $this->tool('records_delete', ['table' => 'items', 'ids' => [$id]]);
        $this->assertSame('not_enough_privilege', $mcp['code']);
        $this->assertSame(1, $this->rows('SELECT COUNT(*) AS n FROM items WHERE id = ?', [$id]));
    }

    public function testAGroupKeyWritesWithinItsGroupAndLeavesItsTrace(): void
    {
        $this->inGroup([
            ['items', 'read', null],
            ['items', 'create', null],
            ['items', 'update', ['status' => ['_in' => ['active']]]],
        ]);
        $group = (int) static::$db->query('SELECT MAX(id) AS id FROM bdus_groups', [], 'read')[0]['id'];
        \Auth\CurrentUser::set(['id' => 0, 'key_id' => 9, 'privilege' => 39, 'is_api_key' => true, 'group_id' => $group, 'name' => 'key']);

        $this->assertDone($this->update(1));
        $this->assertDenied($this->update(4));   // pending: outside the rule
        $this->assertDenied($this->update(2));   // inactive: outside, and frozen

        $created = $this->change([['core' => ['name' => 'by a key', 'status' => 'active']]]);
        $this->assertDone($created);
        $id = $created[1]['results'][0]['id'];
        $this->assertNull(static::$db->query('SELECT creator FROM items WHERE id = ?', [$id], 'read')[0]['creator'], 'a key has no creator');

        $versions = static::$db->query("SELECT operation, channel, key_id FROM bdus_versions WHERE tb = 'items' AND rowid = ? ORDER BY id", [$id], 'read');
        $this->assertSame('create', $versions[0]['operation']);
        $this->assertSame('mcp', $versions[0]['channel']);
        $this->assertSame(9, (int) $versions[0]['key_id']);

        $this->assertSame('mcp', static::$db->query("SELECT channel FROM bdus_versions WHERE tb = 'items' AND rowid = 1 ORDER BY id DESC LIMIT 1", [], 'read')[0]['channel']);
    }

    public function testAValuePinnedByARuleIsFilledInAndEnforced(): void
    {
        $this->actAs(39);
        $this->inGroup([
            ['items', 'read', null],
            ['items', 'create', null, ['status' => ['draft']]],
        ]);

        // left out: filled in, and the dry run shows it
        [$check] = $this->change([['core' => ['name' => 'pinned']]]);
        $this->assertTrue($check['valid'], json_encode($check));
        $this->assertSame('draft', $check['results'][0]['core']['status']);

        // another value: refused, nothing written
        [$bad, $write] = $this->change([['core' => ['name' => 'not pinned', 'status' => 'active']]]);
        $this->assertNull($write);
        $this->assertFalse($bad['valid']);
        $this->assertSame('value_not_allowed', $bad['results'][0]['code']);
        $this->assertSame(0, $this->rows("SELECT COUNT(*) AS n FROM items WHERE name = 'not pinned'"));
    }

    public function testWritingNeedsToBeAbleToReadTheTable(): void
    {
        // The REST API lets this group update without reading; MCP is stricter on purpose:
        // a table the principal cannot read does not exist for any tool.
        $this->actAs(39);
        $this->inGroup([['items', 'update', null]]);

        $rest = $this->callController(
            $this->makeController('Bdus\\Controllers\\Record', [], ['tb' => 'items', 'id' => 1, 'core' => ['description' => 'rest']]),
            'saveRecord'
        );
        $this->assertSame('success', $rest['status'], json_encode($rest));

        $res = $this->tool('records_validate', ['table' => 'items', 'records' => [['id' => 1, 'core' => ['description' => 'mcp']]]]);
        $this->assertSame('unknown_table', $res['code']);
        $this->assertNotSame('mcp', $this->field(1));
    }

    // ── Plugin rows travel with their record ──────────────────────────────────

    public function testUpdateAddsAPluginRowAsTheToolDescribesIt(): void
    {
        // records_validate / records_write document `{ "fields": { … } }` as "add a row".
        $res = $this->update(3, [], ['tags' => [['fields' => ['label' => 'new-3']]]]);

        $this->assertDone($res);
        $this->assertSame(1, $this->rows("SELECT COUNT(*) AS n FROM tags WHERE label = 'new-3' AND id_link = 3"));
    }

    public function testUpdateChangesAndRemovesPluginRowsOfItsOwnRecord(): void
    {
        $this->assertDone($this->update(1, [], ['tags' => [
            ['id' => 1, 'fields' => ['label' => 'renamed']],
            ['id' => 2, '_delete' => true],
        ]]));

        $this->assertSame('renamed', static::$db->query('SELECT label FROM tags WHERE id = 1', [], 'read')[0]['label']);
        $this->assertSame(0, $this->rows('SELECT COUNT(*) AS n FROM tags WHERE id = 2'));
    }

    public function testAClientCannotTurnAChangeIntoAnInsertWithItsOwnFlag(): void
    {
        // `_isNew` is the tool's to set: a row that names an id changes that row.
        $this->assertDone($this->update(1, [], ['tags' => [['id' => 1, '_isNew' => true, 'fields' => ['label' => 'changed-not-added']]]]));

        $this->assertSame(2, $this->rows('SELECT COUNT(*) AS n FROM tags WHERE id_link = 1'));
        $this->assertSame('changed-not-added', static::$db->query('SELECT label FROM tags WHERE id = 1', [], 'read')[0]['label']);
    }

    public function testTheDryRunShowsThePluginRowAsItWillBeWritten(): void
    {
        [$check] = $this->update(3, [], ['tags' => [['fields' => ['label' => 'new-3']]]]);

        $this->assertTrue($check['results'][0]['plugins']['tags'][0]['_isNew']);
    }

    public function testAPluginRowOfAnotherRecordCannotBeTouched(): void
    {
        // Tag 1 and 2 belong to item 1. Item 3 is the record being changed.
        $res = $this->update(3, [], ['tags' => [
            ['id' => 1, 'fields' => ['label' => 'hijacked']],
            ['id' => 2, '_delete' => true],
        ]]);

        $this->assertSame('tag-a', static::$db->query('SELECT label FROM tags WHERE id = 1', [], 'read')[0]['label'], json_encode($res));
        $this->assertSame(1, $this->rows('SELECT COUNT(*) AS n FROM tags WHERE id = 2'), 'the other record keeps its rows');
    }

    public function testAPluginTableIsNotATargetOfAWrite(): void
    {
        $res = $this->tool('records_validate', ['table' => 'tags', 'records' => [['core' => ['label' => 'x', 'id_link' => 1]]]]);
        $this->assertSame('unknown_table', $res['code']);
    }
}
