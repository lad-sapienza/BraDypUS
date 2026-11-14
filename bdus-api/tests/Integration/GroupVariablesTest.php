<?php

namespace Tests\Integration;

use Auth\Access;
use DB\System\Manage;
use DB\System\Migrations\M049_AddAttrsToGroups;
use Tests\Support\BdusTestCase;

/**
 * Group variables and server-enforced values on create/update rules (#51).
 *
 * Fixture `items` 1-5: status active, inactive, active, pending, active.
 * The principal is user 52 at tier 39 (enter), so group rules are the only
 * source of grants. `status` plays the part of "the site" that scopes a record.
 */
class GroupVariablesTest extends BdusTestCase
{
    protected function setUp(): void
    {
        static::$db->exec("INSERT INTO bdus_users (id, name, email, password, privilege) VALUES (52, 'w', 'w@example.com', 'x', 39)");
        $this->actAs(39);
    }

    protected function tearDown(): void
    {
        static::$db->exec('DELETE FROM bdus_user_groups');
        static::$db->exec('DELETE FROM bdus_group_rules');
        static::$db->exec('DELETE FROM bdus_groups');
        static::$db->exec('DELETE FROM bdus_users WHERE id = 52');
        static::$db->exec('DELETE FROM items WHERE id > 5');
        static::$db->exec("UPDATE items SET name = 'item ' || id WHERE name = 'imp-one'");
        static::$db->exec("UPDATE items SET status = CASE id WHEN 1 THEN 'active' WHEN 2 THEN 'inactive' WHEN 3 THEN 'active' WHEN 4 THEN 'pending' ELSE 'active' END, creator = 'admin'");
        $this->setPrivilege(1);
    }

    // ── Helpers ───────────────────────────────────────────────────────────────

    private function actAs(int $tier): void
    {
        \Auth\CurrentUser::set(['id' => 52, 'name' => 'w', 'email' => 'w@example.com', 'privilege' => $tier, 'app' => 'test']);
    }

    /** A group with attributes, the principal (user 52) as a member, and rules [table, action, ?filter, ?enforce]. */
    private function group(array|string|null $attrs, array $rules = []): int
    {
        static $n = 0;
        $json = is_string($attrs) ? $attrs : ($attrs === null ? null : json_encode($attrs));
        $id   = (int) static::$db->query(
            'INSERT INTO bdus_groups (name, attrs) VALUES (?, ?)', ['G' . (++$n), $json], 'id'
        );
        static::$db->exec("INSERT INTO bdus_user_groups (user_id, group_id) VALUES (52, {$id})");
        foreach ($rules as [$table, $action, $filter, $enforce]) {
            static::$db->query(
                'INSERT INTO bdus_group_rules (group_id, table_name, action, filter, enforce) VALUES (?, ?, ?, ?, ?)',
                [$id, $table, $action, $filter === null ? null : json_encode($filter), $enforce === null ? null : json_encode($enforce)],
                'boolean'
            );
        }
        return $id;
    }

    private function access(array $groupIds, int $tier = 39): Access
    {
        return new Access(static::$db, static::$cfg, $tier, 52, $groupIds);
    }

    /** @return int[] ids of `items` readable through the rules */
    private function readable(Access $a): array
    {
        [$sql, $vals] = $a->rowCondition('read', 'items');
        return array_map('intval', array_column(
            static::$db->query("SELECT id FROM items WHERE {$sql} ORDER BY id", $vals, 'read') ?: [],
            'id'
        ));
    }

    private function readRule(string $op, mixed $value): array
    {
        return ['items', 'read', ['status' => [$op => $value]], null];
    }

    private function call(string $ctrl, string $method, array $get = [], array $post = []): array
    {
        return $this->callController($this->makeController("Bdus\\Controllers\\{$ctrl}", $get, $post), $method);
    }

    private function itemStatus(int $id): ?string
    {
        return static::$db->query('SELECT status FROM items WHERE id = ?', [$id], 'read')[0]['status'] ?? null;
    }

    // ── Variables in filters ──────────────────────────────────────────────────

