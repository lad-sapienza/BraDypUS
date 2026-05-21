<?php

namespace Tests\Integration;

use Tests\Support\BdusTestCase;

/**
 * Integration tests for GET /api/record/{tb}/deleted  (getDeletedRecords)
 */
class RecordDeletedApiTest extends BdusTestCase
{
    private const TB = 'items';

    // ── helpers ──────────────────────────────────────────────────────────────

    private function callGetDeleted(string $tb): array
    {
        $ctrl = $this->makeController('record_ctrl', ['tb' => $tb]);
        return $this->callController($ctrl, 'getDeletedRecords');
    }

    /**
     * Insert a row directly, snapshot + erase it, return the version id
     * and the new record id.
     */
    private function insertAndDelete(string $name): array
    {
        $id = (int) static::$db->query(
            "INSERT INTO items (creator, name, status) VALUES ('admin', ?, 'active')",
            [$name], 'id'
        );

        // Snapshot via triggerUpdate then hard-delete via controller erase
        $countBefore = (int)(static::$db->query(
            "SELECT COUNT(*) AS c FROM bdus_versions WHERE tb = ? AND rowid = ?",
            [self::TB, $id], 'read'
        )[0]['c'] ?? 0);

        $ctrl = $this->makeController('record_ctrl', [], [
            'tb'   => self::TB,
            'id'   => $id,
            'core' => ['status' => 'inactive'],
        ]);
        $this->callController($ctrl, 'saveRecord');

        // Now erase the record (creates a 'delete' snapshot)
        $ctrl = $this->makeController('record_ctrl', [
            'tb' => self::TB,
            'id' => $id,
        ]);
        $res = $this->callController($ctrl, 'erase');
        $this->assertSame('success', $res['status']);

        $rows = static::$db->query(
            "SELECT id FROM bdus_versions WHERE tb = ? AND rowid = ? AND operation = 'delete' ORDER BY id DESC LIMIT 1",
            [self::TB, $id], 'read'
        );
        $versionId = (int)($rows[0]['id'] ?? 0);

        return ['id' => $id, 'version_id' => $versionId];
    }

    // ── error paths ───────────────────────────────────────────────────────────

    public function testMissingTbReturnsError(): void
    {
        $ctrl = $this->makeController('record_ctrl', []);
        $res  = $this->callController($ctrl, 'getDeletedRecords');
        $this->assertSame('error', $res['status']);
        $this->assertSame('parameter_missing', $res['code']);
    }

    public function testUnknownTableReturnsError(): void
    {
        $res = $this->callGetDeleted('nonexistent_table');
        $this->assertSame('error', $res['status']);
        $this->assertSame('unknown_table', $res['code']);
    }

    // ── success paths ─────────────────────────────────────────────────────────

    public function testReturnsDeletedKey(): void
    {
        $res = $this->callGetDeleted(self::TB);
        $this->assertSame('success', $res['status']);
        $this->assertArrayHasKey('deleted', $res);
        $this->assertIsArray($res['deleted']);
    }

    public function testDeletedRecordAppearsInList(): void
    {
        $r = $this->insertAndDelete('Deleted-Alpha');

        $res  = $this->callGetDeleted(self::TB);
        $ids  = array_column($res['deleted'], 'rowid');
        $this->assertContains($r['id'], $ids, 'Deleted record must appear in the list.');
    }

    public function testDeletedEntryShape(): void
    {
        $r    = $this->insertAndDelete('Deleted-Beta');
        $res  = $this->callGetDeleted(self::TB);

        $entry = null;
        foreach ($res['deleted'] as $row) {
            if ((int)$row['rowid'] === $r['id']) { $entry = $row; break; }
        }
        $this->assertNotNull($entry, 'Entry for deleted record not found.');

        $this->assertArrayHasKey('version_id', $entry);
        $this->assertArrayHasKey('rowid',      $entry);
        $this->assertArrayHasKey('userid',     $entry);
        $this->assertArrayHasKey('time',       $entry);
        $this->assertArrayHasKey('content',    $entry);
        $this->assertArrayHasKey('core',    $entry['content']);
        $this->assertArrayHasKey('plugins', $entry['content']);
        $this->assertSame($r['version_id'], (int)$entry['version_id']);
    }

    public function testRestoredRecordDisappearsFromList(): void
    {
        $r = $this->insertAndDelete('Deleted-Gamma');

        // Verify it appears first
        $res  = $this->callGetDeleted(self::TB);
        $ids  = array_column($res['deleted'], 'rowid');
        $this->assertContains($r['id'], $ids);

        // Restore it
        $ctrl = $this->makeController('record_ctrl', ['id' => $r['version_id']], [
            'version_id' => $r['version_id'],
        ]);
        $restore = $this->callController($ctrl, 'restoreVersion');
        $this->assertSame('success', $restore['status']);

        // Should no longer appear
        $res2 = $this->callGetDeleted(self::TB);
        $ids2 = array_column($res2['deleted'], 'rowid');
        $this->assertNotContains($r['id'], $ids2,
            'Restored record must not appear in the deleted list.');

        // Clean up
        static::$db->query("DELETE FROM items WHERE id = ?", [$r['id']], 'boolean');
    }

    public function testLiveRecordsDoNotAppear(): void
    {
        // Seed records 1-5 are alive and have no delete snapshot
        $res  = $this->callGetDeleted(self::TB);
        $ids  = array_column($res['deleted'], 'rowid');
        foreach ([1, 2, 3, 4, 5] as $liveId) {
            $this->assertNotContains($liveId, $ids,
                "Live record #{$liveId} must not appear in deleted list.");
        }
    }
}
