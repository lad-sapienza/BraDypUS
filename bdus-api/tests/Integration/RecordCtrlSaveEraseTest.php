<?php

namespace Tests\Integration;

use Tests\Support\BdusTestCase;

/**
 * Integration tests for record_ctrl::saveRecord() and record_ctrl::erase().
 *
 * Uses the shared in-memory DB (via BdusTestCase::setUpBeforeClass).
 * Tests that modify rows use isolated IDs / cleanup within each test to avoid
 * order-dependency with other test classes.
 */
class RecordCtrlSaveEraseTest extends BdusTestCase
{
    private const TB = 'items';

    // ── saveRecord — privilege (self_writer / add_new vs edit) ────────────

    public function testInsertRequiresAtLeastAddNewPrivilege(): void
    {
        $this->setPrivilege(40); // waiting — fails even add_new (≤25)

        $ctrl = $this->makeController(
            'Bdus\\Controllers\\Record',
            [],
            ['tb' => self::TB, 'core' => ['name' => 'Should not be created', 'status' => 'active']]
        );
        $res = $this->callController($ctrl, 'saveRecord');
        $this->assertSame('not_enough_privilege', $res['code']);

        $this->setPrivilege(1);
    }

    public function testSelfWriterCanCreateNewRecord(): void
    {
        \Auth\CurrentUser::set([
            'id' => 42, 'name' => 'Self Writer', 'email' => 'sw@example.com',
            'privilege' => 25, 'app' => 'test',
        ]);

        $ctrl = $this->makeController(
            'Bdus\\Controllers\\Record',
            [],
            ['tb' => self::TB, 'core' => ['name' => 'Self-writer new item', 'status' => 'active']]
        );
        $res = $this->callController($ctrl, 'saveRecord');

        $this->assertSame('success', $res['status']);
        $newId = (int) $res['id'];
        $this->assertGreaterThan(0, $newId);

        // creator is always forced server-side to the authenticated user's id
        $row = static::$db->query('SELECT creator FROM items WHERE id = ?', [$newId], 'read');
        $this->assertSame('42', (string) $row[0]['creator']);

        $this->setPrivilege(1);
        static::$db->query('DELETE FROM items WHERE id = ?', [$newId], 'boolean');
    }

    public function testSelfWriterCanEditOwnRecord(): void
    {
        // Record owned by user id 42 (creator stored as its numeric id, unlike
        // the seeded 'admin' fixture rows which use a display name)
        static::$db->query(
            "INSERT INTO items (creator, name, description, status) VALUES ('42', 'Owned item', 'desc', 'active')",
            [], 'boolean'
        );
        $ownId = (int) static::$db->query('SELECT last_insert_rowid() AS id', [], 'read')[0]['id'];

        \Auth\CurrentUser::set([
            'id' => 42, 'name' => 'Self Writer', 'email' => 'sw@example.com',
            'privilege' => 25, 'app' => 'test',
        ]);

        $ctrl = $this->makeController(
            'Bdus\\Controllers\\Record',
            [],
            ['tb' => self::TB, 'id' => $ownId, 'core' => ['description' => 'Edited by owner']]
        );
        $res = $this->callController($ctrl, 'saveRecord');

        $this->assertSame('success', $res['status']);

        $this->setPrivilege(1);
        static::$db->query('DELETE FROM items WHERE id = ?', [$ownId], 'boolean');
    }

    public function testSelfWriterCannotEditOthersRecord(): void
    {
        // Record id=1 is seeded with creator='admin' — not user 42
        \Auth\CurrentUser::set([
            'id' => 42, 'name' => 'Self Writer', 'email' => 'sw@example.com',
            'privilege' => 25, 'app' => 'test',
        ]);

        $ctrl = $this->makeController(
            'Bdus\\Controllers\\Record',
            [],
            ['tb' => self::TB, 'id' => 1, 'core' => ['description' => 'Should not be saved']]
        );
        $res = $this->callController($ctrl, 'saveRecord');
        $this->assertSame('not_enough_privilege', $res['code']);

        $this->setPrivilege(1);
    }