    public function testGroupAttributeScopesReadForOneGroup(): void
    {
        $g = $this->group(['st' => 'active'], [$this->readRule('_eq', '$GROUP.st')]);

        $this->assertSame([1, 3, 5], $this->readable($this->access([$g])));
    }

    public function testSameRuleFollowsEachGroupsOwnValue(): void
    {
        $a = $this->group(['st' => 'active'],  [$this->readRule('_eq', '$GROUP.st')]);
        $b = $this->group(['st' => 'pending'], [$this->readRule('_eq', '$GROUP.st')]);

        $this->assertSame([1, 3, 5], $this->readable($this->access([$a])));
        $this->assertSame([4],       $this->readable($this->access([$b])));
    }

    public function testUserInManyGroupsGetsTheUnionOfTheirValues(): void
    {
        $a = $this->group(['st' => 'active'],  [$this->readRule('_eq', '$GROUP.st')]);
        $b = $this->group(['st' => 'pending']);

        $this->assertSame([1, 3, 4, 5], $this->readable($this->access([$a, $b])));
    }

    public function testListAttributeAndNegation(): void
    {
        $g = $this->group(['st' => ['active', 'pending']], [$this->readRule('_neq', '$GROUP.st')]);

        $this->assertSame([2], $this->readable($this->access([$g])));
    }

    public function testVariableInsideAnInList(): void
    {
        $g = $this->group(['st' => 'pending'], [$this->readRule('_in', ['inactive', '$GROUP.st'])]);

        $this->assertSame([2, 4], $this->readable($this->access([$g])));
    }

    public function testAttributeNobodySetsGrantsNothing(): void
    {
        $g = $this->group(['other' => 'x'], [$this->readRule('_eq', '$GROUP.st')]);

        $this->assertSame([], $this->readable($this->access([$g])));
    }

    public function testNegatedUnknownAttributeDoesNotOpenEverything(): void
    {
        $g = $this->group(null, [$this->readRule('_neq', '$GROUP.st')]);

        $this->assertSame([], $this->readable($this->access([$g])));
    }

    public function testUnreadableAttrsCountAsNoAttributes(): void
    {
        $g = $this->group('{not json', [$this->readRule('_eq', '$GROUP.st')]);

        $this->assertSame([], $this->readable($this->access([$g])));
    }

    public function testUnknownVariablesGrantNothing(): void
    {
        foreach (['$GROUPS.st', '$GROUP_st', '$CURRENT_ADMIN', '$GROUP.'] as $var) {
            static::$db->exec('DELETE FROM bdus_group_rules');
            $g = $this->group(['st' => 'active'], [$this->readRule('_eq', $var)]);
            $this->assertSame([], $this->readable($this->access([$g])), $var);
        }
    }

    public function testListVariableNeedsASetOperator(): void
    {
        $g = $this->group(['st' => 'active'], [$this->readRule('_contains', '$GROUP.st')]);

        $this->assertSame([], $this->readable($this->access([$g])));
        $this->assertStringContainsString(
            '_in',
            (string) Access::checkFilter(static::$db, static::$cfg, 'items', ['status' => ['_gt' => '$GROUP.st']])
        );
    }

    public function testCurrentGroupsIsTheListOfGroupIds(): void
    {
        $g = $this->group(null);
        // The group id as a value to match: id = $CURRENT_GROUPS has the shape of a set test.
        $filter = ['id' => ['_in' => '$CURRENT_GROUPS']];
        static::$db->query(
            'INSERT INTO bdus_group_rules (group_id, table_name, action, filter) VALUES (?, ?, ?, ?)',
            [$g, 'items', 'read', json_encode($filter)], 'boolean'
        );

        $expected = in_array($g, [1, 2, 3, 4, 5], true) ? [$g] : [];
        $this->assertSame($expected, $this->readable($this->access([$g])));
    }

    public function testCurrentUserStillWorks(): void
    {
        static::$db->exec("UPDATE items SET creator = 52 WHERE id IN (2, 4)");
        $g = $this->group(null, [['items', 'read', ['creator' => ['_eq' => '$CURRENT_USER']], null]]);

        $this->assertSame([2, 4], $this->readable($this->access([$g])));
    }

