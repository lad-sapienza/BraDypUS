<?php

namespace Tests\Integration;

use Tests\Support\BdusTestCase;

/**
 * Write-side enforcement of locks and group rules on record changes (#78).
 *
 * Fixture items 1-5: active, inactive, active, pending, active. Unless a test
 * says otherwise there is one lock, `items` with status = inactive (item 2 is
 * frozen), and the acting principal is a writer (tier 20, user 52).
 * Item 1 has a manual link and an RS relation to the frozen item 2 and two tag
 * rows (plugin of items); file 1 is attached to item 1.
 */
class AccessWriteEnforcementTest extends BdusTestCase
{
    private int $group = 0;

    protected function setUp(): void
    {
        static::$db->exec("INSERT INTO bdus_users (id, name, email, password, privilege) VALUES (52, 'w', 'w@example.com', 'x', 20)");
        static::$db->query(
            "INSERT INTO bdus_table_locks (table_name, filter) VALUES ('items', ?)",
            [json_encode(['status' => ['_eq' => 'inactive']])], 'boolean'
        );
        $this->actAs(20);
    }

    protected function tearDown(): void
    {
        static::$db->exec('DELETE FROM bdus_user_groups');
        static::$db->exec('DELETE FROM bdus_group_rules');
        static::$db->exec('DELETE FROM bdus_groups');
        static::$db->exec('DELETE FROM bdus_table_locks');
        static::$db->exec('DELETE FROM bdus_users WHERE id = 52');
        static::$db->exec("DELETE FROM items WHERE id > 5");
        static::$db->exec("DELETE FROM tags WHERE id_link = 2 OR label LIKE 'new-%'");
        static::$db->exec("DELETE FROM bdus_file_links WHERE record_id = 2 OR record_id = 3");
        static::$db->exec("DELETE FROM bdus_rs WHERE NOT (first = 1 AND second = 2)");
        static::$db->exec("DELETE FROM bdus_userlinks WHERE NOT (id_one = 1 AND id_two = 2)");
        static::$db->exec("UPDATE items SET status = CASE id WHEN 1 THEN 'active' WHEN 2 THEN 'inactive' WHEN 3 THEN 'active' WHEN 4 THEN 'pending' ELSE 'active' END, description = name, creator = 'admin'");
        $this->setPrivilege(1);
    }

    private function actAs(int $tier, int $id = 52): void
    {
        \Auth\CurrentUser::set(['id' => $id, 'name' => 'w', 'email' => 'w@example.com', 'privilege' => $tier, 'app' => 'test']);
    }

    /** Puts the principal (user 52) in a group with the given rules: [table, action, ?filter]. */
    private function inGroup(array $rules): void
    {
        $this->group = (int) static::$db->query("INSERT INTO bdus_groups (name) VALUES ('G')", [], 'id');
        static::$db->exec("INSERT INTO bdus_user_groups (user_id, group_id) VALUES (52, {$this->group})");
        foreach ($rules as [$table, $action, $filter]) {
            static::$db->query(
                'INSERT INTO bdus_group_rules (group_id, table_name, action, filter) VALUES (?, ?, ?, ?)',
                [$this->group, $table, $action, $filter === null ? null : json_encode($filter)], 'boolean'
            );
        }
    }

    private function call(string $method, array $get = [], array $post = []): array
    {
        return $this->callController($this->makeController('Bdus\\Controllers\\Record', $get, $post), $method);
    }

    private function save(int $id, array $core = ['description' => 'changed'], array $extra = []): array
    {
        return $this->call('saveRecord', [], array_merge(['tb' => 'items', 'id' => $id, 'core' => $core], $extra));
    }

    private function field(int $id, string $fld = 'description'): ?string
    {
        return static::$db->query("SELECT {$fld} AS v FROM items WHERE id = ?", [$id], 'read')[0]['v'] ?? null;
    }

    private function item(string $status): int
    {
        static::$db->query("INSERT INTO items (creator, name, status) VALUES ('admin', 'tmp', ?)", [$status], 'boolean');
        return (int) static::$db->query('SELECT last_insert_rowid() AS id', [], 'read')[0]['id'];
    }

    private function denied(array $res): void
    {
        $this->assertSame('not_enough_privilege', $res['code'] ?? null, json_encode($res));
    }

    // ── saveRecord: update ────────────────────────────────────────────────────

    public function testWriterUpdatesAFreeRecordButNotAFrozenOne(): void
    {
        $this->assertSame('success', $this->save(1)['status']);
        $this->assertSame('changed', $this->field(1));

        $this->denied($this->save(2));
        $this->assertNotSame('changed', $this->field(2));
    }

