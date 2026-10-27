<?php

namespace Tests\Integration;

use Auth\Access;
use Tests\Support\BdusTestCase;

/**
 * Tests for Auth\Access (#78): tier baseline, group rules, table locks.
 *
 * Fixture table `items` (ids 1-5): status active, inactive, active, pending,
 * active; creator 'admin'.
 */
class AccessTest extends BdusTestCase
{
    private const SUPERADM = 1;
    private const ADM      = 10;
    private const WRITER   = 20;
    private const SELF_W   = 25;
    private const READER   = 30;
    private const ENTER    = 39;
    private const WAITING  = 40;

    protected function tearDown(): void
    {
        static::$db->exec('DELETE FROM bdus_group_rules');
        static::$db->exec('DELETE FROM bdus_table_locks');
        static::$db->exec('DELETE FROM bdus_groups');
        static::$db->exec("DELETE FROM items WHERE id > 5");
        static::$db->exec("UPDATE items SET creator = 'admin'");
    }

    // ── Helpers ───────────────────────────────────────────────────────────────

    private function access(int $tier, array $groups = [], int $userId = 7): Access
    {
        return new Access(static::$db, static::$cfg, $tier, $userId, $groups);
    }

    private function group(string $name): int
    {
        return (int) static::$db->query(
            'INSERT INTO bdus_groups (name) VALUES (?)', [$name], 'id'
        );
    }

    private function rule(int $group, string $table, string $action, ?array $filter = null): void
    {
        static::$db->query(
            'INSERT INTO bdus_group_rules (group_id, table_name, action, filter) VALUES (?, ?, ?, ?)',
            [$group, $table, $action, $filter === null ? null : json_encode($filter)],
            'boolean'
        );
    }

    private function lock(string $table, array|string $filter): void
    {
        static::$db->query(
            'INSERT INTO bdus_table_locks (table_name, filter) VALUES (?, ?)',
            [$table, is_string($filter) ? $filter : json_encode($filter)],
            'boolean'
        );
    }

    /** Ids of `items` selected by the row condition of $action. */
    private function ids(Access $a, string $action): array
    {
        [$sql, $vals] = $a->rowCondition($action, 'items');
        $rows = static::$db->query(
            "SELECT id FROM items WHERE {$sql} ORDER BY id", $vals, 'read'
        ) ?: [];
        return array_map('intval', array_column($rows, 'id'));
    }

    // ── Tier baseline (no groups, rules or locks: behaviour as before) ────────

    public function testAdminsBypassEverything(): void
    {
        $this->lock('items', ['status' => ['_eq' => 'inactive']]);

        foreach ([self::SUPERADM, self::ADM] as $tier) {
            $a = $this->access($tier);
            foreach (Access::ACTIONS as $action) {
                $this->assertTrue($a->can($action, 'items'), "$tier $action");
            }
            $this->assertSame([1, 2, 3, 4, 5], $this->ids($a, 'update'));
            $this->assertTrue($a->rowAllowed('delete', 'items', 2));
        }
    }

    public function testWriterTierWithoutRulesIsUnchanged(): void
    {
        $a = $this->access(self::WRITER);

        foreach (Access::ACTIONS as $action) {
            $this->assertTrue($a->can($action, 'items'));
            $this->assertSame([1, 2, 3, 4, 5], $this->ids($a, $action));
        }
    }

    public function testReaderTierReadsButCannotWrite(): void
    {
        $a = $this->access(self::READER);

        $this->assertTrue($a->can('read', 'items'));
        $this->assertSame([1, 2, 3, 4, 5], $this->ids($a, 'read'));
        foreach (['create', 'update', 'delete'] as $action) {
            $this->assertFalse($a->can($action, 'items'), $action);
            $this->assertSame([], $this->ids($a, $action), $action);
        }
    }

    public function testEnterTierWithoutGroupsHasNoDataAccess(): void
    {
        $a = $this->access(self::ENTER);

        foreach (Access::ACTIONS as $action) {
            $this->assertFalse($a->can($action, 'items'));
            $this->assertSame(['1=0', []], $a->rowCondition($action, 'items'));
        }
        $this->assertFalse($a->rowAllowed('read', 'items', 1));
    }

