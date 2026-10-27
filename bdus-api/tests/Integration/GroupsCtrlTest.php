<?php

namespace Tests\Integration;

use Tests\Support\BdusTestCase;

/**
 * Groups controller (#78): groups, membership, rules and table locks.
 */
class GroupsCtrlTest extends BdusTestCase
{
    protected function setUp(): void
    {
        static::$db->exec("INSERT INTO bdus_users (id, name, email, password, privilege) VALUES (61, 'ann', 'ann@example.com', 'x', 39), (62, 'bob', 'bob@example.com', 'x', 30)");
    }

    protected function tearDown(): void
    {
        foreach (['bdus_user_groups', 'bdus_group_rules', 'bdus_groups', 'bdus_table_locks', 'bdus_api_keys'] as $t) {
            static::$db->exec("DELETE FROM {$t}");
        }
        static::$db->exec('DELETE FROM bdus_users WHERE id IN (61, 62)');
        $this->setPrivilege(1);
    }

    private function call(string $method, array $get = [], array $post = []): array
    {
        return $this->callController($this->makeController('Bdus\\Controllers\\Groups', $get, $post), $method);
    }

    private function group(string $name = 'Reviewers'): int
    {
        return (int) $this->call('createGroup', [], ['name' => $name])['id'];
    }

    private function scalar(string $sql, array $v = []): int
    {
        return (int) static::$db->query($sql, $v, 'read')[0]['n'];
    }

    // ── Privilege ─────────────────────────────────────────────────────────────

    public function testEveryEndpointIsAdminOnly(): void
    {
        $g = $this->group();
        $this->setPrivilege(20);

        foreach ([
            ['listGroups', []], ['createGroup', ['name' => 'x']], ['getGroup', ['id' => $g]], ['updateGroup', ['id' => $g]],
            ['deleteGroup', ['id' => $g]], ['setMembers', ['id' => $g]], ['setRules', ['id' => $g]],
            ['getUserGroups', ['id' => 61]], ['setUserGroups', ['id' => 61]],
            ['listLocks', []], ['createLock', []], ['updateLock', ['id' => 1]], ['deleteLock', ['id' => 1]],
        ] as [$method, $args]) {
            $res = $this->call($method, $args, $args);
            $this->assertSame('not_enough_privilege', $res['code'] ?? null, $method);
        }
        $this->assertSame(1, $this->scalar('SELECT COUNT(*) AS n FROM bdus_groups'));
    }

    // ── Groups ────────────────────────────────────────────────────────────────

    public function testCreateListUpdateAndDelete(): void
    {
        $id = $this->group('Reviewers');
        $this->assertGreaterThan(0, $id);

        $this->assertSame('group_name_taken', $this->call('createGroup', [], ['name' => 'Reviewers'])['code']);
        $this->assertSame('parameter_missing', $this->call('createGroup', [], ['name' => '  '])['code']);

        $this->assertSame('group_updated', $this->call('updateGroup', ['id' => $id], ['name' => 'Reviewers', 'description' => 'Site 5'])['code']);
        $other = $this->group('Other');
        $this->assertSame('group_name_taken', $this->call('updateGroup', ['id' => $other], ['name' => 'Reviewers'])['code']);

        $list = $this->call('listGroups')['groups'];
        $this->assertSame(['Other', 'Reviewers'], array_column($list, 'name'));
        $this->assertSame('Site 5', $list[1]['description']);

        $this->assertSame('group_deleted', $this->call('deleteGroup', ['id' => $id])['code']);
        $this->assertSame('group_not_found', $this->call('getGroup', ['id' => $id])['code']);
    }

    public function testDeletingAGroupRemovesItsRulesAndMembersAndFreesItsKeys(): void
    {
        $g = $this->group();
        $this->call('setMembers', ['id' => $g], ['user_ids' => [61]]);
        $this->call('setRules', ['id' => $g], ['rules' => [['table' => 'items', 'action' => 'read']]]);
        static::$db->exec("INSERT INTO bdus_api_keys (key_hash, label, created_at, privilege, group_id) VALUES ('h', 'k', 1, 39, {$g})");

        $this->assertSame(1, $this->call('listGroups')['groups'][0]['keys']);
        $this->call('deleteGroup', ['id' => $g]);

        $this->assertSame(0, $this->scalar('SELECT COUNT(*) AS n FROM bdus_user_groups'));
        $this->assertSame(0, $this->scalar('SELECT COUNT(*) AS n FROM bdus_group_rules'));
        $this->assertNull(static::$db->query('SELECT group_id FROM bdus_api_keys', [], 'read')[0]['group_id']);
    }