    public function testCheckFilterAcceptsVariablesAndRejectsUnknownOnes(): void
    {
        $ok = fn(array $f) => Access::checkFilter(static::$db, static::$cfg, 'items', $f);

        $this->assertNull($ok(['status' => ['_eq' => '$GROUP.anything']]));
        $this->assertNull($ok(['status' => ['_in' => '$CURRENT_GROUPS']]));
        $this->assertNotNull($ok(['status' => ['_eq' => '$GROUP_oops']]));
    }

    // ── server stamps: $NOW, $TODAY, $KEY_ID (#73) ────────────────────────────

    private function accessWithKey(array $groupIds, ?int $keyId): Access
    {
        return new Access(static::$db, static::$cfg, 39, 0, $groupIds, $keyId);
    }

    public function testCreateStampsNowTodayAndKeyId(): void
    {
        $g = $this->group(null, [$this->createRule(['description' => '$NOW', 'status' => '$TODAY', 'category' => '$KEY_ID'])]);
        $out = $this->accessWithKey([$g], 7)->enforceValues('create', 'items', ['name' => 'x']);

        $this->assertMatchesRegularExpression('/^\d{4}-\d{2}-\d{2} \d{2}:\d{2}:\d{2}$/', $out['description']);
        $this->assertSame(date('Y-m-d'), $out['status']);
        $this->assertSame(7, $out['category']);
    }

    public function testCreateRefusesAClientValueForAStampedField(): void
    {
        $g = $this->group(null, [$this->createRule(['description' => '$NOW'])]);
        $a = $this->accessWithKey([$g], 7);

        $this->assertNull($a->enforceValues('create', 'items', ['name' => 'x', 'description' => '1999-01-01 00:00:00']));
    }

    public function testKeyIdStampGrantsNothingToAPerson(): void
    {
        $g = $this->group(null, [$this->createRule(['category' => '$KEY_ID'])]);

        $this->assertNull($this->accessWithKey([$g], null)->enforceValues('create', 'items', ['name' => 'x']));
    }

    public function testStampsAreNotAllowedOnUpdateRulesOrInFilters(): void
    {
        $this->assertNotNull(Access::checkEnforce(static::$cfg, 'items', ['description' => '$NOW'], 'update'));
        $this->assertNull(Access::checkEnforce(static::$cfg, 'items', ['description' => '$NOW'], 'create'));
        $this->assertNotNull(Access::checkFilter(static::$db, static::$cfg, 'items', ['status' => ['_eq' => '$TODAY']]));
    }

    public function testUnknownDollarWordStaysAnUnknownVariable(): void
    {
        $this->assertNotNull(Access::checkEnforce(static::$cfg, 'items', ['description' => '$CURRENT_TIME'], 'create'));
    }

    // ── enforce on create ─────────────────────────────────────────────────────

    private function createRule(array $enforce = ['status' => '$GROUP.st']): array
    {
        return ['items', 'create', null, $enforce];
    }

    public function testCreateAcceptsAnAllowedValue(): void
    {
        $g = $this->group(['st' => 'active'], [$this->createRule()]);

        $this->assertSame(
            ['name' => 'x', 'status' => 'active'],
            $this->access([$g])->enforceValues('create', 'items', ['name' => 'x', 'status' => 'active'])
        );
    }

    public function testCreateRefusesAValueOutsideTheGroupValues(): void
    {
        $g = $this->group(['st' => 'active'], [$this->createRule()]);

        $this->assertNull($this->access([$g])->enforceValues('create', 'items', ['status' => 'pending']));
    }

    public function testCreateFillsInAMissingOrEmptyValueWhenOnlyOneIsPossible(): void
    {
        $g = $this->group(['st' => 'active'], [$this->createRule()]);
        $a = $this->access([$g]);

        $this->assertSame('active', $a->enforceValues('create', 'items', ['name' => 'x'])['status']);
        $this->assertSame('active', $a->enforceValues('create', 'items', ['status' => ''])['status']);
        $this->assertSame('active', $a->enforceValues('create', 'items', ['status' => null])['status']);
    }