    public function testSelfWriterUpdatesOnlyOwnRecords(): void
    {
        static::$db->exec("UPDATE items SET creator = '7' WHERE id IN (2, 4)");
        $a = $this->access(self::SELF_W, [], 7);

        $this->assertTrue($a->can('create', 'items'));
        $this->assertSame([1, 2, 3, 4, 5], $this->ids($a, 'read'));
        $this->assertSame([2, 4], $this->ids($a, 'update'));
        $this->assertSame([2, 4], $this->ids($a, 'delete'));
        $this->assertTrue($a->can('update', 'items'));
    }

    public function testWaitingUsersGetNothingEvenWithGroups(): void
    {
        $g = $this->group('G');
        $this->rule($g, 'items', 'read');

        $a = $this->access(self::WAITING, [$g]);

        $this->assertFalse($a->can('read', 'items'));
        $this->assertSame([], $this->ids($a, 'read'));
    }

    public function testUnknownTableActionAndUnsafeNamesAreDenied(): void
    {
        $g = $this->group('G');
        $this->rule($g, 'items', 'read');
        $a = $this->access(self::ENTER, [$g]);

        $this->assertFalse($a->can('read', 'no_such_table'));
        $this->assertFalse($a->can('read', 'items; DROP TABLE items'));
        $this->assertFalse($a->can('explode', 'items'));
        $this->assertSame(['1=0', []], $a->rowCondition('read', 'no_such_table'));
        $this->assertSame(['1=0', []], $a->rowCondition('update', 'items; DROP TABLE items'));
        $this->assertFalse($a->rowAllowed('read', 'no_such_table', 1));
    }

    public function testRowAllowedNeverInterpolatesAnUnusableTableName(): void
    {
        foreach ([self::ADM, self::WRITER, self::ENTER] as $tier) {
            $this->assertFalse($this->access($tier)->rowAllowed('read', 'items; DROP TABLE items', 1), "tier $tier");
            $this->assertFalse($this->access($tier)->rowAllowed('read', 'no_such_table', 1), "tier $tier");
        }
        $this->assertSame(5, (int) static::$db->query('SELECT COUNT(*) AS n FROM items', [], 'read')[0]['n']);
    }

    // ── Group rules ───────────────────────────────────────────────────────────

    public function testRuleWithFilterRestrictsRowsForEnterTier(): void
    {
        $g = $this->group('Reviewers');
        $this->rule($g, 'items', 'read', ['status' => ['_eq' => 'active']]);

        $a = $this->access(self::ENTER, [$g]);

        $this->assertTrue($a->can('read', 'items'));
        $this->assertFalse($a->can('update', 'items'));
        $this->assertFalse($a->can('read', 'tags'));
        $this->assertSame([1, 3, 5], $this->ids($a, 'read'));
        $this->assertTrue($a->rowAllowed('read', 'items', 1));
        $this->assertFalse($a->rowAllowed('read', 'items', 2));
    }

    public function testRuleWithoutFilterGrantsAllRows(): void
    {
        $g = $this->group('All');
        $this->rule($g, 'items', 'read');

        $this->assertSame([1, 2, 3, 4, 5], $this->ids($this->access(self::ENTER, [$g]), 'read'));
    }

    public function testRulesOfSeveralGroupsAreOrEd(): void
    {
        $g1 = $this->group('A');
        $g2 = $this->group('B');
        $this->rule($g1, 'items', 'read', ['status' => ['_eq' => 'active']]);
        $this->rule($g2, 'items', 'read', ['status' => ['_eq' => 'pending']]);

        $this->assertSame([1, 3, 4, 5], $this->ids($this->access(self::ENTER, [$g1, $g2]), 'read'));
    }

    public function testRulesOfGroupsTheUserDoesNotBelongToAreIgnored(): void
    {
        $mine  = $this->group('Mine');
        $other = $this->group('Other');
        $this->rule($mine,  'items', 'read', ['status' => ['_eq' => 'active']]);
        $this->rule($other, 'items', 'read');

        $this->assertSame([1, 3, 5], $this->ids($this->access(self::ENTER, [$mine]), 'read'));
    }

