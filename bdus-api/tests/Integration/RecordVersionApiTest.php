<?php

namespace Tests\Integration;

use Tests\Support\BdusTestCase;

/**
 * Integration tests for the three version-history API endpoints:
 *   getVersions    GET /api/record/{tb}/{id}/versions
 *   getVersionDiff GET /api/version/{id}
 *   restoreVersion POST /api/version/{id}/restore
 */
class RecordVersionApiTest extends BdusTestCase
{
    private const TB = 'items';

    // ── helpers ───────────────────────────────────────────────────────────────

    /** Create a snapshot by saving a record, return the version id. */
    private function triggerUpdate(int $recordId, array $fields): int
    {
        $countBefore = (int)(static::$db->query(
            "SELECT COUNT(*) AS c FROM bdus_versions WHERE tb = ? AND rowid = ?",
            [self::TB, $recordId], 'read'
        )[0]['c'] ?? 0);

        $ctrl = $this->makeController('record_ctrl', [], [
            'tb'   => self::TB,
            'id'   => $recordId,
            'core' => $fields,
        ]);
        $res = $this->callController($ctrl, 'saveRecord');
        $this->assertSame('success', $res['status']);

        $rows = static::$db->query(
            "SELECT id FROM bdus_versions WHERE tb = ? AND rowid = ? ORDER BY id DESC LIMIT 1",
            [self::TB, $recordId], 'read'
        );
        return (int)($rows[0]['id'] ?? 0);
    }

    // ── getVersions ───────────────────────────────────────────────────────────

    public function testGetVersionsMissingTbReturnsError(): void
    {
        $ctrl = $this->makeController('record_ctrl', ['id' => 1]);
        $res  = $this->callController($ctrl, 'getVersions');
        $this->assertSame('error', $res['status']);
        $this->assertSame('parameter_missing', $res['code']);
    }

    public function testGetVersionsMissingIdReturnsError(): void
    {
        $ctrl = $this->makeController('record_ctrl', ['tb' => self::TB]);
        $res  = $this->callController($ctrl, 'getVersions');
        $this->assertSame('error', $res['status']);
        $this->assertSame('parameter_missing', $res['code']);
    }

    public function testGetVersionsUnknownTableReturnsError(): void
    {
        $ctrl = $this->makeController('record_ctrl', ['tb' => 'nonexistent', 'id' => 1]);
        $res  = $this->callController($ctrl, 'getVersions');
        $this->assertSame('error', $res['status']);
        $this->assertSame('unknown_table', $res['code']);
    }

    public function testGetVersionsReturnsVersionsKey(): void
    {
        // Trigger at least one snapshot first
        $this->triggerUpdate(1, ['status' => 'inactive']);

        $ctrl = $this->makeController('record_ctrl', ['tb' => self::TB, 'id' => 1]);
        $res  = $this->callController($ctrl, 'getVersions');

        $this->assertSame('success', $res['status']);
        $this->assertArrayHasKey('versions', $res);
        $this->assertIsArray($res['versions']);
        $this->assertNotEmpty($res['versions']);

        // Restore
        static::$db->query("UPDATE items SET status = 'active' WHERE id = 1", [], 'boolean');
    }

    public function testGetVersionsEntriesHaveExpectedShape(): void
    {
        $this->triggerUpdate(2, ['status' => 'pending']);

        $ctrl = $this->makeController('record_ctrl', ['tb' => self::TB, 'id' => 2]);
        $res  = $this->callController($ctrl, 'getVersions');

        $entry = $res['versions'][0];
        $this->assertArrayHasKey('id',        $entry);
        $this->assertArrayHasKey('userid',    $entry);
        $this->assertArrayHasKey('time',      $entry);
        $this->assertArrayHasKey('operation', $entry);
        $this->assertSame('update', $entry['operation']);

        static::$db->query("UPDATE items SET status = 'inactive' WHERE id = 2", [], 'boolean');
    }

    public function testGetVersionsOrderedNewestFirst(): void
    {
        $v1 = $this->triggerUpdate(3, ['status' => 'inactive']);
        $v2 = $this->triggerUpdate(3, ['status' => 'active']);

        $ctrl = $this->makeController('record_ctrl', ['tb' => self::TB, 'id' => 3]);
        $res  = $this->callController($ctrl, 'getVersions');

        $ids = array_column($res['versions'], 'id');
        // Newest first → v2 appears before v1
        $this->assertLessThan(
            array_search($v1, $ids),
            array_search($v2, $ids),
            'Versions must be ordered newest-first.'
        );
    }

    // ── getVersionDiff ────────────────────────────────────────────────────────

    public function testGetVersionDiffMissingIdReturnsError(): void
    {
        $ctrl = $this->makeController('record_ctrl', []);
        $res  = $this->callController($ctrl, 'getVersionDiff');
        $this->assertSame('error', $res['status']);
        $this->assertSame('parameter_missing', $res['code']);
    }

    public function testGetVersionDiffUnknownIdReturnsError(): void
    {
        $ctrl = $this->makeController('record_ctrl', ['id' => 9999999]);
        $res  = $this->callController($ctrl, 'getVersionDiff');
        $this->assertSame('error', $res['status']);
        $this->assertSame('version_not_found', $res['code']);
    }