    public function testCreateInManyGroupsAcceptsEachOwnValueButNeverGuesses(): void
    {
        $a = $this->group(['st' => 'active'],  [$this->createRule()]);
        $b = $this->group(['st' => 'pending'], [$this->createRule()]);
        $acc = $this->access([$a, $b]);

        $this->assertNotNull($acc->enforceValues('create', 'items', ['status' => 'active']));
        $this->assertNotNull($acc->enforceValues('create', 'items', ['status' => 'pending']));
        $this->assertNull($acc->enforceValues('create', 'items', ['status' => 'inactive']));
        $this->assertNull($acc->enforceValues('create', 'items', ['name' => 'no status sent']));
    }

    public function testCreateWithLiteralAndListEnforce(): void
    {
        $g = $this->group(null, [$this->createRule(['status' => ['draft', 'active']])]);
        $a = $this->access([$g]);

        $this->assertNotNull($a->enforceValues('create', 'items', ['status' => 'draft']));
        $this->assertNull($a->enforceValues('create', 'items', ['status' => 'pending']));
        $this->assertNull($a->enforceValues('create', 'items', []), 'two possible values, none sent');
    }

    public function testCreateWithAnAttributeNobodySetsGrantsNothing(): void
    {
        $g = $this->group(['other' => 'x'], [$this->createRule()]);

        $this->assertNull($this->access([$g])->enforceValues('create', 'items', ['status' => 'active']));
    }

    public function testARuleWithoutEnforceLiftsTheRestriction(): void
    {
        $a = $this->group(['st' => 'active'], [$this->createRule()]);
        $b = $this->group(null, [['items', 'create', null, null]]);

        $this->assertNotNull($this->access([$a, $b])->enforceValues('create', 'items', ['status' => 'whatever']));
        $this->assertNull($this->access([$a])->enforceValues('create', 'items', ['status' => 'whatever']));
    }

    public function testNonScalarValuesAreRefused(): void
    {
        $g = $this->group(['st' => 'active'], [$this->createRule()]);

        $this->assertNull($this->access([$g])->enforceValues('create', 'items', ['status' => ['active']]));
    }

    public function testTiersAndAdminsAreNeverRestricted(): void
    {
        $g = $this->group(['st' => 'active'], [$this->createRule()]);

        foreach ([1, 10, 20, 25] as $tier) {
            $this->assertSame(
                ['status' => 'pending'],
                $this->access([$g], $tier)->enforceValues('create', 'items', ['status' => 'pending']),
                "tier {$tier}"
            );
        }
    }

    public function testOtherTablesAndActionsAreLeftAlone(): void
    {
        $g = $this->group(['st' => 'active'], [$this->createRule()]);
        $a = $this->access([$g]);

        $this->assertSame(['x' => 1], $a->enforceValues('create', 'tags', ['x' => 1]));
        $this->assertSame(['status' => 'pending'], $a->enforceValues('update', 'items', ['status' => 'pending'], 1));
    }

    // ── enforce on update ─────────────────────────────────────────────────────

    public function testUpdateRefusesMovingARecordOutOfTheGroupsValues(): void
    {
        $g = $this->group(['st' => 'active'], [['items', 'update', ['status' => ['_eq' => '$GROUP.st']], ['status' => '$GROUP.st']]]);
        $a = $this->access([$g]);

        $this->assertNull($a->enforceValues('update', 'items', ['status' => 'pending'], 1));
        $this->assertSame(['status' => 'active'], $a->enforceValues('update', 'items', ['status' => 'active'], 1));
    }

    public function testUpdateLeavesUntouchedFieldsAlone(): void
    {
        $g = $this->group(['st' => 'active'], [['items', 'update', null, ['status' => '$GROUP.st']]]);

        $this->assertSame(
            ['name' => 'renamed'],
            $this->access([$g])->enforceValues('update', 'items', ['name' => 'renamed'], 1)
        );
    }