    public function testRuleRaisesAReaderToWriteOnASubset(): void
    {
        $g = $this->group('Correctors');
        $this->rule($g, 'items', 'update', ['status' => ['_eq' => 'pending']]);

        $a = $this->access(self::READER, [$g]);

        $this->assertTrue($a->can('update', 'items'));
        $this->assertSame([4], $this->ids($a, 'update'));
        $this->assertSame([1, 2, 3, 4, 5], $this->ids($a, 'read'));
    }

    public function testCreateRuleIsATableLevelSwitch(): void
    {
        $g = $this->group('Creators');
        $this->rule($g, 'items', 'create');

        $a = $this->access(self::ENTER, [$g]);

        $this->assertTrue($a->can('create', 'items'));
        $this->assertFalse($a->can('create', 'tags'));
        $this->assertSame(['1=1', []], $a->rowCondition('create', 'items'));
        $this->assertSame(['1=0', []], $a->rowCondition('create', 'tags'));
    }

    public function testCurrentUserVariable(): void
    {
        static::$db->exec("UPDATE items SET creator = '7' WHERE id = 2");
        $g = $this->group('Mine');
        $this->rule($g, 'items', 'read', ['creator' => ['_eq' => '$CURRENT_USER']]);

        $this->assertSame([2], $this->ids($this->access(self::ENTER, [$g], 7), 'read'));
        $this->assertSame([], $this->ids($this->access(self::ENTER, [$g], 8), 'read'));
    }

    public function testApiKeyLikePrincipalUsesItsGroup(): void
    {
        $g = $this->group('Public');
        $this->rule($g, 'items', 'read', ['status' => ['_eq' => 'active']]);

        $a = $this->access(self::ENTER, [$g], 0);

        $this->assertSame([1, 3, 5], $this->ids($a, 'read'));
        $this->assertFalse($a->can('update', 'items'));
    }

    public function testRuleFiltersAreBoundNotInterpolated(): void
    {
        $g = $this->group('G');
        $this->rule($g, 'items', 'read', ['name' => ['_eq' => "x' OR '1'='1"]]);

        [$sql, $vals] = $this->access(self::ENTER, [$g])->rowCondition('read', 'items');

        $this->assertStringNotContainsString("OR '1'='1", $sql);
        $this->assertSame(["x' OR '1'='1"], $vals);
        $this->assertSame([], $this->ids($this->access(self::ENTER, [$g]), 'read'));
    }

    // ── Locks ─────────────────────────────────────────────────────────────────

    public function testLockBlocksUpdateAndDeleteButNotReadOrCreate(): void
    {
        $this->lock('items', ['status' => ['_eq' => 'inactive']]);

        $a = $this->access(self::WRITER);

        $this->assertSame([1, 3, 4, 5], $this->ids($a, 'update'));
        $this->assertSame([1, 3, 4, 5], $this->ids($a, 'delete'));
        $this->assertSame([1, 2, 3, 4, 5], $this->ids($a, 'read'));
        $this->assertTrue($a->can('create', 'items'));
        $this->assertFalse($a->rowAllowed('update', 'items', 2));
        $this->assertTrue($a->rowAllowed('update', 'items', 1));
    }

    public function testLockAppliesToSelfWritersOwnRecordsAndToGroupRules(): void
    {
        static::$db->exec("UPDATE items SET creator = '7' WHERE id IN (1, 2)");
        $this->lock('items', ['status' => ['_eq' => 'inactive']]);

        $this->assertSame([1], $this->ids($this->access(self::SELF_W, [], 7), 'update'));

        $g = $this->group('Editors');
        $this->rule($g, 'items', 'update');
        $this->assertSame([1, 3, 4, 5], $this->ids($this->access(self::ENTER, [$g]), 'update'));
    }

    public function testLockIsNullSafe(): void
    {
        static::$db->exec("INSERT INTO items (id, creator, name, status) VALUES (6, 'admin', 'No status', NULL)");
        $this->lock('items', ['status' => ['_eq' => 'inactive']]);

        // A bare NOT (status = 'inactive') is NULL for this row and would block it.
        $this->assertContains(6, $this->ids($this->access(self::WRITER), 'update'));
    }

    public function testSeveralLocksAreCumulative(): void
    {
        $this->lock('items', ['status' => ['_eq' => 'inactive']]);
        $this->lock('items', ['status' => ['_eq' => 'pending']]);

        $this->assertSame([1, 3, 5], $this->ids($this->access(self::WRITER), 'update'));
    }