    // ── Membership ────────────────────────────────────────────────────────────

    public function testMembersAreReplacedAndValidated(): void
    {
        $g = $this->group();

        $this->assertSame('members_saved', $this->call('setMembers', ['id' => $g], ['user_ids' => [61, 62, 61]])['code']);
        $this->assertSame(2, $this->scalar('SELECT COUNT(*) AS n FROM bdus_user_groups WHERE group_id = ?', [$g]));

        $this->call('setMembers', ['id' => $g], ['user_ids' => [62]]);
        $members = $this->call('getGroup', ['id' => $g])['group']['members'];
        $this->assertSame([62], array_column($members, 'id'));

        $this->assertSame('user_not_found', $this->call('setMembers', ['id' => $g], ['user_ids' => [62, 9999]])['code']);
        $this->assertSame(1, $this->scalar('SELECT COUNT(*) AS n FROM bdus_user_groups WHERE group_id = ?', [$g])); // unchanged
        $this->assertSame('parameter_missing', $this->call('setMembers', ['id' => $g], [])['code']);
    }

    public function testAUsersGroups(): void
    {
        $a = $this->group('A');
        $b = $this->group('B');

        $this->assertSame('groups_saved', $this->call('setUserGroups', ['id' => 61], ['group_ids' => [$a, $b]])['code']);
        $this->assertSame([$a, $b], $this->call('getUserGroups', ['id' => 61])['groups']);

        $this->call('setUserGroups', ['id' => 61], ['group_ids' => []]);
        $this->assertSame([], $this->call('getUserGroups', ['id' => 61])['groups']);

        $this->assertSame('user_not_found', $this->call('getUserGroups', ['id' => 9999])['code']);
        $this->assertSame('group_not_found', $this->call('setUserGroups', ['id' => 61], ['group_ids' => [9999]])['code']);
    }

    // ── Rules ─────────────────────────────────────────────────────────────────

    public function testRulesAreReplacedAsAWhole(): void
    {
        $g = $this->group();
        $filter = ['status' => ['_eq' => 'active']];

        $res = $this->call('setRules', ['id' => $g], ['rules' => [
            ['table' => 'items', 'action' => 'read', 'filter' => $filter],
            ['table' => 'items', 'action' => 'update', 'filter' => null],
            ['table' => 'items', 'action' => 'create'],
            ['table' => 'tags',  'action' => 'read', 'filter' => []],
        ]]);
        $this->assertSame('rules_saved', $res['code']);

        $rules = $this->call('getGroup', ['id' => $g])['group']['rules'];
        $byKey = [];
        foreach ($rules as $r) {
            $byKey[$r['table'] . '.' . $r['action']] = $r['filter'];
        }
        $this->assertSame($filter, $byKey['items.read']);
        $this->assertNull($byKey['items.update']);
        $this->assertNull($byKey['tags.read']);
        $this->assertCount(4, $rules);

        $this->call('setRules', ['id' => $g], ['rules' => [['table' => 'items', 'action' => 'read']]]);
        $this->assertCount(1, $this->call('getGroup', ['id' => $g])['group']['rules']);
    }

