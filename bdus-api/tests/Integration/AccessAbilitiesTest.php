<?php

namespace Tests\Integration;

use Auth\Authorization;
use Tests\Support\BdusTestCase;

/**
 * What the interface is told the user may do (#78): per-table abilities in the
 * table list, the app info and the records list, and per-record can_edit /
 * can_delete / locked in the record view — the same answers the write gate gives.
 *
 * Fixture: items 1-5 (active, inactive, active, pending, active), item 2 frozen
 * by a lock. The principal is user 53.
 */
class AccessAbilitiesTest extends BdusTestCase
{
    protected function setUp(): void
    {
        static::$db->exec("INSERT INTO bdus_users (id, name, email, password, privilege) VALUES (53, 'u', 'u@example.com', 'x', 20)");
        static::$db->query(
            "INSERT INTO bdus_table_locks (table_name, filter) VALUES ('items', ?)",
            [json_encode(['status' => ['_eq' => 'inactive']])], 'boolean'
        );
        $this->actAs(20);
        // What the Dispatcher does for every request.
        Authorization::setAccessProvider(fn() => \Auth\Access::forCurrentUser(static::$db, static::$cfg));
    }

    protected function tearDown(): void
    {
        Authorization::setAppStatus('on');
        foreach (['bdus_user_groups', 'bdus_group_rules', 'bdus_groups', 'bdus_table_locks'] as $t) {
            static::$db->exec("DELETE FROM {$t}");
        }
        static::$db->exec('DELETE FROM bdus_users WHERE id = 53');
        static::$db->exec("UPDATE items SET creator = 'admin'");
        $this->setPrivilege(1);
    }

    private function actAs(int $tier): void
    {
        \Auth\CurrentUser::set(['id' => 53, 'name' => 'u', 'email' => 'u@example.com', 'privilege' => $tier, 'app' => 'test']);
    }

    private function inGroup(array $rules): void
    {
        $g = (int) static::$db->query("INSERT INTO bdus_groups (name) VALUES ('G')", [], 'id');
        static::$db->exec("INSERT INTO bdus_user_groups (user_id, group_id) VALUES (53, {$g})");
        foreach ($rules as [$table, $action, $filter]) {
            static::$db->query(
                'INSERT INTO bdus_group_rules (group_id, table_name, action, filter) VALUES (?, ?, ?, ?)',
                [$g, $table, $action, $filter === null ? null : json_encode($filter)], 'boolean'
            );
        }
    }

    private function call(string $class, string $method, array $get = []): array
    {
        return $this->callController($this->makeController("Bdus\\Controllers\\{$class}", $get), $method);
    }

    private function meta(int $id): array
    {
        return $this->call('Record', 'getRecord', ['tb' => 'items', 'id' => $id])['metadata'];
    }

    // ── Authorization::tableAbilities ─────────────────────────────────────────

    public function testAbilitiesFollowTheTier(): void
    {
        $all  = ['create' => true,  'read' => true,  'update' => true,  'delete' => true];
        $none = ['create' => false, 'read' => false, 'update' => false, 'delete' => false];

        foreach ([[1, $all], [10, $all], [20, $all],
                  [25, ['create' => true, 'read' => true, 'update' => true, 'delete' => true]], // own records
                  [30, ['create' => false, 'read' => true, 'update' => false, 'delete' => false]],
                  [39, $none], [40, $none]] as [$tier, $expected]) {
            $this->actAs($tier);
            $this->assertSame($expected, Authorization::tableAbilities('items'), "tier $tier");
        }
    }

    public function testRulesAddAbilitiesPerAction(): void
    {
        $this->actAs(39);
        $this->inGroup([['items', 'read', null], ['items', 'update', ['status' => ['_eq' => 'pending']]]]);

        $this->assertSame(['create' => false, 'read' => true, 'update' => true, 'delete' => false], Authorization::tableAbilities('items'));
        $this->assertSame(['create' => false, 'read' => false, 'update' => false, 'delete' => false], Authorization::tableAbilities('tags'));
    }

    public function testFrozenAppSwitchesOffEveryWrite(): void
    {
        Authorization::setAppStatus('frozen');

        $this->assertSame(['create' => false, 'read' => true, 'update' => false, 'delete' => false], Authorization::tableAbilities('items'));
    }