    public function testLockOnAnotherTableDoesNotAffectThisOne(): void
    {
        $this->lock('tags', ['name' => ['_eq' => 'x']]);

        $this->assertSame([1, 2, 3, 4, 5], $this->ids($this->access(self::WRITER), 'update'));
    }

    // ── Fail closed ───────────────────────────────────────────────────────────

    public function testRuleWithUnknownFieldGrantsNothing(): void
    {
        $g = $this->group('Broken');
        $this->rule($g, 'items', 'read', ['no_such_field' => ['_eq' => 1]]);

        $this->assertSame([], $this->ids($this->access(self::ENTER, [$g]), 'read'));
    }

    public function testRuleWithInvalidJsonGrantsNothing(): void
    {
        $g = $this->group('Broken');
        static::$db->query(
            "INSERT INTO bdus_group_rules (group_id, table_name, action, filter) VALUES (?, 'items', 'read', '{oops')",
            [$g], 'boolean'
        );

        $a = $this->access(self::ENTER, [$g]);

        $this->assertFalse($a->can('read', 'items'));
        $this->assertSame([], $this->ids($a, 'read'));
    }

    public function testLockWithUnknownFieldOrInvalidJsonLocksTheWholeTable(): void
    {
        $this->lock('items', ['no_such_field' => ['_eq' => 1]]);
        $this->assertSame([], $this->ids($this->access(self::WRITER), 'update'));
        $this->assertSame([1, 2, 3, 4, 5], $this->ids($this->access(self::WRITER), 'read'));

        static::$db->exec('DELETE FROM bdus_table_locks');
        $this->lock('items', '{oops');
        $this->assertSame([], $this->ids($this->access(self::WRITER), 'delete'));
    }

    // ── Factory ───────────────────────────────────────────────────────────────

    public function testForCurrentUserLoadsGroupsFromMembership(): void
    {
        $g = $this->group('Reviewers');
        $this->rule($g, 'items', 'read', ['status' => ['_eq' => 'active']]);
        static::$db->exec("INSERT INTO bdus_users (id, name, email, password, privilege) VALUES (77, 'r', 'r@example.com', 'x', 39)");
        static::$db->exec("INSERT INTO bdus_user_groups (user_id, group_id) VALUES (77, {$g})");

        \Auth\CurrentUser::set(['id' => 77, 'privilege' => self::ENTER, 'name' => 'r']);
        try {
            $a = Access::forCurrentUser(static::$db, static::$cfg);
            $this->assertSame([1, 3, 5], $this->ids($a, 'read'));
        } finally {
            static::$db->exec('DELETE FROM bdus_user_groups');
            static::$db->exec('DELETE FROM bdus_users WHERE id = 77');
            \Auth\CurrentUser::set(['id' => 1, 'name' => 'Test Admin', 'email' => 'test@example.com', 'privilege' => 1, 'app' => 'test']);
        }
    }

    public function testForCurrentUserWhenUnauthenticatedAllowsNothing(): void
    {
        \Auth\CurrentUser::set([]);
        try {
            $a = Access::forCurrentUser(static::$db, static::$cfg);
            $this->assertFalse($a->can('read', 'items'));
        } finally {
            \Auth\CurrentUser::set(['id' => 1, 'name' => 'Test Admin', 'email' => 'test@example.com', 'privilege' => 1, 'app' => 'test']);
        }
    }

    public function testForCurrentUserApiKeyUsesItsGroup(): void
    {
        $g = $this->group('Public');
        $this->rule($g, 'items', 'read', ['status' => ['_eq' => 'pending']]);

        \Auth\CurrentUser::set(['id' => 0, 'privilege' => self::ENTER, 'name' => 'API Key: k', 'is_api_key' => true, 'group_id' => $g]);
        try {
            $a = Access::forCurrentUser(static::$db, static::$cfg);
            $this->assertSame([4], $this->ids($a, 'read'));
        } finally {
            \Auth\CurrentUser::set(['id' => 1, 'name' => 'Test Admin', 'email' => 'test@example.com', 'privilege' => 1, 'app' => 'test']);
        }
    }
}