    public function testAnInvalidRuleSavesNothing(): void
    {
        $g = $this->group();
        $this->call('setRules', ['id' => $g], ['rules' => [['table' => 'items', 'action' => 'read']]]);

        $cases = [
            'unknown_table'    => [['table' => 'nope', 'action' => 'read']],
            'system table'     => [['table' => 'bdus_users', 'action' => 'read']],
            'invalid_rule'     => [['table' => 'items', 'action' => 'explode']],
            'create + filter'  => [['table' => 'items', 'action' => 'create', 'filter' => ['status' => ['_eq' => 'a']]]],
            'invalid_filter'   => [['table' => 'items', 'action' => 'read', 'filter' => ['nofield' => ['_eq' => 1]]]],
            'bad operator'     => [['table' => 'items', 'action' => 'read', 'filter' => ['status' => ['_oops' => 1]]]],
            'duplicate_rule'   => [['table' => 'items', 'action' => 'update'], ['table' => 'items', 'action' => 'update']],
        ];
        foreach ($cases as $label => $rules) {
            $res = $this->call('setRules', ['id' => $g], ['rules' => array_merge([['table' => 'tags', 'action' => 'read']], $rules)]);
            $this->assertSame('error', $res['status'], $label);
        }

        $left = $this->call('getGroup', ['id' => $g])['group']['rules'];
        $this->assertSame([['table' => 'items', 'action' => 'read', 'filter' => null]], $left);
    }

    public function testCurrentUserVariableIsAcceptedInFilters(): void
    {
        $g = $this->group();

        $res = $this->call('setRules', ['id' => $g], ['rules' => [
            ['table' => 'items', 'action' => 'read', 'filter' => ['creator' => ['_eq' => '$CURRENT_USER']]],
        ]]);

        $this->assertSame('rules_saved', $res['code']);
    }

    // ── Locks ─────────────────────────────────────────────────────────────────

    public function testLockLifecycle(): void
    {
        $filter = ['status' => ['_eq' => 'active']];
        $id = (int) $this->call('createLock', [], ['table' => 'items', 'filter' => $filter, 'note' => 'published'])['id'];

        $locks = $this->call('listLocks')['locks'];
        $this->assertSame([['id' => $id, 'table' => 'items', 'filter' => $filter, 'note' => 'published']], $locks);

        $this->assertSame('lock_updated', $this->call('updateLock', ['id' => $id], ['table' => 'items', 'filter' => ['status' => ['_eq' => 'pending']]])['code']);
        $this->assertSame(['status' => ['_eq' => 'pending']], $this->call('listLocks')['locks'][0]['filter']);

        $this->assertSame('lock_deleted', $this->call('deleteLock', ['id' => $id])['code']);
        $this->assertSame('lock_not_found', $this->call('deleteLock', ['id' => $id])['code']);
        $this->assertSame('lock_not_found', $this->call('updateLock', ['id' => $id], ['table' => 'items', 'filter' => $filter])['code']);
    }

    public function testLockValidation(): void
    {
        $this->assertSame('unknown_table', $this->call('createLock', [], ['table' => 'nope', 'filter' => ['id' => ['_eq' => 1]]])['code']);
        $this->assertSame('unknown_table', $this->call('createLock', [], ['table' => 'bdus_users', 'filter' => ['id' => ['_eq' => 1]]])['code']);
        $this->assertSame('invalid_filter', $this->call('createLock', [], ['table' => 'items', 'filter' => []])['code']);
        $this->assertSame('invalid_filter', $this->call('createLock', [], ['table' => 'items'])['code']);
        $this->assertSame('invalid_filter', $this->call('createLock', [], ['table' => 'items', 'filter' => ['nofield' => ['_eq' => 1]]])['code']);
        $this->assertSame(0, $this->scalar('SELECT COUNT(*) AS n FROM bdus_table_locks'));
    }

    public function testASavedRuleAndLockActuallyDriveAccess(): void
    {
        // What the endpoints store is what Auth\Access reads back.
        $g = $this->group();
        $this->call('setMembers', ['id' => $g], ['user_ids' => [61]]);
        $this->call('setRules', ['id' => $g], ['rules' => [
            ['table' => 'items', 'action' => 'read', 'filter' => ['status' => ['_eq' => 'active']]],
        ]]);

        $access = new \Auth\Access(static::$db, static::$cfg, 39, 61, [$g]);
        [$sql, $vals] = $access->rowCondition('read', 'items');
        $ids = array_column(static::$db->query("SELECT id FROM items WHERE {$sql} ORDER BY id", $vals, 'read'), 'id');

        $this->assertSame([1, 3, 5], array_map('intval', $ids));
    }
}
