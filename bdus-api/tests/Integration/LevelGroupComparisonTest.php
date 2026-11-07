<?php

namespace Tests\Integration;

use Auth\Verify\LevelGroupComparison;
use DB\System\Manage;
use DB\System\Migrations\M050_AddDefaultGroups;
use Tests\Support\BdusTestCase;

/**
 * The read-only comparison of privilege levels with the system groups (M050).
 * Users: 81 reader (30), 82 self writer (25), 83 writer (20), 84 super admin (1),
 * 85 enter (39). Items 1 and 2 were created by user 82.
 */
class LevelGroupComparisonTest extends BdusTestCase
{
    protected function setUp(): void
    {
        foreach ([81 => 30, 82 => 25, 83 => 20, 84 => 1, 85 => 39] as $id => $level) {
            static::$db->exec("INSERT INTO bdus_users (id, name, email, password, privilege) VALUES ({$id}, 'u{$id}', 'u{$id}@example.com', 'x', {$level})");
        }
        static::$db->exec("UPDATE items SET creator = CASE WHEN id IN (1, 2) THEN '82' ELSE 'admin' END");
    }

    protected function tearDown(): void
    {
        static::$db->exec('DELETE FROM bdus_api_keys');
        static::$db->exec('DELETE FROM bdus_group_rules');
        static::$db->exec('DELETE FROM bdus_groups');
        static::$db->exec('DELETE FROM bdus_users WHERE id BETWEEN 81 AND 85');
        static::$db->exec("UPDATE items SET creator = 'admin'");
    }

    private function run_(): array
    {
        return (new LevelGroupComparison(static::$db, static::$cfg))->run();
    }

    private function seed(): void
    {
        M050_AddDefaultGroups::run(new Manage(static::$db));
    }

    private function user(array $report, int $id): array
    {
        return array_values(array_filter($report['users'], fn($u) => $u['id'] === $id))[0];
    }

    public function testLevelsAndTheirSystemGroupsAreEquivalent(): void
    {
        $this->seed();
        $r = $this->run_();

        $this->assertTrue($r['summary']['ok'], json_encode($r['summary']));
        $this->assertSame(3, $r['summary']['comparable']);
        $this->assertSame(3, $r['summary']['equivalent']);
        foreach ([81 => 'Reader', 82 => 'Self writer', 83 => 'Writer'] as $id => $group) {
            $u = $this->user($r, $id);
            $this->assertSame($group, $u['group']);
            $this->assertTrue($u['equivalent'], "user {$id}");
        }
        // levels with no group are only counted
        $this->assertNull($this->user($r, 84)['equivalent']);
        $this->assertNull($this->user($r, 85)['equivalent']);
        $this->assertNull($r['levels'][1]['group']);
    }

    public function testADifferenceIsReportedWithItsTableAndAction(): void
    {
        $this->seed();
        $writer = (int) static::$db->query("SELECT id FROM bdus_groups WHERE name = 'Writer'", [], 'read')[0]['id'];
        static::$db->query("DELETE FROM bdus_group_rules WHERE group_id = ? AND action = 'delete'", [$writer], 'boolean');

        $r = $this->run_();
        $u = $this->user($r, 83);

        $this->assertFalse($r['summary']['ok']);
        $this->assertSame(1, $r['summary']['different']);
        $this->assertFalse($u['equivalent']);
        $this->assertContains(['table' => 'items', 'action' => 'delete', 'kind' => 'gate', 'level_only' => 1, 'group_only' => 0], $u['differences']);
        // the other levels are untouched
        $this->assertTrue($this->user($r, 81)['equivalent']);
    }

    public function testRowsThatOnlyOneSideSeesAreCounted(): void
    {
        $this->seed();
        $self = (int) static::$db->query("SELECT id FROM bdus_groups WHERE name = 'Self writer'", [], 'read')[0]['id'];
        // the group lets its members update everything, the level only their own records
        static::$db->query("UPDATE bdus_group_rules SET filter = NULL WHERE group_id = ? AND action = 'update'", [$self], 'boolean');

        $d = $this->user($this->run_(), 82)['differences'];

        $this->assertContains(['table' => 'items', 'action' => 'update', 'kind' => 'rows', 'level_only' => 0, 'group_only' => 3], $d);
    }

    public function testMissingGroupsAreReportedAndNothingIsWritten(): void
    {
        $users = static::$db->query('SELECT id, privilege FROM bdus_users ORDER BY id', [], 'read');
        $r = $this->run_();   // no groups at all

        $this->assertFalse($r['summary']['ok']);
        $this->assertEqualsCanonicalizing(['Reader', 'Self writer', 'Writer'], $r['summary']['missing_groups']);
        $this->assertSame($users, static::$db->query('SELECT id, privilege FROM bdus_users ORDER BY id', [], 'read'));
        $this->assertSame(0, (int) static::$db->query('SELECT COUNT(*) AS n FROM bdus_groups', [], 'read')[0]['n']);
    }

    public function testKeysWithALevelAndNoGroupAreListed(): void
    {
        $this->seed();
        static::$db->exec("INSERT INTO bdus_api_keys (key_hash, label, created_at, privilege) VALUES ('h1', 'old reader key', 1, 30), ('h2', 'admin key', 1, 10), ('h3', 'revoked', 1, 20)");
        static::$db->exec("UPDATE bdus_api_keys SET revoked_at = 5 WHERE label = 'revoked'");

        $keys = $this->run_()['keys'];

        $this->assertSame(['old reader key'], array_column($keys, 'label'));
        $this->assertSame(30, $keys[0]['level']);
    }
}
