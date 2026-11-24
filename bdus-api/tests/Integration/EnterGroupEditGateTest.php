<?php

namespace Tests\Integration;

use Tests\Support\BdusTestCase;

/**
 * Table-less edit gates (charts, Zotero search, Pleiades, GeoFace colour field)
 * for a user at level Enter (39): a group that may update the table opens them,
 * a read-only group or no group does not.
 */
class EnterGroupEditGateTest extends BdusTestCase
{
    private const CALLS = [
        ['Chart', 'saveChart'],
        ['Zotero', 'search'],
        ['Pleiades', 'search'],
        ['Pleiades', 'getPlace'],
        ['Geoface', 'saveColorField'],
    ];

    protected function setUp(): void
    {
        static::$db->exec("INSERT INTO bdus_users (id, name, email, password, privilege) VALUES (64, 'ent', 'ent4@example.com', 'x', 39)");
        \Auth\CurrentUser::set(['id' => 64, 'name' => 'ent', 'email' => 'ent4@example.com', 'privilege' => 39, 'app' => 'test']);
    }

    protected function tearDown(): void
    {
        static::$db->exec('DELETE FROM bdus_user_groups');
        static::$db->exec('DELETE FROM bdus_group_rules');
        static::$db->exec('DELETE FROM bdus_groups');
        static::$db->exec('DELETE FROM bdus_users WHERE id = 64');
        $this->setPrivilege(1);
    }

    private function joinGroup(string $action): void
    {
        $g = (int) static::$db->query("INSERT INTO bdus_groups (name) VALUES ('G')", [], 'id');
        static::$db->exec("INSERT INTO bdus_group_rules (group_id, table_name, action) VALUES ({$g}, 'items', '{$action}')");
        static::$db->exec("INSERT INTO bdus_user_groups (user_id, group_id) VALUES (64, {$g})");
    }

    private function codes(): array
    {
        $out = [];
        foreach (self::CALLS as [$c, $m]) {
            $ctrl = $this->makeController("Bdus\\Controllers\\{$c}", ['tb' => 'items']);
            $out["$c::$m"] = $this->callController($ctrl, $m)['code'] ?? null;
        }
        return $out;
    }

    public function testEnterWithoutGroupOrWithReadOnlyGroupIsDenied(): void
    {
        foreach ($this->codes() as $call => $code) {
            $this->assertSame('not_enough_privilege', $code, $call);
        }
        $this->joinGroup('read');
        foreach ($this->codes() as $call => $code) {
            $this->assertSame('not_enough_privilege', $code, "$call (read-only group)");
        }
    }

    public function testGroupThatUpdatesIsAllowed(): void
    {
        $this->joinGroup('update');
        foreach ($this->codes() as $call => $code) {
            $this->assertNotSame('not_enough_privilege', $code, $call);
        }
    }
}