    public function testUpdateRefusesEmptyingAnEnforcedField(): void
    {
        $g = $this->group(['st' => 'active'], [['items', 'update', null, ['status' => '$GROUP.st']]]);

        $this->assertNull($this->access([$g])->enforceValues('update', 'items', ['status' => ''], 1));
        $this->assertNull($this->access([$g])->enforceValues('update', 'items', ['status' => null], 1));
    }

    public function testUpdateRuleCountsOnlyWhereItsFilterMatchesTheRecord(): void
    {
        $free     = ['items', 'update', ['status' => ['_eq' => 'active']],   null];
        $enforced = ['items', 'update', ['status' => ['_eq' => 'inactive']], ['status' => 'inactive']];
        $a = $this->access([$this->group(null, [$free]), $this->group(null, [$enforced])]);

        // Record 1 is active: the free rule applies and restricts nothing.
        $this->assertNotNull($a->enforceValues('update', 'items', ['status' => 'x'], 1));
        // Record 2 is inactive: only the enforcing rule applies.
        $this->assertNull($a->enforceValues('update', 'items', ['status' => 'x'], 2));
        $this->assertNotNull($a->enforceValues('update', 'items', ['status' => 'inactive'], 2));
    }

    public function testSelfWritersOwnRecordsAreNotRestricted(): void
    {
        $g = $this->group(['st' => 'active'], [['items', 'update', null, ['status' => '$GROUP.st']]]);
        static::$db->exec('UPDATE items SET creator = 52 WHERE id = 3');
        $a = $this->access([$g], 25);

        $this->assertNotNull($a->enforceValues('update', 'items', ['status' => 'pending'], 3));
    }

    // ── Write paths ───────────────────────────────────────────────────────────

    public function testSaveRecordFillsInRefusesAndKeepsTheRecordInScope(): void
    {
        $this->group(['st' => 'active'], [
            $this->createRule(),
            ['items', 'update', ['status' => ['_eq' => '$GROUP.st']], ['status' => '$GROUP.st']],
            ['items', 'read', ['status' => ['_eq' => '$GROUP.st']], null],
        ]);

        // create: value filled in
        $res = $this->call('Record', 'saveRecord', [], ['tb' => 'items', 'core' => ['name' => 'filled']]);
        $this->assertSame('success', $res['status'], json_encode($res));
        $this->assertSame('active', $this->itemStatus((int) $res['id']));

        // create: forged value refused, nothing written
        $before = (int) static::$db->query('SELECT COUNT(*) AS n FROM items', [], 'read')[0]['n'];
        $res = $this->call('Record', 'saveRecord', [], ['tb' => 'items', 'core' => ['name' => 'forged', 'status' => 'pending']]);
        $this->assertSame('value_not_allowed', $res['code'] ?? null, json_encode($res));
        $this->assertSame($before, (int) static::$db->query('SELECT COUNT(*) AS n FROM items', [], 'read')[0]['n']);

        // update: moving the record out of the scope is refused, an in-scope change goes through
        $res = $this->call('Record', 'saveRecord', [], ['tb' => 'items', 'id' => 1, 'core' => ['status' => 'pending']]);
        $this->assertSame('value_not_allowed', $res['code'] ?? null, json_encode($res));
        $this->assertSame('active', $this->itemStatus(1));

        $res = $this->call('Record', 'saveRecord', [], ['tb' => 'items', 'id' => 1, 'core' => ['name' => 'renamed']]);
        $this->assertSame('success', $res['status'], json_encode($res));
    }

    public function testDuplicateGetsTheEnforcedValue(): void
    {
        $this->group(['st' => 'active'], [
            $this->createRule(),
            ['items', 'read', null, null],
        ]);

        // Record 4 is pending; the copy is a new record and must carry the group's value.
        $res = $this->call('Record', 'duplicateRecord', ['tb' => 'items', 'id' => 4]);
        $this->assertSame('success', $res['status'], json_encode($res));
        $this->assertSame('active', $this->itemStatus((int) $res['id']));
    }

