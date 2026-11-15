<?php

namespace Tests\Integration;

use Tests\Support\BdusTestCase;
use Mcp\Context;
use Mcp\Server;

/**
 * Stratigraphic relations through MCP: rs_read, rs_validate, rs_write (and the codes
 * `schema` teaches). Fixture: `items` has the rs plugin; seeded relation 1: item 1 is
 * covered by item 2 (row id 1). Items 1-5 are active, inactive, active, pending, active.
 */
class McpRsTest extends BdusTestCase
{
    protected function setUp(): void
    {
        static::$db->exec('DELETE FROM bdus_rs');
        static::$db->exec("INSERT INTO bdus_rs (id, tb, first, second, relation) VALUES (1, 'items', 1, 2, 1)");
        $this->setPrivilege(1);
        \Auth\Channel::set('mcp');
    }

    protected function tearDown(): void
    {
        \Auth\Channel::set(null);
        static::$db->exec('DELETE FROM bdus_user_groups');
        static::$db->exec('DELETE FROM bdus_group_rules');
        static::$db->exec('DELETE FROM bdus_groups');
        static::$db->exec('DELETE FROM bdus_table_locks');
        static::$db->exec('DELETE FROM bdus_users WHERE id IN (50, 52)');
        static::$db->exec('DELETE FROM bdus_rs');
        static::$db->exec("INSERT INTO bdus_rs (id, tb, first, second, relation) VALUES (1, 'items', 1, 2, 1)");
        $this->setPrivilege(1);
    }

    // ── Helpers ───────────────────────────────────────────────────────────────

