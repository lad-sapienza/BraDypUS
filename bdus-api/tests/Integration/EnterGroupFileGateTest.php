<?php

namespace Tests\Integration;

use Tests\Support\BdusTestCase;

/**
 * Editing file metadata (update, replace, sort) for a user at level Enter (39):
 * the level grants nothing, a group that may update some table does, as for
 * uploading, linking and deleting files in Record.
 */
class EnterGroupFileGateTest extends BdusTestCase
{
    protected function setUp(): void
    {
        static::$db->exec("INSERT INTO bdus_users (id, name, email, password, privilege) VALUES (62, 'ent', 'ent2@example.com', 'x', 39)");
        \Auth\CurrentUser::set(['id' => 62, 'name' => 'ent', 'email' => 'ent2@example.com', 'privilege' => 39, 'app' => 'test']);
    }

    protected function tearDown(): void
    {
        static::$db->exec('DELETE FROM bdus_user_groups');
        static::$db->exec('DELETE FROM bdus_group_rules');
        static::$db->exec('DELETE FROM bdus_groups');
        static::$db->exec('DELETE FROM bdus_users WHERE id = 62');
        $this->setPrivilege(1);
    }

    private function joinGroup(string $action): void
    {
        $g = (int) static::$db->query("INSERT INTO bdus_groups (name) VALUES ('G')", [], 'id');
        static::$db->query(
            'INSERT INTO bdus_group_rules (group_id, table_name, action, filter) VALUES (?, ?, ?, NULL)',
            [$g, 'items', $action],
            'boolean'
        );
        static::$db->exec("INSERT INTO bdus_user_groups (user_id, group_id) VALUES (62, {$g})");
    }

    private function codes(): array
    {
        $out = [];
        foreach (['updateFile', 'replaceFile', 'sortFiles'] as $m) {
            $out[$m] = $this->callController($this->makeController('Bdus\\Controllers\\File'), $m)['code'] ?? null;
        }
        return $out;
    }

    public function testEnterWithoutGroupIsDenied(): void
    {
        foreach ($this->codes() as $m => $code) {
            $this->assertSame('not_enough_privilege', $code, $m);
        }
    }

    public function testReadOnlyGroupIsDenied(): void
    {
        $this->joinGroup('read');
        foreach ($this->codes() as $m => $code) {
            $this->assertSame('not_enough_privilege', $code, $m);
        }
    }

    public function testGroupThatUpdatesIsAllowed(): void
    {
        $this->joinGroup('update');
        foreach ($this->codes() as $m => $code) {
            $this->assertNotSame('not_enough_privilege', $code, $m);
        }
    }
}