    public function testWriterCanEditAnyRecordRegardlessOfCreator(): void
    {
        $this->setPrivilege(20); // writer — the plain, non-ownership branch

        $ctrl = $this->makeController(
            'Bdus\\Controllers\\Record',
            [],
            ['tb' => self::TB, 'id' => 1, 'core' => ['description' => 'Writer edit']]
        );
        $res = $this->callController($ctrl, 'saveRecord');
        $this->assertSame('success', $res['status']);

        // restore
        static::$db->query("UPDATE items SET description = 'First description' WHERE id = ?", [1], 'boolean');
        $this->setPrivilege(1);
    }

    // ── saveRecord — UPDATE ───────────────────────────────────────────────

    public function testSaveRecordUpdateChangesField(): void
    {
        // Record id=1 has name='Alpha item'; change description
        $ctrl = $this->makeController(
            'Bdus\\Controllers\\Record',
            [],    // no GET params
            [
                'tb'   => self::TB,
                'id'   => 1,
                'core' => ['description' => 'Updated description'],
            ]
        );
        $res = $this->callController($ctrl, 'saveRecord');

        $this->assertSame('success', $res['status']);
        $this->assertSame('success_saved', $res['code']);
        $this->assertSame(1, $res['id']);

        // Verify DB was updated
        $row = static::$db->query(
            'SELECT description FROM items WHERE id = ?',
            [1],
            'read'
        );
        $this->assertSame('Updated description', $row[0]['description']);

        // Restore original value for other tests
        static::$db->query(
            "UPDATE items SET description = 'First description' WHERE id = ?",
            [1],
            'boolean'
        );
    }

    public function testSaveRecordUpdateNoChangeIsNoop(): void
    {
        // Sending the same value that's already stored should succeed (no error)
        // even if Persist treats it as noop internally.
        $ctrl = $this->makeController(
            'Bdus\\Controllers\\Record',
            [],
            [
                'tb'   => self::TB,
                'id'   => 2,
                'core' => ['name' => 'Beta item'],  // same as seeded
            ]
        );
        $res = $this->callController($ctrl, 'saveRecord');

        $this->assertSame('success', $res['status']);
    }

    public function testSaveRecordUpdateMissingTbReturnsError(): void
    {
        $ctrl = $this->makeController('Bdus\\Controllers\\Record', [], ['id' => 1, 'core' => ['name' => 'X']]);
        $res  = $this->callController($ctrl, 'saveRecord');
        $this->assertSame('error', $res['status']);
        $this->assertSame('parameter_missing', $res['code']);
    }

    // ── saveRecord — INSERT ───────────────────────────────────────────────

    public function testSaveRecordInsertCreatesNewRecord(): void
    {
        $ctrl = $this->makeController(
            'Bdus\\Controllers\\Record',
            [],
            [
                'tb'   => self::TB,
                // no id → INSERT path
                'core' => ['name' => 'Zeta item', 'description' => 'New', 'status' => 'active', 'creator' => 'admin'],
            ]
        );
        $res = $this->callController($ctrl, 'saveRecord');

        $this->assertSame('success', $res['status']);
        $this->assertSame('success_created', $res['code']);
        $this->assertNotNull($res['id']);
        $newId = (int)$res['id'];
        $this->assertGreaterThan(0, $newId);

        // Verify in DB
        $row = static::$db->query(
            'SELECT name FROM items WHERE id = ?',
            [$newId],
            'read'
        );
        $this->assertSame('Zeta item', $row[0]['name']);

        // Clean up
        static::$db->query('DELETE FROM items WHERE id = ?', [$newId], 'boolean');
    }