    public function testDeleteHasTheTiersOfEdit(): void
    {
        foreach ([1, 10, 20, 25, 30, 39] as $tier) {
            $this->actAs($tier);
            $this->assertSame(Authorization::can('edit', 53), Authorization::can('delete', 53), "tier $tier");
        }
    }

    // ── Endpoints ─────────────────────────────────────────────────────────────

    public function testTableListCarriesTheAbilities(): void
    {
        $this->actAs(39);
        $this->inGroup([['items', 'read', null], ['items', 'create', null]]);

        $tables = $this->call('Home', 'listTables')['tables'];

        $this->assertSame(['items'], array_column($tables, 'name'));
        $this->assertSame(['create' => true, 'read' => true, 'update' => false, 'delete' => false], $tables[0]['can']);
    }

    public function testAppInfoIsOpenToAnyoneWhoCanReadSomething(): void
    {
        $this->actAs(39);
        $this->assertSame('not_enough_privilege', $this->call('Info', 'getAppInfo')['code']);

        $this->inGroup([['items', 'read', null]]);
        $info = $this->call('Info', 'getAppInfo');
        $this->assertSame('success', $info['status']);
        $this->assertSame(['read' => true, 'create' => false, 'update' => false, 'delete' => false], $info['can']);

        $this->actAs(20);
        $this->assertSame(['read' => true, 'create' => true, 'update' => true, 'delete' => true], $this->call('Info', 'getAppInfo')['can']);
    }

    public function testRecordsListCarriesTheAbilities(): void
    {
        $res = $this->call('Record', 'getRecords', ['tb' => 'items']);

        $this->assertTrue($res['can_add']);
        $this->assertSame(['create' => true, 'read' => true, 'update' => true, 'delete' => true], $res['can']);

        $this->actAs(30);
        $res = $this->call('Record', 'getRecords', ['tb' => 'items']);
        $this->assertFalse($res['can_add']);
        $this->assertFalse($res['can']['update']);
    }

    // ── Record view ───────────────────────────────────────────────────────────

    public function testWriterCanChangeAFreeRecordButNotAFrozenOne(): void
    {
        $free = $this->meta(1);
        $this->assertTrue($free['can_edit']);
        $this->assertTrue($free['can_delete']);
        $this->assertFalse($free['locked']);

        $frozen = $this->meta(2);
        $this->assertFalse($frozen['can_edit']);
        $this->assertFalse($frozen['can_delete']);
        $this->assertTrue($frozen['locked']);
    }

    public function testAdminsCanChangeAFrozenRecordAndSeeThatItIsFrozen(): void
    {
        $this->actAs(10);
        $frozen = $this->meta(2);

        $this->assertTrue($frozen['can_edit']);
        $this->assertTrue($frozen['can_delete']);
        $this->assertTrue($frozen['locked']);
    }

    public function testRulesDecideEditAndDeleteSeparately(): void
    {
        $this->actAs(39);
        $this->inGroup([
            ['items', 'read', null],
            ['items', 'update', ['status' => ['_eq' => 'pending']]],
            ['items', 'delete', ['status' => ['_eq' => 'active']]],
        ]);

        $pending = $this->meta(4);
        $this->assertTrue($pending['can_edit']);
        $this->assertFalse($pending['can_delete']);

        $active = $this->meta(1);
        $this->assertFalse($active['can_edit']);
        $this->assertTrue($active['can_delete']);
    }

    public function testSelfWriterOnlyChangesOwnRecords(): void
    {
        static::$db->exec("UPDATE items SET creator = '53' WHERE id IN (1, 2)");
        $this->actAs(25);

        $this->assertTrue($this->meta(1)['can_edit']);
        $this->assertFalse($this->meta(2)['can_edit']);   // own but frozen
        $this->assertFalse($this->meta(3)['can_edit']);   // free but not theirs
        $this->assertTrue($this->meta(3)['can_add']);
    }

    public function testReaderCannotChangeAnything(): void
    {
        $this->actAs(30);
        $meta = $this->meta(1);

        $this->assertFalse($meta['can_edit']);
        $this->assertFalse($meta['can_delete']);
        $this->assertFalse($meta['can_add']);
    }

    public function testNewRecordSkeletonKeepsItsAbilities(): void
    {
        $meta = $this->call('Record', 'getRecord', ['tb' => 'items'])['metadata'];

        $this->assertTrue($meta['can_add']);
        $this->assertTrue($meta['can_edit']);
        $this->assertFalse($meta['locked']);
    }
}