    public function testGetVersionDiffReturnsVersionAndCurrent(): void
    {
        // Record 4 starts as 'pending' (seed); use a different value so the
        // UPDATE is not a no-op and a snapshot is actually created.
        $vId = $this->triggerUpdate(4, ['status' => 'inactive']);

        $ctrl = $this->makeController('record_ctrl', ['id' => $vId]);
        $res  = $this->callController($ctrl, 'getVersionDiff');

        $this->assertSame('success', $res['status']);
        $this->assertArrayHasKey('version', $res);
        $this->assertArrayHasKey('current', $res);

        // version shape
        $v = $res['version'];
        $this->assertSame($vId, $v['id']);
        $this->assertArrayHasKey('content', $v);
        $this->assertArrayHasKey('core',    $v['content']);
        $this->assertArrayHasKey('plugins', $v['content']);

        // current shape
        $c = $res['current'];
        $this->assertArrayHasKey('core',    $c);
        $this->assertArrayHasKey('plugins', $c);
        $this->assertNotNull($c['core'], 'current.core must not be null for an existing record.');

        // Restore
        static::$db->query("UPDATE items SET status = 'pending' WHERE id = 4", [], 'boolean');
    }

    public function testGetVersionDiffCurrentCoreIsNullForDeletedRecord(): void
    {
        // Insert + snapshot + delete
        $newId = (int) static::$db->query(
            "INSERT INTO items (creator, name, status) VALUES ('admin', 'Diff-deleted', 'active')",
            [], 'id'
        );
        $vId = $this->triggerUpdate($newId, ['status' => 'inactive']);
        static::$db->query("DELETE FROM items WHERE id = ?", [$newId], 'boolean');

        $ctrl = $this->makeController('record_ctrl', ['id' => $vId]);
        $res  = $this->callController($ctrl, 'getVersionDiff');

        $this->assertNull($res['current']['core'],
            'current.core must be null when the record has been deleted.'
        );
    }

    // ── restoreVersion ────────────────────────────────────────────────────────

    public function testRestoreVersionMissingVersionIdReturnsError(): void
    {
        $ctrl = $this->makeController('record_ctrl', [], []);
        $res  = $this->callController($ctrl, 'restoreVersion');
        $this->assertSame('error', $res['status']);
        $this->assertSame('parameter_missing', $res['code']);
    }

    public function testRestoreVersionUnknownIdReturnsError(): void
    {
        $ctrl = $this->makeController('record_ctrl', ['id' => 9999999], []);
        $res  = $this->callController($ctrl, 'restoreVersion');
        $this->assertSame('error', $res['status']);
        $this->assertSame('version_not_found', $res['code']);
    }

    public function testRestoreVersionRestoresSelectedField(): void
    {
        // Set a known value, snapshot it, then change it
        static::$db->query("UPDATE items SET status = 'active' WHERE id = 5", [], 'boolean');
        $vId = $this->triggerUpdate(5, ['status' => 'inactive']); // snapshot captures 'active'

        // Now restore just the 'status' field back to the snapshot value
        $ctrl = $this->makeController('record_ctrl', ['id' => $vId], [
            'version_id'      => $vId,
            'fields'          => ['status'],
            'restore_plugins' => [],
        ]);
        $res = $this->callController($ctrl, 'restoreVersion');

        $this->assertSame('success', $res['status']);
        $this->assertSame('success_restored', $res['code']);
        $this->assertFalse($res['created']);

        // Verify DB
        $row = static::$db->query("SELECT status FROM items WHERE id = 5", [], 'read');
        $this->assertSame('active', $row[0]['status'],
            'Status must be restored to the snapshotted value.'
        );
    }

    public function testRestoreVersionCreatesPreRestoreSnapshot(): void
    {
        static::$db->query("UPDATE items SET status = 'active' WHERE id = 5", [], 'boolean');
        $vId = $this->triggerUpdate(5, ['status' => 'inactive']);

        $countBefore = (int)(static::$db->query(
            "SELECT COUNT(*) AS c FROM bdus_versions WHERE tb = ? AND rowid = ? AND operation = 'restore'",
            [self::TB, 5], 'read'
        )[0]['c'] ?? 0);

        $ctrl = $this->makeController('record_ctrl', ['id' => $vId], [
            'version_id' => $vId,
        ]);
        $this->callController($ctrl, 'restoreVersion');

        $countAfter = (int)(static::$db->query(
            "SELECT COUNT(*) AS c FROM bdus_versions WHERE tb = ? AND rowid = ? AND operation = 'restore'",
            [self::TB, 5], 'read'
        )[0]['c'] ?? 0);

        $this->assertSame($countBefore + 1, $countAfter,
            'restoreVersion must create a pre-restore snapshot with operation=restore.'
        );
    }

    public function testRestoreVersionRestoresDeletedRecord(): void
    {
        // Insert a record, snapshot it, then hard-delete it
        $newId = (int) static::$db->query(
            "INSERT INTO items (creator, name, status) VALUES ('admin', 'Restore-me', 'active')",
            [], 'id'
        );
        $vId = $this->triggerUpdate($newId, ['status' => 'inactive']); // snap has status=active
        static::$db->query("DELETE FROM items WHERE id = ?", [$newId], 'boolean');

        // Confirm it is gone
        $gone = static::$db->query("SELECT id FROM items WHERE id = ?", [$newId], 'read');
        $this->assertEmpty($gone);

        // Restore it via the version
        $ctrl = $this->makeController('record_ctrl', ['id' => $vId], ['version_id' => $vId]);
        $res  = $this->callController($ctrl, 'restoreVersion');

        $this->assertSame('success', $res['status']);
        $this->assertTrue($res['created'],
            'created must be true when the record is being re-inserted.'
        );

        // Confirm it is back
        $back = static::$db->query("SELECT name FROM items WHERE id = ?", [$newId], 'read');
        $this->assertNotEmpty($back);
        $this->assertSame('Restore-me', $back[0]['name']);

        // Clean up
        static::$db->query("DELETE FROM items WHERE id = ?", [$newId], 'boolean');
    }
}