    public function testSaveRecordInsertWithPluginRow(): void
    {
        // Insert a new item AND a plugin (tag) row at the same time
        $ctrl = $this->makeController(
            'Bdus\\Controllers\\Record',
            [],
            [
                'tb'      => self::TB,
                'core'    => ['name' => 'Eta item', 'status' => 'active', 'creator' => 'admin'],
                'plugins' => [
                    'tags' => [
                        ['id' => null, '_delete' => false, '_isNew' => true,
                         'fields' => ['label' => 'new-plugin-tag']],
                    ]
                ],
            ]
        );
        $res = $this->callController($ctrl, 'saveRecord');

        $this->assertSame('success', $res['status']);
        $newId = (int)$res['id'];
        $this->assertGreaterThan(0, $newId);

        // The tag should have been inserted
        $tags = static::$db->query(
            'SELECT * FROM tags WHERE id_link = ?',
            [$newId],
            'read'
        );
        $this->assertCount(1, $tags);
        $this->assertSame('new-plugin-tag', $tags[0]['label']);

        // Clean up
        static::$db->query('DELETE FROM items WHERE id = ?', [$newId], 'boolean');
        static::$db->query('DELETE FROM tags WHERE id_link = ?', [$newId], 'boolean');
    }

    // ── erase ─────────────────────────────────────────────────────────────

    public function testEraseDeletesRecord(): void
    {
        // Insert a throwaway record, then erase it
        static::$db->query(
            "INSERT INTO items (creator, name, description, status)
             VALUES ('admin', 'Temp item', 'To be deleted', 'active')",
            [],
            'boolean'
        );
        $tmpId = (int) static::$db->query('SELECT last_insert_rowid() AS id', [], 'read')[0]['id'];

        $ctrl = $this->makeController('Bdus\\Controllers\\Record', ['tb' => self::TB, 'id' => $tmpId]);
        $res  = $this->callController($ctrl, 'erase');

        $this->assertSame('success', $res['status']);
        $this->assertSame('all_record_deleted', $res['code']);

        // Confirm it is gone
        $row = static::$db->query(
            'SELECT id FROM items WHERE id = ?',
            [$tmpId],
            'read'
        );
        $this->assertEmpty($row);
    }

    public function testEraseRequiresAtLeastEditChance(): void
    {
        $this->setPrivilege(30); // reader — can never pass 'edit', not even best case

        static::$db->query(
            "INSERT INTO items (creator, name, description, status) VALUES ('30', 'Reader owned?', 'x', 'active')",
            [], 'boolean'
        );
        $tmpId = (int) static::$db->query('SELECT last_insert_rowid() AS id', [], 'read')[0]['id'];

        $ctrl = $this->makeController('Bdus\\Controllers\\Record', ['tb' => self::TB, 'id' => $tmpId]);
        $res  = $this->callController($ctrl, 'erase');
        $this->assertSame('not_enough_privilege', $res['code']);

        $this->setPrivilege(1);
        static::$db->query('DELETE FROM items WHERE id = ?', [$tmpId], 'boolean');
    }

    public function testSelfWriterCanEraseOwnRecord(): void
    {
        static::$db->query(
            "INSERT INTO items (creator, name, description, status) VALUES ('42', 'Owned, to delete', 'x', 'active')",
            [], 'boolean'
        );
        $ownId = (int) static::$db->query('SELECT last_insert_rowid() AS id', [], 'read')[0]['id'];

        \Auth\CurrentUser::set([
            'id' => 42, 'name' => 'Self Writer', 'email' => 'sw@example.com',
            'privilege' => 25, 'app' => 'test',
        ]);

        $ctrl = $this->makeController('Bdus\\Controllers\\Record', ['tb' => self::TB, 'id' => $ownId]);
        $res  = $this->callController($ctrl, 'erase');

        $this->assertSame('success', $res['status']);
        $this->assertSame('all_record_deleted', $res['code']);

        $row = static::$db->query('SELECT id FROM items WHERE id = ?', [$ownId], 'read');
        $this->assertEmpty($row);

        $this->setPrivilege(1);
    }

