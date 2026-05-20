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

    // ── saveRecord — UPDATE ───────────────────────────────────────────────

    public function testSaveRecordUpdateChangesField(): void
    {
        // Record id=1 has name='Alpha item'; change description
        $ctrl = $this->makeController(
            'record_ctrl',
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
            'record_ctrl',
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
        $ctrl = $this->makeController('record_ctrl', [], ['id' => 1, 'core' => ['name' => 'X']]);
        $res  = $this->callController($ctrl, 'saveRecord');
        $this->assertSame('error', $res['status']);
        $this->assertSame('parameter_missing', $res['code']);
    }

    // ── saveRecord — INSERT ───────────────────────────────────────────────

    public function testSaveRecordInsertCreatesNewRecord(): void
    {
        $ctrl = $this->makeController(
            'record_ctrl',
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
            'record_ctrl',
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
            'SELECT * FROM tags WHERE id_link = ? AND table_link = ?',
            [$newId, self::TB],
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

        $ctrl = $this->makeController('record_ctrl', ['tb' => self::TB, 'id' => $tmpId]);
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

    public function testEraseMissingIdReturnsError(): void
    {
        $ctrl = $this->makeController('record_ctrl', ['tb' => self::TB]);
        $res  = $this->callController($ctrl, 'erase');
        $this->assertSame('error', $res['status']);
        $this->assertSame('no_id_provided', $res['code']);
    }

    public function testEraseMissingTbReturnsError(): void
    {
        $ctrl = $this->makeController('record_ctrl', ['id' => 1]);
        $res  = $this->callController($ctrl, 'erase');
        $this->assertSame('error', $res['status']);
        $this->assertSame('no_id_provided', $res['code']);
    }
}
