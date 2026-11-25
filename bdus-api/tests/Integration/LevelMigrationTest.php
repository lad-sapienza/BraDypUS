<?php

namespace Tests\Integration;

use Auth\LevelMigration;
use DB\System\Manage;
use DB\System\Migrations\M050_AddDefaultGroups;
use Tests\Support\BdusTestCase;

/**
 * Moving users and API keys from a data level to Enter + system group, and back.
 * Users: 81 reader (30), 82 self writer (25), 83 writer (20), 84 super admin (1),
 * 85 enter (39). Items 1 and 2 were created by user 82.
 */
class LevelMigrationTest extends BdusTestCase
{
    protected function setUp(): void
    {
        foreach ([81 => 30, 82 => 25, 83 => 20, 84 => 1, 85 => 39] as $id => $level) {
            static::$db->exec("INSERT INTO bdus_users (id, name, email, password, privilege) VALUES ({$id}, 'u{$id}', 'u{$id}@example.com', 'x', {$level})");
        }
        static::$db->exec("UPDATE items SET creator = CASE WHEN id IN (1, 2) THEN '82' ELSE 'admin' END");
        M050_AddDefaultGroups::run(new Manage(static::$db));
    }

    protected function tearDown(): void
    {
        static::$db->exec('DELETE FROM bdus_api_keys');
        static::$db->exec('DELETE FROM bdus_user_groups WHERE user_id BETWEEN 81 AND 85');
        static::$db->exec('DELETE FROM bdus_group_rules');
        static::$db->exec('DELETE FROM bdus_groups');
        static::$db->exec('DELETE FROM bdus_users WHERE id BETWEEN 81 AND 85');
        static::$db->exec("UPDATE items SET creator = 'admin'");
    }

    private function mig(): LevelMigration
    {
        return new LevelMigration(static::$db, static::$cfg);
    }

    private function gid(string $name): int
    {
        return (int) static::$db->query('SELECT id FROM bdus_groups WHERE name = ?', [$name], 'read')[0]['id'];
    }

    private function user(int $id): array
    {
        return static::$db->query('SELECT * FROM bdus_users WHERE id = ?', [$id], 'read')[0];
    }

    private function groupsOf(int $id): array
    {
        return array_map('intval', array_column(
            static::$db->query('SELECT group_id FROM bdus_user_groups WHERE user_id = ? ORDER BY group_id', [$id], 'read') ?: [], 'group_id'
        ));
    }

    private function key(string $label, int $level, ?int $group = null, ?int $revoked = null): int
    {
        static::$db->query(
            'INSERT INTO bdus_api_keys (key_hash, label, created_at, privilege, group_id, revoked_at) VALUES (?, ?, 1, ?, ?, ?)',
            ['h_' . $label, $label, $level, $group, $revoked], 'boolean'
        );
        return (int) static::$db->query('SELECT id FROM bdus_api_keys WHERE label = ?', [$label], 'read')[0]['id'];
    }

    public function testEachDataLevelMovesToEnterAndItsGroupAndBack(): void
    {
        foreach ([81 => [30, 'Reader'], 82 => [25, 'Self writer'], 83 => [20, 'Writer']] as $id => [$level, $group]) {
            $r = $this->mig()->moveUser($id);
            $this->assertTrue($r['ok'], json_encode($r));
            $u = $this->user($id);
            $this->assertSame(39, (int) $u['privilege']);
            $this->assertSame($level, (int) $u['legacy_privilege']);
            $this->assertSame($this->gid($group), (int) $u['legacy_group_id']);
            $this->assertSame([$this->gid($group)], $this->groupsOf($id));

            $r = $this->mig()->restoreUser($id);
            $this->assertTrue($r['ok'], json_encode($r));
            $u = $this->user($id);
            $this->assertSame($level, (int) $u['privilege']);
            $this->assertNull($u['legacy_privilege']);
            $this->assertNull($u['legacy_group_id']);
            $this->assertSame([], $this->groupsOf($id));
        }
    }

    public function testAUserAlreadyInTheGroupKeepsItWhenRestored(): void
    {
        static::$db->query('INSERT INTO bdus_user_groups (user_id, group_id) VALUES (?, ?)', [83, $this->gid('Writer')], 'boolean');

        $this->assertTrue($this->mig()->moveUser(83)['ok']);
        $this->assertNull($this->user(83)['legacy_group_id']);
        $this->assertTrue($this->mig()->restoreUser(83)['ok']);

        $this->assertSame([$this->gid('Writer')], $this->groupsOf(83));
    }

    public function testOtherGroupsOfTheUserAreKept(): void
    {
        static::$db->query("INSERT INTO bdus_groups (name) VALUES ('Mine')", [], 'boolean');
        $mine = $this->gid('Mine');
        static::$db->query('INSERT INTO bdus_user_groups (user_id, group_id) VALUES (?, ?)', [81, $mine], 'boolean');

        $this->mig()->moveUser(81);
        $this->assertEqualsCanonicalizing([$mine, $this->gid('Reader')], $this->groupsOf(81));
        $this->mig()->restoreUser(81);

        $this->assertSame([$mine], $this->groupsOf(81));
    }

