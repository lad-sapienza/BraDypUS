<?php

namespace Tests\Integration;

use Auth\Access;
use DB\System\Manage;
use DB\System\Migrations\M050_AddDefaultGroups;
use Tests\Support\BdusTestCase;

/**
 * Default groups (M050) and rules for every table (`*`).
 *
 * The groups Reader, Writer and Self writer must give a user at level Enter
 * exactly what the data side of the levels 30, 20 and 25 gives: same table
 * gates, same rows for every action. Fixture `items` 1-5; items 1 and 3 were
 * created by user 52, the others by 'admin'.
 */
class DefaultGroupsTest extends BdusTestCase
{
    private const WRITER = 20;
    private const SELF_W = 25;
    private const READER = 30;
    private const ENTER  = 39;

    protected function setUp(): void
    {
        static::$db->exec("UPDATE items SET creator = CASE WHEN id IN (1, 3) THEN '52' ELSE 'admin' END");
    }

    protected function tearDown(): void
    {
        static::$db->exec('DELETE FROM bdus_group_rules');
        static::$db->exec('DELETE FROM bdus_groups');
        static::$db->exec("UPDATE items SET creator = 'admin'");
        $this->setPrivilege(1);
    }

    private function seed(): void
    {
        M050_AddDefaultGroups::run(new Manage(static::$db));
    }

    private function groupId(string $name): int
    {
        return (int) static::$db->query('SELECT id FROM bdus_groups WHERE name = ?', [$name], 'read')[0]['id'];
    }

    private function access(int $tier, array $groups = []): Access
    {
        return new Access(static::$db, static::$cfg, $tier, 52, $groups);
    }

    /** What an Access lets through: table gates and the rows of `items` for each action. */
    private function profile(Access $a): array
    {
        $out = [];
        foreach (Access::ACTIONS as $action) {
            foreach (['items', 'tags', 'categories', 'reviews'] as $table) {
                $out["can $action $table"] = $a->can($action, $table);
            }
            [$sql, $vals] = $a->rowCondition($action, 'items');
            $out["rows $action"] = array_map('intval', array_column(
                static::$db->query("SELECT id FROM items WHERE {$sql} ORDER BY id", $vals, 'read') ?: [],
                'id'
            ));
        }
        return $out;
    }

    /** @dataProvider-free: one assertion per level, same profile as the group. */
    private function assertSameAsLevel(int $level, string $group): void
    {
        $this->seed();
        $this->assertSame(
            $this->profile($this->access($level)),
            $this->profile($this->access(self::ENTER, [$this->groupId($group)])),
            "level {$level} vs group {$group}"
        );
    }

    public function testReaderGroupEqualsLevelReader(): void
    {
        $this->assertSameAsLevel(self::READER, 'Reader');
    }

    public function testWriterGroupEqualsLevelWriter(): void
    {
        $this->assertSameAsLevel(self::WRITER, 'Writer');
    }

    public function testSelfWriterGroupEqualsLevelSelfWriter(): void
    {
        $this->assertSameAsLevel(self::SELF_W, 'Self writer');
        // and it is not vacuous: own records only
        $this->assertSame([1, 3], $this->profile($this->access(self::ENTER, [$this->groupId('Self writer')]))['rows update']);
    }

    public function testMigrationIsIdempotentAndLeavesNamesAlone(): void
    {
        static::$db->exec("INSERT INTO bdus_groups (name, description) VALUES ('Reader', 'mine')");
        $this->seed();
        $this->seed();

        $this->assertSame(1, (int) static::$db->query("SELECT COUNT(*) AS n FROM bdus_groups WHERE name = 'Reader'", [], 'read')[0]['n']);
        $this->assertSame('mine', static::$db->query("SELECT description FROM bdus_groups WHERE name = 'Reader'", [], 'read')[0]['description']);
        $this->assertSame(0, (int) static::$db->query('SELECT COUNT(*) AS n FROM bdus_group_rules WHERE group_id = ?', [$this->groupId('Reader')], 'read')[0]['n']);
        $this->assertSame(4, (int) static::$db->query('SELECT COUNT(*) AS n FROM bdus_group_rules WHERE group_id = ?', [$this->groupId('Writer')], 'read')[0]['n']);
    }

