<?php

namespace Tests\Integration;

use Tests\Support\BdusTestCase;

/**
 * Table-less read gates (Info, Vocabularies, MyHistory, Widget) for a user at
 * level Enter (39): the level alone grants nothing, a group that reads some
 * table opens them. MyHistory shows such a user only the edits of the records
 * their groups let them read; levels Reader and above keep the whole history.
 */
class EnterGroupReadGateTest extends BdusTestCase
{
    protected static function seedData(): void
    {
        parent::seedData();
        $now = time();
        static::$db->execInTransaction(
            "INSERT INTO bdus_versions (userid, time, tb, rowid, content, editsql, editvalues, operation)
             VALUES
               (1, {$now}, 'items', 1, '{}', NULL, NULL, 'update'),
               (1, {$now}, 'items', 2, '{}', NULL, NULL, 'update'),
               (1, {$now}, 'tags',  1, '{}', NULL, NULL, 'update')"
        );
    }

    protected function setUp(): void
    {
        static::$db->exec("INSERT INTO bdus_users (id, name, email, password, privilege) VALUES (61, 'ent', 'ent@example.com', 'x', 39)");
        $this->actAs(39);
    }

    protected function tearDown(): void
    {
        static::$db->exec('DELETE FROM bdus_user_groups');
        static::$db->exec('DELETE FROM bdus_group_rules');
        static::$db->exec('DELETE FROM bdus_groups');
        static::$db->exec('DELETE FROM bdus_users WHERE id = 61');
        $this->setPrivilege(1);
    }

    private function actAs(int $tier): void
    {
        \Auth\CurrentUser::set(['id' => 61, 'name' => 'ent', 'email' => 'ent@example.com', 'privilege' => $tier, 'app' => 'test']);
    }

    private function joinGroup(string $table, ?array $filter = null): void
    {
        $g = (int) static::$db->query("INSERT INTO bdus_groups (name) VALUES ('G')", [], 'id');
        static::$db->query(
            'INSERT INTO bdus_group_rules (group_id, table_name, action, filter) VALUES (?, ?, ?, ?)',
            [$g, $table, 'read', $filter === null ? null : json_encode($filter)],
            'boolean'
        );
        static::$db->exec("INSERT INTO bdus_user_groups (user_id, group_id) VALUES (61, {$g})");
    }

    private function call(string $class, string $method): array
    {
        return $this->callController($this->makeController("Bdus\\Controllers\\{$class}"), $method);
    }

    public function testEnterWithoutGroupIsDenied(): void
    {
        foreach ([['Info', 'getInfo'], ['Vocabularies', 'list'], ['Vocabularies', 'usages'],
                  ['MyHistory', 'getHistory'], ['Widget', 'listWidgets']] as [$c, $m]) {
            $this->assertSame('not_enough_privilege', $this->call($c, $m)['code'] ?? null, "$c::$m");
        }
    }

    public function testEnterInAReadingGroupIsAllowed(): void
    {
        $this->joinGroup('items');
        foreach ([['Info', 'getInfo'], ['Vocabularies', 'list'], ['Vocabularies', 'usages'],
                  ['MyHistory', 'getHistory'], ['Widget', 'listWidgets']] as [$c, $m]) {
            $this->assertNotSame('not_enough_privilege', $this->call($c, $m)['code'] ?? null, "$c::$m");
        }
    }

    public function testHistoryOfAnEnterUserIsLimitedToReadableRecords(): void
    {
        $this->joinGroup('items', ['id' => ['_eq' => 1]]);
        $res = $this->call('MyHistory', 'getHistory');
        $this->assertSame('success', $res['status']);
        $this->assertSame(1, $res['total']);
        $this->assertSame('items', $res['data'][0]['tb']);
        $this->assertSame(1, (int) $res['data'][0]['rowid']);
    }

    public function testHistoryOfAReaderLevelIsUnchanged(): void
    {
        $this->actAs(30);
        $this->assertSame(3, $this->call('MyHistory', 'getHistory')['total']);
    }
}