    public function testNothingIsWrittenWhenTheGroupIsNotEquivalent(): void
    {
        static::$db->query("DELETE FROM bdus_group_rules WHERE group_id = ? AND action = 'delete'", [$this->gid('Writer')], 'boolean');
        $before = static::$db->query('SELECT * FROM bdus_users ORDER BY id', [], 'read');

        $r = $this->mig()->moveUser(83);

        $this->assertFalse($r['ok']);
        $this->assertSame('not_equivalent', $r['code']);
        $this->assertContains(['table' => 'items', 'action' => 'delete', 'kind' => 'gate', 'level_only' => 1, 'group_only' => 0], $r['differences']);
        $this->assertSame($before, static::$db->query('SELECT * FROM bdus_users ORDER BY id', [], 'read'));
        $this->assertSame([], $this->groupsOf(83));
    }

    public function testOnlyDataLevelsMoveAndTheGroupMustExist(): void
    {
        $this->assertSame('not_data_level', $this->mig()->moveUser(84)['code']);   // super admin
        $this->assertSame('not_data_level', $this->mig()->moveUser(85)['code']);   // enter
        $this->assertSame('user_not_found', $this->mig()->moveUser(9999)['code']);

        static::$db->exec('DELETE FROM bdus_group_rules');
        static::$db->exec('DELETE FROM bdus_groups');
        $this->assertSame('group_missing', $this->mig()->moveUser(81)['code']);
        $this->assertSame(30, (int) $this->user(81)['privilege']);
    }

    public function testRestoreRefusesWhenNotMovedOrWhenTheLevelWasChangedByHand(): void
    {
        $this->assertSame('not_moved', $this->mig()->restoreUser(81)['code']);
        $this->assertSame('user_not_found', $this->mig()->restoreUser(9999)['code']);

        $this->mig()->moveUser(81);
        static::$db->exec('UPDATE bdus_users SET privilege = 10 WHERE id = 81');   // an admin edited the user

        $this->assertSame('level_changed', $this->mig()->restoreUser(81)['code']);
        $this->assertSame(10, (int) $this->user(81)['privilege']);
    }

    public function testAKeyMovesToEnterAndItsGroupAndBack(): void
    {
        $k = $this->key('reader key', 30);

        $r = $this->mig()->moveKey($k);
        $this->assertTrue($r['ok'], json_encode($r));
        $row = static::$db->query('SELECT * FROM bdus_api_keys WHERE id = ?', [$k], 'read')[0];
        $this->assertSame(39, (int) $row['privilege']);
        $this->assertSame($this->gid('Reader'), (int) $row['group_id']);
        $this->assertSame(30, (int) $row['legacy_privilege']);

        $this->assertTrue($this->mig()->restoreKey($k)['ok']);
        $row = static::$db->query('SELECT * FROM bdus_api_keys WHERE id = ?', [$k], 'read')[0];
        $this->assertSame(30, (int) $row['privilege']);
        $this->assertNull($row['group_id']);
        $this->assertNull($row['legacy_privilege']);
    }

    public function testAKeyWithItsOwnGroupOrRevokedIsNeverMoved(): void
    {
        $own = $this->key('scoped', 30, $this->gid('Writer'));
        $rev = $this->key('revoked', 30, null, 5);

        $this->assertSame('key_has_group', $this->mig()->moveKey($own)['code']);
        $this->assertSame('api_key_revoked', $this->mig()->moveKey($rev)['code']);
        $this->assertSame('not_data_level', $this->mig()->moveKey($this->key('admin key', 10))['code']);
        $this->assertSame('key_not_found', $this->mig()->moveKey(9999)['code']);

        $row = static::$db->query('SELECT privilege, group_id FROM bdus_api_keys WHERE id = ?', [$own], 'read')[0];
        $this->assertSame([30, $this->gid('Writer')], [(int) $row['privilege'], (int) $row['group_id']]);
    }

    public function testAKeyEditedAfterTheMoveIsNotRestored(): void
    {
        $k = $this->key('k', 20);
        $this->mig()->moveKey($k);
        static::$db->query("INSERT INTO bdus_groups (name) VALUES ('Other')", [], 'boolean');
        static::$db->query('UPDATE bdus_api_keys SET group_id = ? WHERE id = ?', [$this->gid('Other'), $k], 'boolean');

        $this->assertSame('level_changed', $this->mig()->restoreKey($k)['code']);
        $this->assertSame('not_moved', $this->mig()->restoreKey($this->key('never', 30))['code']);
    }

    public function testMoveAllMovesTheEquivalentAndListsTheRest(): void
    {
        static::$db->query("DELETE FROM bdus_group_rules WHERE group_id = ? AND action = 'delete'", [$this->gid('Writer')], 'boolean');
        $k = $this->key('reader key', 30);

        $dry = $this->mig()->moveAll(true);
        $this->assertTrue($dry['dry_run']);
        $this->assertSame(30, (int) $this->user(81)['privilege'], 'a dry run writes nothing');

        $res = $this->mig()->moveAll();

        $moved = array_map(fn($m) => $m['type'] . $m['id'], $res['moved']);
        $this->assertEqualsCanonicalizing(['user81', 'user82', 'key' . $k], $moved);
        $this->assertEqualsCanonicalizing($moved, array_map(fn($m) => $m['type'] . $m['id'], $dry['moved']));
        $this->assertSame(['not_equivalent'], array_column($res['refused'], 'code'));
        $this->assertSame(83, $res['refused'][0]['id']);
        $this->assertSame(20, (int) $this->user(83)['privilege']);
        $this->assertSame(39, (int) $this->user(81)['privilege']);

        // run again: nothing left to move
        $this->assertSame([], $this->mig()->moveAll()['moved']);
    }
}
