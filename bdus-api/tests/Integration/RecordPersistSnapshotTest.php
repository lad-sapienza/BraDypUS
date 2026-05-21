<?php

namespace Tests\Integration;

use Tests\Support\BdusTestCase;

/**
 * Regression tests for the snapshot-before-write behaviour in Record\Persist.
 *
 * Guards against the following historical bug:
 *   DB::saveSnapshot() (formerly backupBeforeEdit()) was defined but never
 *   called — bdus_versions was always empty regardless of edits.
 *
 * What these tests verify:
 *   1. A row is written to bdus_versions before every UPDATE.
 *   2. No row is written for a pure INSERT (nothing to snapshot — record
 *      did not exist yet).
 *   3. A row with operation='delete' is written before erase().
 *   4. The snapshot content matches the record state BEFORE the edit.
 *   5. Plugin rows are included in the snapshot.
 */
class RecordPersistSnapshotTest extends BdusTestCase
{
    private const TB = 'items';

    // ── helpers ───────────────────────────────────────────────────────────────

    private function versionCount(string $tb, int $rowid, string $op = null): int
    {
        $sql  = "SELECT COUNT(*) AS cnt FROM bdus_versions WHERE tb = ? AND rowid = ?";
        $vals = [$tb, $rowid];
        if ($op !== null) {
            $sql  .= " AND operation = ?";
            $vals[] = $op;
        }
        return (int)(static::$db->query($sql, $vals, 'read')[0]['cnt'] ?? 0);
    }

    private function latestVersion(string $tb, int $rowid): ?array
    {
        $rows = static::$db->query(
            "SELECT * FROM bdus_versions WHERE tb = ? AND rowid = ? ORDER BY id DESC LIMIT 1",
            [$tb, $rowid],
            'read'
        );
        return $rows[0] ?? null;
    }

    // ── INSERT → no snapshot ──────────────────────────────────────────────────

    public function testInsertDoesNotCreateSnapshot(): void
    {
        $ctrl = $this->makeController('record_ctrl', [], [
            'tb'   => self::TB,
            'core' => ['name' => 'Snapshot-insert-test', 'status' => 'active', 'creator' => 'admin'],
        ]);
        $res = $this->callController($ctrl, 'saveRecord');
        $this->assertSame('success', $res['status']);

        $newId = (int)$res['id'];
        $this->assertSame(0, $this->versionCount(self::TB, $newId),
            'INSERT must not create a snapshot — the record did not exist before.'
        );

        // Clean up
        static::$db->query('DELETE FROM items WHERE id = ?', [$newId], 'boolean');
    }

    // ── UPDATE → snapshot with operation='update' ─────────────────────────────

    public function testUpdateCreatesSnapshot(): void
    {
        $beforeCount = $this->versionCount(self::TB, 1);

        $ctrl = $this->makeController('record_ctrl', [], [
            'tb'   => self::TB,
            'id'   => 1,
            'core' => ['description' => 'Snapshot update test'],
        ]);
        $res = $this->callController($ctrl, 'saveRecord');
        $this->assertSame('success', $res['status']);

        $this->assertSame(
            $beforeCount + 1,
            $this->versionCount(self::TB, 1),
            'UPDATE must produce exactly one new snapshot row.'
        );
        $this->assertSame(1, $this->versionCount(self::TB, 1, 'update'));

        // Restore original description
        static::$db->query(
            "UPDATE items SET description = 'First description' WHERE id = 1",
            [], 'boolean'
        );
    }

    // ── snapshot content: core fields captured BEFORE the edit ───────────────