    public function testSelfWriterCannotEraseOthersRecord(): void
    {
        // Record id=1 is seeded with creator='admin' — not user 42
        \Auth\CurrentUser::set([
            'id' => 42, 'name' => 'Self Writer', 'email' => 'sw@example.com',
            'privilege' => 25, 'app' => 'test',
        ]);

        $ctrl = $this->makeController('Bdus\\Controllers\\Record', ['tb' => self::TB, 'id' => 1]);
        $res  = $this->callController($ctrl, 'erase');

        $this->assertSame('error', $res['status']);
        $this->assertSame('no_record_deleted', $res['code']);

        // Confirm id=1 is untouched
        $row = static::$db->query('SELECT id FROM items WHERE id = ?', [1], 'read');
        $this->assertNotEmpty($row);

        $this->setPrivilege(1);
    }

    public function testSelfWriterBulkEraseOnlyDeletesOwnRecords(): void
    {
        static::$db->query(
            "INSERT INTO items (creator, name, description, status) VALUES ('42', 'Owned A', 'x', 'active')",
            [], 'boolean'
        );
        $ownIdA = (int) static::$db->query('SELECT last_insert_rowid() AS id', [], 'read')[0]['id'];

        static::$db->query(
            "INSERT INTO items (creator, name, description, status) VALUES ('42', 'Owned B', 'x', 'active')",
            [], 'boolean'
        );
        $ownIdB = (int) static::$db->query('SELECT last_insert_rowid() AS id', [], 'read')[0]['id'];

        \Auth\CurrentUser::set([
            'id' => 42, 'name' => 'Self Writer', 'email' => 'sw@example.com',
            'privilege' => 25, 'app' => 'test',
        ]);

        // Mix: two owned ids + id=1 (owned by 'admin', not 42)
        $ctrl = $this->makeController('Bdus\\Controllers\\Record', ['tb' => self::TB, 'id' => [$ownIdA, $ownIdB, 1]]);
        $res  = $this->callController($ctrl, 'erase');

        $this->assertSame('warning', $res['status']);
        $this->assertSame('partially_deleted_with_count', $res['code']);
        $this->assertSame(2, $res['deleted']);
        $this->assertSame(1, $res['failed']);

        $this->assertEmpty(static::$db->query('SELECT id FROM items WHERE id = ?', [$ownIdA], 'read'));
        $this->assertEmpty(static::$db->query('SELECT id FROM items WHERE id = ?', [$ownIdB], 'read'));
        $this->assertNotEmpty(static::$db->query('SELECT id FROM items WHERE id = ?', [1], 'read'));

        $this->setPrivilege(1);
    }

    public function testWriterCanEraseAnyRecordRegardlessOfCreator(): void
    {
        $this->setPrivilege(20); // writer — the plain, non-ownership branch

        static::$db->query(
            "INSERT INTO items (creator, name, description, status) VALUES ('admin', 'Writer erase target', 'x', 'active')",
            [], 'boolean'
        );
        $tmpId = (int) static::$db->query('SELECT last_insert_rowid() AS id', [], 'read')[0]['id'];

        $ctrl = $this->makeController('Bdus\\Controllers\\Record', ['tb' => self::TB, 'id' => $tmpId]);
        $res  = $this->callController($ctrl, 'erase');
        $this->assertSame('success', $res['status']);

        $this->setPrivilege(1);
    }

    public function testEraseMissingIdReturnsError(): void
    {
        $ctrl = $this->makeController('Bdus\\Controllers\\Record', ['tb' => self::TB]);
        $res  = $this->callController($ctrl, 'erase');
        $this->assertSame('error', $res['status']);
        $this->assertSame('no_id_provided', $res['code']);
    }

    public function testEraseMissingTbReturnsError(): void
    {
        $ctrl = $this->makeController('Bdus\\Controllers\\Record', ['id' => 1]);
        $res  = $this->callController($ctrl, 'erase');
        $this->assertSame('error', $res['status']);
        $this->assertSame('no_id_provided', $res['code']);
    }
}