    public function testNobodyIsAssignedAndLevelsAreUntouched(): void
    {
        $this->seed();

        $this->assertSame(0, (int) static::$db->query('SELECT COUNT(*) AS n FROM bdus_user_groups', [], 'read')[0]['n']);
        $this->assertFalse($this->access(self::ENTER)->can('read', 'items'));
    }

    public function testAllTablesRuleOnlyReachesConfiguredDataTables(): void
    {
        $this->seed();
        $a = $this->access(self::ENTER, [$this->groupId('Writer')]);

        foreach (['bdus_users', 'bdus_groups', 'no_such_table', 'items; DROP TABLE items'] as $table) {
            foreach (Access::ACTIONS as $action) {
                $this->assertFalse($a->can($action, $table), "$action $table");
            }
        }
        $this->assertSame('1=0', $a->rowCondition('read', 'bdus_users')[0]);
    }

    public function testAllTablesAndTableRulesAreUnited(): void
    {
        $g = (int) static::$db->query("INSERT INTO bdus_groups (name) VALUES ('Mixed')", [], 'id');
        foreach ([['*', 'read', null], ['items', 'update', json_encode(['id' => ['_eq' => 2]])]] as [$tb, $action, $filter]) {
            static::$db->query('INSERT INTO bdus_group_rules (group_id, table_name, action, filter) VALUES (?, ?, ?, ?)', [$g, $tb, $action, $filter], 'boolean');
        }
        $p = $this->profile($this->access(self::ENTER, [$g]));

        $this->assertSame([1, 2, 3, 4, 5], $p['rows read']);
        $this->assertSame([2], $p['rows update']);
        $this->assertFalse($p['can create items']);
    }

    // ── Groups controller ─────────────────────────────────────────────────────

    private function setRules(int $group, array $rules): array
    {
        $this->setPrivilege(1);
        return $this->callController($this->makeController('Bdus\\Controllers\\Groups', ['id' => $group], ['rules' => $rules]), 'setRules');
    }

    public function testControllerAcceptsAllTablesRulesAndKeepsThemOnRead(): void
    {
        $g = (int) static::$db->query("INSERT INTO bdus_groups (name) VALUES ('G')", [], 'id');

        $res = $this->setRules($g, [
            ['table' => '*', 'action' => 'read'],
            ['table' => '*', 'action' => 'update', 'filter' => ['creator' => ['_eq' => '$CURRENT_USER']]],
        ]);
        $this->assertSame('rules_saved', $res['code'] ?? null, json_encode($res));

        $this->setPrivilege(1);
        $rules = $this->callController($this->makeController('Bdus\\Controllers\\Groups', ['id' => $g]), 'getGroup')['group']['rules'];
        $this->assertSame(['*'], array_values(array_unique(array_column($rules, 'table'))));
    }

    public function testControllerRefusesBadAllTablesRules(): void
    {
        $g = (int) static::$db->query("INSERT INTO bdus_groups (name) VALUES ('G')", [], 'id');

        $this->assertSame('invalid_rule', $this->setRules($g, [['table' => '*', 'action' => 'create', 'enforce' => ['status' => 'x']]])['code'] ?? null);
        $this->assertSame('invalid_filter', $this->setRules($g, [['table' => '*', 'action' => 'read', 'filter' => ['no_such_field_anywhere' => ['_eq' => 1]]]])['code'] ?? null);
        $this->assertSame('unknown_table', $this->setRules($g, [['table' => 'bdus_users', 'action' => 'read']])['code'] ?? null);
    }
}
