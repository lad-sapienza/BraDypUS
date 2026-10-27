<?php

namespace Tests\Integration;

use Auth\Authorization;
use Auth\CurrentUser;
use Tests\Support\BdusTestCase;

/**
 * Authorization::can() with a $table (#78): the tier decides first, group
 * rules can only add, and the application status still applies.
 */
class AuthorizationTableTest extends BdusTestCase
{
    protected function setUp(): void
    {
        $g = (int) static::$db->query("INSERT INTO bdus_groups (name) VALUES ('G')", [], 'id');
        foreach (['read', 'update', 'create'] as $action) {
            static::$db->query(
                "INSERT INTO bdus_group_rules (group_id, table_name, action) VALUES (?, 'items', ?)",
                [$g, $action], 'boolean'
            );
        }
        static::$db->exec("INSERT INTO bdus_users (id, name, email, password, privilege) VALUES (60, 'u', 'u@example.com', 'x', 39)");
        static::$db->exec("INSERT INTO bdus_user_groups (user_id, group_id) VALUES (60, {$g})");
        $this->actAs(39);
        Authorization::setAccessProvider(fn() => \Auth\Access::forCurrentUser(static::$db, static::$cfg));
    }

    protected function tearDown(): void
    {
        Authorization::setAppStatus('on');
        Authorization::setAccessProvider(null);
        static::$db->exec('DELETE FROM bdus_user_groups');
        static::$db->exec('DELETE FROM bdus_group_rules');
        static::$db->exec('DELETE FROM bdus_groups');
        static::$db->exec('DELETE FROM bdus_users WHERE id = 60');
        $this->setPrivilege(1);
    }

    private function actAs(int $tier): void
    {
        CurrentUser::set(['id' => 60, 'name' => 'u', 'privilege' => $tier, 'app' => 'test']);
    }

    public function testRulesGrantOnTheirTableOnly(): void
    {
        $this->assertTrue(Authorization::can('read', table: 'items'));
        $this->assertTrue(Authorization::can('preview', table: 'items'));
        $this->assertTrue(Authorization::can('edit', table: 'items'));
        $this->assertTrue(Authorization::can('add_new', table: 'items'));
        $this->assertFalse(Authorization::can('read', table: 'tags'));
        $this->assertFalse(Authorization::can('read', table: 'no_such_table'));
        $this->assertFalse(Authorization::can('read', table: null));
    }

    public function testWithoutTheTableArgumentOnlyTheTierCounts(): void
    {
        $this->assertFalse(Authorization::can('read'));
        $this->assertFalse(Authorization::can('edit'));
    }

    public function testRulesNeverGrantAdminPrivileges(): void
    {
        $this->assertFalse(Authorization::can('admin', table: 'items'));
        $this->assertFalse(Authorization::can('super_admin', table: 'items'));
        $this->assertFalse(Authorization::can('enter', table: 'items'));
    }

    public function testWithoutAProviderRulesAreIgnored(): void
    {
        Authorization::setAccessProvider(null);

        $this->assertFalse(Authorization::can('read', table: 'items'));
    }

    public function testWaitingUsersStayOut(): void
    {
        $this->actAs(40);

        $this->assertFalse(Authorization::can('read', table: 'items'));
    }

    public function testFrozenAppBlocksRuleBasedWritesButNotReads(): void
    {
        Authorization::setAppStatus('frozen');

        $this->assertTrue(Authorization::can('read', table: 'items'));
        $this->assertFalse(Authorization::can('edit', table: 'items'));
        $this->assertFalse(Authorization::can('add_new', table: 'items'));
    }

    public function testOffAppBlocksRuleBasedAccess(): void
    {
        Authorization::setAppStatus('off');

        $this->assertFalse(Authorization::can('read', table: 'items'));
    }

    public function testSelfWriterGetsNoBlanketEditFromTheTableGate(): void
    {
        // The "own records" grant needs the record's creator; the table gate must not stand in for it.
        $this->actAs(25);
        static::$db->exec('DELETE FROM bdus_group_rules');

        $this->assertFalse(Authorization::can('edit', 999, 'items'));
        $this->assertTrue(Authorization::can('edit', 60, 'items'));
    }
}