    private function tool(string $name, array $args, array $settings = []): array
    {
        \Auth\Authorization::setAccessProvider(fn() => \Auth\Access::forCurrentUser(static::$db, static::$cfg));
        $server = new Server(new Context(
            static::$db,
            static::$cfg,
            \Auth\Access::forCurrentUser(static::$db, static::$cfg),
            $settings + ['enabled' => true, 'write' => true, 'delete' => true, 'guide' => ''],
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

    private function validate(array $payload): array
    {
        return $this->tool('rs_validate', ['table' => 'items'] + $payload);
    }

    /** Validate, then write with the token. Returns [validation, write|null]. */
    private function change(array $payload): array
    {
        $check = $this->validate($payload);
        if (empty($check['valid'])) {
            return [$check, null];
        }
        return [$check, $this->tool('rs_write', ['table' => 'items', 'validation_token' => $check['validation_token']] + $payload)];
    }

    private function add(int $first, int $relation, int $second): array
    {
        return ['first' => $first, 'relation' => $relation, 'second' => $second];
    }

    private function rows(string $where = '1=1', array $p = []): array
    {
        return static::$db->query("SELECT id, first, second, relation FROM bdus_rs WHERE {$where} ORDER BY id", $p, 'read') ?: [];
    }

    private function codes(array $validation): array
    {
        return array_map(fn($r) => $r['code'] ?? null, $validation['results']);
    }

    private function actAs(int $tier, int $id = 52): void
    {
        \Auth\CurrentUser::set(['id' => $id, 'name' => 'w', 'email' => 'w@example.com', 'privilege' => $tier, 'app' => 'test']);
    }

    // ── schema, rs_read ───────────────────────────────────────────────────────

    public function testSchemaTeachesTheRelationCodesOnlyWhereTheyApply(): void
    {
        $items = $this->tool('schema', ['table' => 'items']);
        $this->assertContains('stratigraphic_relations', $items['features']);
        $codes = array_column($items['stratigraphy']['relations'], 'inverse', 'code');
        $this->assertSame([1 => 5, 2 => 6, 3 => 7, 4 => 8, 5 => 1, 6 => 2, 7 => 3, 8 => 4, 9 => 9, 10 => 10], $codes);
        $this->assertSame('covers', array_column($items['stratigraphy']['relations'], 'name', 'code')[5]);

        $this->assertArrayNotHasKey('stratigraphy', $this->tool('schema', ['table' => 'reviews']));
    }

    public function testRsReadListsTheRelationsOfARecord(): void
    {
        $res = $this->tool('rs_read', ['table' => 'items', 'id' => 1]);

        $this->assertFalse($res['__isError'], json_encode($res));
        $this->assertCount(1, $res['relations']);
        $this->assertSame(
            ['id' => 1, 'first' => 1, 'first_label' => '1', 'relation' => 1, 'name' => 'is_covered_by', 'second' => 2, 'second_label' => '2', 'reading' => '1 is covered by 2'],
            $res['relations'][0]
        );
        // the other side of the same row
        $this->assertSame([1], array_column($this->tool('rs_read', ['table' => 'items', 'id' => 2])['relations'], 'id'));
        $this->assertSame([], $this->tool('rs_read', ['table' => 'items', 'id' => 3])['relations']);
    }

    public function testRsReadRefusals(): void
    {
        $cases = [
            'rs_not_configured' => ['table' => 'reviews', 'id' => 1],
            'unknown_table'     => ['table' => 'nope', 'id' => 1],
            'parameter_missing' => ['table' => 'items'],
            'record_not_found'  => ['table' => 'items', 'id' => 99999],
        ];
        foreach ($cases as $code => $args) {
            $res = $this->tool('rs_read', $args);
            $this->assertTrue($res['__isError'], $code);
            $this->assertSame($code, $res['code']);
        }
        $this->assertSame('unknown_table', $this->tool('rs_read', ['table' => 'tags', 'id' => 1])['code'], 'a plugin table is not a subject');
    }

    // ── rs_validate, rs_write ─────────────────────────────────────────────────

    public function testValidateShowsWhatWillBeWrittenAndWritesNothing(): void
    {
        $res = $this->validate(['add' => [$this->add(3, 5, 4)]]);

        $this->assertTrue($res['valid'], json_encode($res));
        $this->assertNotEmpty($res['validation_token']);
        $this->assertSame('add', $res['results'][0]['kind']);
        $this->assertSame('covers', $res['results'][0]['name']);
        $this->assertSame('3 covers 4', $res['results'][0]['reading']);
        $this->assertCount(1, $this->rows(), 'nothing was written');
    }

    public function testWriteAddsAndRemovesInOneCall(): void
    {
        [, $res] = $this->change(['add' => [$this->add(3, 5, 4), $this->add(4, 6, 5)], 'remove' => [1]]);

        $this->assertFalse($res['__isError'], json_encode($res));
        $this->assertSame(1, $res['removed']);
        $this->assertCount(2, $res['added']);
        $this->assertSame([[3, 4, 5], [4, 5, 6]], array_map(fn($r) => [(int) $r['first'], (int) $r['second'], (int) $r['relation']], $this->rows()));
        $this->assertSame(
            ['3 covers 4'],
            array_column($this->tool('rs_read', ['table' => 'items', 'id' => 3])['relations'], 'reading')
        );
    }

    public function testTheSameFactReadFromTheOtherSideIsADuplicate(): void
    {
        // seeded: 1 is covered by 2. "2 covers 1" is the same fact.
        $res = $this->validate(['add' => [$this->add(2, 5, 1)]]);
        $this->assertFalse($res['valid']);
        $this->assertNull($res['validation_token']);
        $this->assertSame(['relation_already_exist'], $this->codes($res));

        // also inside one call
        $twice = $this->validate(['add' => [$this->add(3, 5, 4), $this->add(4, 1, 3)]]);
        $this->assertFalse($twice['valid']);
        $this->assertSame([null, 'relation_already_exist'], $this->codes($twice));
    }

    public function testRefusalsAreCodesPerItem(): void
    {
        $res = $this->validate(['add' => [
            $this->add(3, 5, 99999),   // a record that does not exist
            $this->add(3, 11, 4),      // not a relation
            $this->add(3, 5, 3),       // with itself
        ], 'remove' => [99999]]);

        $this->assertFalse($res['valid']);
        $this->assertSame(['not_found', 'record_not_found', 'invalid_relation', 'relation_with_itself'], $this->codes($res));
        $this->assertCount(1, $this->rows());
    }

    public function testRemovalsMustBeRelationsOfThatTable(): void
    {
        static::$db->exec("INSERT INTO bdus_rs (id, tb, first, second, relation) VALUES (2, 'reviews', 1, 2, 5)");

        $res = $this->validate(['remove' => [2]]);

        $this->assertFalse($res['valid']);
        $this->assertSame(['not_found'], $this->codes($res), 'a relation of another table is not this table\'s');
        static::$db->exec('DELETE FROM bdus_rs WHERE id = 2');
    }

    public function testARemovedRelationMayBeStatedAgainInTheSameCall(): void
    {
        [, $res] = $this->change(['remove' => [1], 'add' => [$this->add(1, 1, 2)]]);

        $this->assertFalse($res['__isError'], json_encode($res));
        $this->assertCount(1, $this->rows());
        $this->assertNotSame(1, (int) $this->rows()[0]['id'], 'a new row replaced the old one');
    }

    public function testTheCallMustBeOneOfAddOrRemoveAndNotTooBig(): void
    {
        $this->assertSame('parameter_missing', $this->tool('rs_validate', ['table' => 'items'])['code']);
        $this->assertSame('invalid_parameters', $this->tool('rs_validate', ['table' => 'items', 'add' => 'x'])['code']);
        $this->assertSame('invalid_parameters', $this->tool('rs_validate', ['table' => 'items', 'add' => [['first' => 1, 'relation' => 5]]])['code']);
        $this->assertSame('invalid_parameters', $this->tool('rs_validate', ['table' => 'items', 'add' => [['first' => 0, 'relation' => 5, 'second' => 2]]])['code']);
        $this->assertSame('invalid_parameters', $this->tool('rs_validate', ['table' => 'items', 'remove' => ['x']])['code']);
        $tooMany = array_map(fn($i) => $this->add(3, 5, 4), range(1, 51));
        $this->assertSame('invalid_parameters', $this->tool('rs_validate', ['table' => 'items', 'add' => $tooMany])['code']);
        $this->assertSame('rs_not_configured', $this->tool('rs_validate', ['table' => 'reviews', 'add' => [$this->add(1, 5, 2)]])['code']);
        $this->assertSame('unknown_table', $this->tool('rs_validate', ['table' => 'nope', 'add' => [$this->add(1, 5, 2)]])['code']);
    }

    // ── token, switch, budget ─────────────────────────────────────────────────

    public function testWriteNeedsTheTokenOfExactlyThisPayload(): void
    {
        $payload = ['add' => [$this->add(3, 5, 4)]];
        $token   = $this->validate($payload)['validation_token'];

        $other = $this->tool('rs_write', ['table' => 'items', 'add' => [$this->add(3, 5, 5)], 'validation_token' => $token]);
        $this->assertSame('validation_token_invalid', $other['code']);
        $this->assertSame('validation_token_invalid', $this->tool('rs_write', ['table' => 'items'] + $payload)['code'], 'no token');
        $this->assertSame('validation_token_invalid', $this->tool('rs_write', ['table' => 'items'] + $payload + ['validation_token' => 'x'])['code']);

        // a token for records is not one for relations, and the other way round
        $records = $this->tool('records_validate', ['table' => 'items', 'records' => [['core' => ['name' => 'unrelated', 'status' => 'active']]]]);
        $this->assertSame('validation_token_invalid', $this->tool('rs_write', ['table' => 'items'] + $payload + ['validation_token' => $records['validation_token']])['code']);

        $this->assertCount(1, $this->rows());
        $this->assertFalse($this->tool('rs_write', ['table' => 'items', 'validation_token' => $token] + $payload)['__isError']);
    }

    public function testWriteIsRefusedWhileTheSwitchIsOffButValidationIsNot(): void
    {
        $payload = ['add' => [$this->add(3, 5, 4)]];
        $this->assertTrue($this->tool('rs_validate', ['table' => 'items'] + $payload, ['write' => false])['valid']);
        $res = $this->tool('rs_write', ['table' => 'items', 'validation_token' => 'x'] + $payload, ['write' => false]);
        $this->assertSame('mcp_write_disabled', $res['code']);
    }

    public function testABatchIsAllOrNothingWhenTheDatabaseChangesAfterValidation(): void
    {
        $payload = ['add' => [$this->add(3, 5, 4), $this->add(4, 6, 5)]];
        $check   = $this->validate($payload);
        $this->assertTrue($check['valid']);

        static::$db->exec("INSERT INTO bdus_rs (tb, first, second, relation) VALUES ('items', 4, 5, 6)");   // someone got there first
        $res = $this->tool('rs_write', ['table' => 'items', 'validation_token' => $check['validation_token']] + $payload);

        $this->assertTrue($res['__isError']);
        $this->assertSame('write_refused', $res['code']);
        $this->assertSame(['kind' => 'add', 'index' => 1, 'code' => 'relation_already_exist'], array_intersect_key($res['detail'], array_flip(['kind', 'index', 'code'])));
        $this->assertSame([], $this->rows('first = 3'), 'the first relation was rolled back');
        $this->assertFalse(static::$db->inTransaction());
    }

    public function testRelationsCountAgainstTheHourlyBudgetOfAKey(): void
    {
        $dir   = sys_get_temp_dir() . '/bdus_mcp_rs_' . bin2hex(random_bytes(4)) . '/';
        $saved = getenv('BRADYPUS_RATE_MCP_WRITE_ROWS');
        \Auth\RateLimiter::setDir($dir);
        putenv('BRADYPUS_RATE_MCP_WRITE_ROWS=1');
        try {
            \Auth\CurrentUser::set(['id' => 0, 'key_id' => 8, 'privilege' => 20, 'is_api_key' => true, 'name' => 'key']);
            [, $res] = $this->change(['add' => [$this->add(3, 5, 4), $this->add(4, 6, 5)]]);

            $this->assertSame('too_many_requests', $res['code']);
            $this->assertSame([], $this->rows('first = 3'), 'refused before anything changed');
        } finally {
            putenv($saved === false ? 'BRADYPUS_RATE_MCP_WRITE_ROWS' : "BRADYPUS_RATE_MCP_WRITE_ROWS={$saved}");
            \Auth\RateLimiter::setDir(null);
            foreach (glob($dir . '*') ?: [] as $f) {
                unlink($f);
            }
            @rmdir($dir);
        }
    }

    // ── Rights ────────────────────────────────────────────────────────────────

    public function testBothRecordsMustBeChangeableByThePrincipal(): void
    {
        // A writer; item 2 is frozen by a lock.
        static::$db->exec("INSERT INTO bdus_users (id, name, email, password, privilege) VALUES (52, 'w', 'w@example.com', 'x', 20)");
        static::$db->query("INSERT INTO bdus_table_locks (table_name, filter) VALUES ('items', ?)", [json_encode(['status' => ['_eq' => 'inactive']])], 'boolean');
        $this->actAs(20);

        $res = $this->validate(['add' => [$this->add(3, 5, 2), $this->add(3, 5, 4)], 'remove' => [1]]);
        $this->assertFalse($res['valid']);
        $this->assertSame(['not_enough_privilege', 'not_enough_privilege', null], $this->codes($res));
        $this->assertNull($res['validation_token']);

        [, $ok] = $this->change(['add' => [$this->add(3, 5, 4)]]);
        $this->assertFalse($ok['__isError'], json_encode($ok));

        // an admin is not stopped by the lock
        $this->actAs(10, 1);
        [, $admin] = $this->change(['add' => [$this->add(3, 6, 2)], 'remove' => [1]]);
        $this->assertFalse($admin['__isError'], json_encode($admin));
    }

    public function testAReaderCannotWrite(): void
    {
        $this->setPrivilege(30);

        $res = $this->validate(['add' => [$this->add(3, 5, 4)]]);
        $this->assertFalse($res['valid']);
        $this->assertSame(['not_enough_privilege'], $this->codes($res));
    }

    public function testRelationsToRecordsThePrincipalCannotReadStayHidden(): void
    {
        // Tier 39 with a group that reads, and updates, only active items: 1, 3, 5.
        $g = (int) static::$db->query("INSERT INTO bdus_groups (name) VALUES ('Scoped')", [], 'id');
        foreach (['read', 'update'] as $action) {
            static::$db->query(
                'INSERT INTO bdus_group_rules (group_id, table_name, action, filter) VALUES (?, ?, ?, ?)',
                [$g, 'items', $action, json_encode(['status' => ['_eq' => 'active']])],
                'boolean'
            );
        }
        static::$db->exec("INSERT INTO bdus_users (id, name, email, password, privilege) VALUES (50, 'rev', 'rev@example.com', 'x', 39)");
        static::$db->exec("INSERT INTO bdus_user_groups (user_id, group_id) VALUES (50, {$g})");
        $this->actAs(39, 50);

        // 1 is covered by 2, but 2 is inactive: for this principal the relation is not there
        $this->assertSame([], $this->tool('rs_read', ['table' => 'items', 'id' => 1])['relations']);
        $this->assertSame('not_enough_privilege', $this->tool('rs_read', ['table' => 'items', 'id' => 2])['code']);

        // and it can neither add a relation to it nor remove the one that exists
        $res = $this->validate(['add' => [$this->add(3, 5, 2)], 'remove' => [1]]);
        $this->assertFalse($res['valid']);
        $this->assertSame(['not_enough_privilege', 'not_enough_privilege'], $this->codes($res));

        // between records it may change, with their labels
        $ok = $this->validate(['add' => [$this->add(3, 5, 5)]]);
        $this->assertTrue($ok['valid'], json_encode($ok));
        $this->assertSame('3 covers 5', $ok['results'][0]['reading']);
    }
}