    public function testDuplicateIsRefusedWhenSeveralValuesArePossible(): void
    {
        $this->group(null, [$this->createRule(['status' => ['draft', 'active']]), ['items', 'read', null, null]]);

        $res = $this->call('Record', 'duplicateRecord', ['tb' => 'items', 'id' => 4]);
        $this->assertSame('value_not_allowed', $res['code'] ?? null, json_encode($res));
    }

    // ── Import ────────────────────────────────────────────────────────────────

    private function importCsv(string $csv, array $mapping): array
    {
        $id = bin2hex(random_bytes(8));
        file_put_contents(sys_get_temp_dir() . '/bdus_import_' . $id, $csv);
        return $this->call('Import', 'importData', [], [
            'temp_id' => $id, 'type' => 'csv', 'tb' => 'items', 'mapping' => $mapping, 'key_field' => 'name',
        ]);
    }

    private function countItems(string $where): int
    {
        return (int) static::$db->query("SELECT COUNT(*) AS n FROM items WHERE {$where}", [], 'read')[0]['n'];
    }

    public function testImportFillsInTheEnforcedValueOnNewRows(): void
    {
        // Importing needs the right to edit the table as well as to create in it.
        $this->group(['st' => 'active'], [$this->createRule(), ['items', 'update', null, null], ['items', 'read', null, null]]);

        $res = $this->importCsv("name\nimp-a\nimp-b\n", ['name' => 'name']);

        $this->assertSame('ok_import_data', $res['code'] ?? null, json_encode($res));
        $this->assertSame(2, $this->countItems("name LIKE 'imp-%' AND status = 'active'"));
    }

    public function testImportWithAForbiddenValueIsCancelledAsAWhole(): void
    {
        // Importing needs the right to edit the table as well as to create in it.
        $this->group(['st' => 'active'], [$this->createRule(), ['items', 'update', null, null], ['items', 'read', null, null]]);

        $res = $this->importCsv("name,status\nimp-ok,active\nimp-bad,pending\n", ['name' => 'name', 'status' => 'status']);

        $this->assertSame('value_not_allowed', $res['code'] ?? null, json_encode($res));
        $this->assertSame(0, $this->countItems("name LIKE 'imp-%'"));
    }

    public function testImportCannotMoveAnExistingRecordOutOfScope(): void
    {
        static::$db->exec("UPDATE items SET name = 'imp-one' WHERE id = 1");
        $this->group(['st' => 'active'], [
            ['items', 'update', ['status' => ['_eq' => '$GROUP.st']], ['status' => '$GROUP.st']],
        ]);

        $res = $this->importCsv("name,status\nimp-one,pending\n", ['name' => 'name', 'status' => 'status']);

        $this->assertSame('value_not_allowed', $res['code'] ?? null, json_encode($res));
        $this->assertSame('active', $this->itemStatus(1));
    }

    // ── Groups controller ─────────────────────────────────────────────────────

    public function testGroupAttrsRoundTripAndValidation(): void
    {
        $this->setPrivilege(1);
        $id = (int) $this->call('Groups', 'createGroup', [], ['name' => 'Ostia', 'attrs' => ['site' => 'Ostia', 'offices' => ['N', 'S']]])['id'];

        $g = $this->call('Groups', 'getGroup', ['id' => $id])['group'];
        $this->assertSame(['site' => 'Ostia', 'offices' => ['N', 'S']], $g['attrs']);

        // attrs left out on update = unchanged; sent empty = cleared
        $this->call('Groups', 'updateGroup', ['id' => $id], ['name' => 'Ostia 2']);
        $this->assertSame('Ostia', $this->call('Groups', 'getGroup', ['id' => $id])['group']['attrs']['site']);
        $this->call('Groups', 'updateGroup', ['id' => $id], ['name' => 'Ostia 2', 'attrs' => []]);
        $this->assertNull($this->call('Groups', 'getGroup', ['id' => $id])['group']['attrs']);

        foreach ([['bad key' => 'x'], ['k' => []], ['k' => ['a' => 'b']], ['k' => ''], ['k' => true], ['x', 'y']] as $bad) {
            $res = $this->call('Groups', 'updateGroup', ['id' => $id], ['name' => 'Ostia 2', 'attrs' => $bad]);
            $this->assertSame('invalid_attrs', $res['code'] ?? null, json_encode($bad));
        }
    }