    public function testSnapshotCoreReflectsStateBeforeEdit(): void
    {
        // Ensure a known description value
        static::$db->query(
            "UPDATE items SET description = 'Before snapshot' WHERE id = 2",
            [], 'boolean'
        );

        $ctrl = $this->makeController('record_ctrl', [], [
            'tb'   => self::TB,
            'id'   => 2,
            'core' => ['description' => 'After snapshot'],
        ]);
        $this->callController($ctrl, 'saveRecord');

        $ver = $this->latestVersion(self::TB, 2);
        $this->assertNotNull($ver);

        $content = json_decode($ver['content'], true);
        $this->assertIsArray($content);
        $this->assertArrayHasKey('core', $content);
        $this->assertSame('Before snapshot', $content['core']['description'],
            'Snapshot must capture the value BEFORE the edit, not after.'
        );

        // Restore
        static::$db->query(
            "UPDATE items SET description = 'Second description' WHERE id = 2",
            [], 'boolean'
        );
    }

    // ── snapshot content: plugins included ───────────────────────────────────

    public function testSnapshotIncludesPlugins(): void
    {
        // Item 1 has two tags seeded (tag-a, tag-b)
        $ctrl = $this->makeController('record_ctrl', [], [
            'tb'   => self::TB,
            'id'   => 1,
            'core' => ['status' => 'inactive'],
        ]);
        $this->callController($ctrl, 'saveRecord');

        $ver     = $this->latestVersion(self::TB, 1);
        $content = json_decode($ver['content'], true);

        $this->assertArrayHasKey('plugins', $content,
            'Snapshot must include a "plugins" key.'
        );
        $this->assertArrayHasKey('tags', $content['plugins'],
            'Plugin table "tags" must appear in the snapshot.'
        );
        $this->assertCount(2, $content['plugins']['tags'],
            'Both seeded tags must be captured in the snapshot.'
        );

        // Restore
        static::$db->query(
            "UPDATE items SET status = 'active' WHERE id = 1",
            [], 'boolean'
        );
    }

    // ── DELETE → snapshot with operation='delete' ─────────────────────────────

    public function testEraseCreatesDeleteSnapshot(): void
    {
        // Insert a throwaway record
        $newId = (int) static::$db->query(
            "INSERT INTO items (creator, name, status) VALUES ('admin', 'To-erase', 'active')",
            [], 'id'
        );

        $ctrl = $this->makeController('record_ctrl', ['tb' => self::TB, 'id' => $newId]);
        $res  = $this->callController($ctrl, 'erase');
        $this->assertSame('success', $res['status']);

        $this->assertSame(1, $this->versionCount(self::TB, $newId, 'delete'),
            'erase() must create exactly one snapshot with operation="delete".'
        );

        $ver     = $this->latestVersion(self::TB, $newId);
        $content = json_decode($ver['content'], true);
        $this->assertSame('To-erase', $content['core']['name'],
            'Delete snapshot must capture the name before deletion.'
        );
    }

    // ── no phantom snapshot when string value is unchanged ───────────────────

    public function testNoSnapshotWhenValueStringEqual(): void
    {
        // Seed: item 4 has status='pending' (string in DB).
        // Send status as the same string — must not create a snapshot.
        $countBefore = $this->versionCount(self::TB, 4);

        $ctrl = $this->makeController('record_ctrl', [], [
            'tb'   => self::TB,
            'id'   => 4,
            'core' => ['status' => 'pending'],  // same value already in DB
        ]);
        $res = $this->callController($ctrl, 'saveRecord');
        $this->assertSame('success', $res['status']);

        $this->assertSame(
            $countBefore,
            $this->versionCount(self::TB, 4),
            'No snapshot must be created when the submitted value equals the stored value.'
        );
    }

    // ── snapshot operation column is set correctly ────────────────────────────

    public function testSnapshotOperationColumnIsUpdate(): void
    {
        $ctrl = $this->makeController('record_ctrl', [], [
            'tb'   => self::TB,
            'id'   => 3,
            'core' => ['status' => 'pending'],
        ]);
        $this->callController($ctrl, 'saveRecord');

        $ver = $this->latestVersion(self::TB, 3);
        $this->assertSame('update', $ver['operation']);

        // Restore
        static::$db->query(
            "UPDATE items SET status = 'active' WHERE id = 3",
            [], 'boolean'
        );
    }
}