    public function testAdminsChangeFrozenRecords(): void
    {
        foreach ([10, 1] as $tier) {
            $this->actAs($tier, 1);
            $this->assertSame('success', $this->save(2, ['description' => "by $tier"])['status']);
            $this->assertSame("by $tier", $this->field(2));
        }
    }

    public function testFrozenRecordCannotBeChangedByTheSameCallThatWouldAddPluginRows(): void
    {
        $res = $this->save(2, [], ['plugins' => ['tags' => [['isNew' => true, '_isNew' => true, 'fields' => ['label' => 'new-x']]]]]);

        $this->denied($res);
        $this->assertSame(0, (int) static::$db->query("SELECT COUNT(*) AS n FROM tags WHERE label = 'new-x'", [], 'read')[0]['n']);
    }

    public function testSelfWriterChangesOnlyOwnUnfrozenRecords(): void
    {
        static::$db->exec("UPDATE items SET creator = '52' WHERE id IN (1, 2)");
        $this->actAs(25);

        $this->assertSame('success', $this->save(1)['status']);
        $this->denied($this->save(2));   // own, but frozen
        $this->denied($this->save(3));   // free, but not theirs
    }

    // ── saveRecord: rules ─────────────────────────────────────────────────────

    public function testReaderWithAnUpdateRuleChangesOnlyTheRuleScope(): void
    {
        $this->actAs(30);
        $this->inGroup([['items', 'update', ['status' => ['_eq' => 'pending']]]]);

        $this->assertSame('success', $this->save(4)['status']);
        $this->denied($this->save(1));
        $this->denied($this->save(2));
    }

    public function testRulesDoNotLiftALock(): void
    {
        $this->actAs(39);
        $this->inGroup([['items', 'update', null]]);

        $this->assertSame('success', $this->save(3)['status']);
        $this->denied($this->save(2));
    }

    public function testEnterTierWithoutAnUpdateRuleCannotChangeAnything(): void
    {
        $this->actAs(39);
        $this->inGroup([['items', 'read', null]]);

        $this->denied($this->save(1));
    }

    // ── saveRecord: create and plugin rows ────────────────────────────────────

    public function testCreateNeedsTheTierOrACreateRule(): void
    {
        $create = fn() => $this->call('saveRecord', [], ['tb' => 'items', 'core' => ['name' => 'brand new', 'status' => 'draft']]);

        $this->actAs(30);
        $this->denied($create());

        $this->inGroup([['items', 'create', null]]);
        $this->assertSame('success', $create()['status']);
    }

    public function testPluginRowsCannotBeAddedToAFrozenRecord(): void
    {
        $add = fn(int $host) => $this->call('saveRecord', [], ['tb' => 'tags', 'core' => ['label' => 'new-' . $host, 'id_link' => $host]]);

        $this->assertSame('success', $add(1)['status']);
        $this->denied($add(2));
    }

    public function testPluginRowsOfAFrozenRecordCannotBeEditedOrErased(): void
    {
        static::$db->exec("INSERT INTO tags (label, id_link) VALUES ('frozen-tag', 2)");
        $tagId = (int) static::$db->query('SELECT last_insert_rowid() AS id', [], 'read')[0]['id'];

        $this->denied($this->call('saveRecord', [], ['tb' => 'tags', 'id' => $tagId, 'core' => ['label' => 'x']]));
        $erase = $this->call('erase', ['tb' => 'tags', 'id' => $tagId]);
        $this->assertSame('error', $erase['status']);
        $this->assertSame(1, (int) static::$db->query('SELECT COUNT(*) AS n FROM tags WHERE id = ?', [$tagId], 'read')[0]['n']);
    }

    // ── erase ─────────────────────────────────────────────────────────────────

    public function testEraseSkipsFrozenRecords(): void
    {
        $free   = $this->item('active');
        $frozen = $this->item('inactive');

        $res = $this->call('erase', ['tb' => 'items', 'id' => [$free, $frozen]]);

        $this->assertSame('partially_deleted_with_count', $res['code']);
        $this->assertSame(0, (int) static::$db->query('SELECT COUNT(*) AS n FROM items WHERE id = ?', [$free], 'read')[0]['n']);
        $this->assertSame(1, (int) static::$db->query('SELECT COUNT(*) AS n FROM items WHERE id = ?', [$frozen], 'read')[0]['n']);
    }

    public function testAdminErasesFrozenRecords(): void
    {
        $frozen = $this->item('inactive');
        $this->actAs(10, 1);

        $this->assertSame('all_record_deleted', $this->call('erase', ['tb' => 'items', 'id' => $frozen])['code']);
    }