    public function testSetRulesStoresAndValidatesEnforce(): void
    {
        $this->setPrivilege(1);
        $id = (int) $this->call('Groups', 'createGroup', [], ['name' => 'R'])['id'];
        $set = fn(array $rules) => $this->call('Groups', 'setRules', ['id' => $id], ['rules' => $rules]);

        $ok = $set([
            ['table' => 'items', 'action' => 'create', 'enforce' => ['status' => '$GROUP.st']],
            ['table' => 'items', 'action' => 'update', 'filter' => ['status' => ['_eq' => '$GROUP.st']], 'enforce' => ['status' => ['draft', '$GROUP.st']]],
            ['table' => 'items', 'action' => 'read',   'filter' => ['status' => ['_in' => '$CURRENT_GROUPS']]],
        ]);
        $this->assertSame('rules_saved', $ok['code'] ?? null, json_encode($ok));

        $rules = $this->call('Groups', 'getGroup', ['id' => $id])['group']['rules'];
        $byAction = array_column($rules, null, 'action');
        $this->assertSame(['status' => '$GROUP.st'], $byAction['create']['enforce']);
        $this->assertSame(['status' => ['draft', '$GROUP.st']], $byAction['update']['enforce']);
        $this->assertArrayNotHasKey('enforce', $byAction['read']);

        $cases = [
            'invalid_rule'    => [['table' => 'items', 'action' => 'read',   'enforce' => ['status' => 'x']]],
            'invalid_enforce' => [['table' => 'items', 'action' => 'create', 'enforce' => ['nope' => 'x']]],
        ];
        $cases['invalid_enforce'][] = ['table' => 'items', 'action' => 'create', 'enforce' => ['status' => '$GROUP_oops']];
        $cases['invalid_enforce'][] = ['table' => 'items', 'action' => 'create', 'enforce' => ['creator' => 'x']];
        $cases['invalid_enforce'][] = ['table' => 'items', 'action' => 'create', 'enforce' => ['x', 'y']];

        foreach ($cases as $code => $list) {
            foreach ($list as $rule) {
                $res = $set([$rule]);
                $this->assertSame($code, $res['code'] ?? null, json_encode($rule) . ' → ' . json_encode($res));
            }
        }
        $this->assertSame('invalid_rule', $set([['table' => 'tags', 'action' => 'create', 'enforce' => ['label' => 'x']]])['code'] ?? null, 'plugin table');
    }

    // ── Migration M049 ────────────────────────────────────────────────────────

    public function testRulesKeepWorkingBeforeM049AndTheMigrationIsIdempotent(): void
    {
        $g = $this->group(['st' => 'active'], [['items', 'read', ['status' => ['_eq' => 'active']], null]]);

        static::$db->exec('ALTER TABLE bdus_group_rules DROP COLUMN enforce');
        static::$db->exec('ALTER TABLE bdus_groups DROP COLUMN attrs');
        try {
            // Plain rules still apply; variables find no attributes yet.
            $this->assertSame([1, 3, 5], $this->readable($this->access([$g])));
            $this->assertSame(['x' => 1], $this->access([$g])->enforceValues('create', 'items', ['x' => 1]));

            // The Groups endpoints still answer on the old shape.
            $this->setPrivilege(1);
            $res = $this->call('Groups', 'getGroup', ['id' => $g]);
            $this->assertSame('success', $res['status'], json_encode($res));
            $res = $this->call('Groups', 'setRules', ['id' => $g], ['rules' => [['table' => 'items', 'action' => 'read']]]);
            $this->assertSame('rules_saved', $res['code'] ?? null, json_encode($res));
        } finally {
            $manage = new Manage(static::$db);
            M049_AddAttrsToGroups::run($manage);
            M049_AddAttrsToGroups::run($manage);
        }

        $this->assertTrue((new Manage(static::$db))->columnExists('bdus_groups', 'attrs'));
        $this->assertTrue((new Manage(static::$db))->columnExists('bdus_group_rules', 'enforce'));
    }
}