    public function testErasePluginsAndItsCheckRespectLocks(): void
    {
        $this->denied($this->call('erasePlugins', ['tb' => 'items', 'id' => 2]));
        $this->denied($this->call('checkPluginsBeforeDelete', ['tb' => 'items', 'id' => 2]));
        $this->assertSame('success', $this->call('checkPluginsBeforeDelete', ['tb' => 'items', 'id' => 1])['status']);
    }

    // ── duplicate ─────────────────────────────────────────────────────────────

    public function testDuplicatingAFrozenRecordIsAllowedBecauseItCreatesANewOne(): void
    {
        $res = $this->call('duplicateRecord', ['tb' => 'items', 'id' => 2]);

        $this->assertSame('success', $res['status']);
    }

    public function testDuplicateNeedsCreateRightAndAReadableSource(): void
    {
        $this->actAs(30);
        $this->denied($this->call('duplicateRecord', ['tb' => 'items', 'id' => 1]));

        $this->actAs(39);
        $this->inGroup([['items', 'create', null], ['items', 'read', ['status' => ['_eq' => 'active']]]]);
        $this->assertSame('success', $this->call('duplicateRecord', ['tb' => 'items', 'id' => 1])['status']);
        $this->denied($this->call('duplicateRecord', ['tb' => 'items', 'id' => 4]));
    }

    // ── Dependants: files, RS, links ──────────────────────────────────────────

    public function testFileLinksFollowTheLock(): void
    {
        $this->assertSame('success', $this->call('linkFile', ['tb' => 'items', 'id' => 3], ['fileId' => 1])['status']);
        $this->denied($this->call('linkFile', ['tb' => 'items', 'id' => 2], ['fileId' => 1]));

        static::$db->exec("INSERT INTO bdus_file_links (file_id, table_name, record_id, sort) VALUES (2, 'items', 2, 1)");
        $linkId = (int) static::$db->query('SELECT last_insert_rowid() AS id', [], 'read')[0]['id'];
        $this->denied($this->call('unlinkFile', ['linkId' => $linkId]));
        $this->assertSame(1, (int) static::$db->query('SELECT COUNT(*) AS n FROM bdus_file_links WHERE id = ?', [$linkId], 'read')[0]['n']);
    }

    public function testDeletingAFileNeedsEveryLinkedRecordToBeChangeable(): void
    {
        static::$db->exec("INSERT INTO bdus_file_links (file_id, table_name, record_id, sort) VALUES (1, 'items', 2, 2)");

        $this->denied($this->call('deleteFile', [], ['fileId' => 1]));
        $this->assertSame(1, (int) static::$db->query('SELECT COUNT(*) AS n FROM bdus_files WHERE id = 1', [], 'read')[0]['n']);
    }

    public function testRsRelationsNeedBothEndsChangeable(): void
    {
        $this->assertSame('success', $this->call('addRs', [], ['tb' => 'items', 'first' => 3, 'relation' => 5, 'second' => 4])['status']);
        $this->denied($this->call('addRs', [], ['tb' => 'items', 'first' => 3, 'relation' => 5, 'second' => 2]));

        $seeded = (int) static::$db->query('SELECT id FROM bdus_rs WHERE first = 1 AND second = 2', [], 'read')[0]['id'];
        $this->denied($this->call('deleteRs', [], ['id' => $seeded]));
    }

    public function testManualLinksNeedBothEndsChangeable(): void
    {
        $this->assertSame('success', $this->call('addManualLink', [], ['tb_one' => 'items', 'id_one' => 3, 'tb_two' => 'items', 'id_two' => 4])['status']);
        $this->denied($this->call('addManualLink', [], ['tb_one' => 'items', 'id_one' => 3, 'tb_two' => 'items', 'id_two' => 2]));

        $seeded = (int) static::$db->query('SELECT id FROM bdus_userlinks WHERE id_one = 1 AND id_two = 2', [], 'read')[0]['id'];
        $this->denied($this->call('deleteManualLink', [], ['id' => $seeded]));
    }

    public function testAdminsChangeDependantsOfFrozenRecords(): void
    {
        $this->actAs(10, 1);
        $seeded = (int) static::$db->query('SELECT id FROM bdus_rs WHERE first = 1 AND second = 2', [], 'read')[0]['id'];

        $this->assertSame('success', $this->call('deleteRs', [], ['id' => $seeded])['status']);
    }

    // ── No groups, no locks: nothing changes ──────────────────────────────────

    public function testWithoutLocksAWriterChangesEverything(): void
    {
        static::$db->exec('DELETE FROM bdus_table_locks');

        $this->assertSame('success', $this->save(2)['status']);
        $this->assertSame('success', $this->call('addRs', [], ['tb' => 'items', 'first' => 2, 'relation' => 5, 'second' => 3])['status']);
    }
}
