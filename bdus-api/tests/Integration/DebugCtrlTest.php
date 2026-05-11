<?php

namespace Tests\Integration;

use Tests\Support\BdusTestCase;

/**
 * Integration tests for debug_ctrl (Log viewer).
 */
class DebugCtrlTest extends BdusTestCase
{
    // ── getLogs ───────────────────────────────────────────────────────────

    public function testGetLogsReturnsExpectedShape(): void
    {
        $ctrl = $this->makeController('debug_ctrl', ['page' => 1, 'per_page' => 50]);
        $res  = $this->callController($ctrl, 'getLogs');

        $this->assertArrayHasKey('total', $res);
        $this->assertArrayHasKey('data',  $res);
        $this->assertSame(2, $res['total']); // seeded 2 log entries
    }

    public function testGetLogsRowHasRequiredKeys(): void
    {
        $ctrl = $this->makeController('debug_ctrl', ['page' => 1, 'per_page' => 50]);
        $res  = $this->callController($ctrl, 'getLogs');

        $row = $res['data'][0];
        foreach (['id', 'channel', 'level', 'level_name', 'message', 'time'] as $key) {
            $this->assertArrayHasKey($key, $row, "Missing key: $key");
        }
    }

    public function testGetLogsLevelNameIsMapped(): void
    {
        $ctrl = $this->makeController('debug_ctrl', ['page' => 1, 'per_page' => 50]);
        $res  = $this->callController($ctrl, 'getLogs');

        $byLevel = array_column($res['data'], 'level_name', 'level');
        $this->assertSame('INFO',  $byLevel[200]);
        $this->assertSame('ERROR', $byLevel[400]);
    }

    public function testGetLogsFilterByLevel(): void
    {
        $ctrl = $this->makeController('debug_ctrl', ['page' => 1, 'per_page' => 50, 'level' => 400]);
        $res  = $this->callController($ctrl, 'getLogs');

        $this->assertSame(1, $res['total']);
        $this->assertSame(400, $res['data'][0]['level']);
    }

    public function testGetLogsFilterBySearch(): void
    {
        $ctrl = $this->makeController('debug_ctrl', ['page' => 1, 'per_page' => 50, 'search' => 'Error']);
        $res  = $this->callController($ctrl, 'getLogs');

        $this->assertSame(1, $res['total']);
        $this->assertStringContainsStringIgnoringCase('error', strtolower($res['data'][0]['message']));
    }

    public function testGetLogsNewestFirst(): void
    {
        $ctrl = $this->makeController('debug_ctrl', ['page' => 1, 'per_page' => 50]);
        $res  = $this->callController($ctrl, 'getLogs');

        $ids = array_column($res['data'], 'id');
        $this->assertGreaterThan($ids[1], $ids[0], 'Rows should be ordered newest (highest id) first');
    }

    public function testGetLogsPagination(): void
    {
        $ctrl = $this->makeController('debug_ctrl', ['page' => 1, 'per_page' => 1]);
        $res  = $this->callController($ctrl, 'getLogs');

        $this->assertSame(2, $res['total']); // total unchanged
        $this->assertCount(1, $res['data']); // page size respected
    }

    // ── purgeLogs ─────────────────────────────────────────────────────────

    public function testPurgeLogsDeletesOldEntries(): void
    {
        // The seeded INFO entry is 3600s old — purge entries older than 30 min
        $ctrl = $this->makeController('debug_ctrl', [], ['days' => 0]); // 0 days = all before now
        // Note: days is clamped to min 1 inside purgeLogs(), so use a short period
        // Instead: seed a very old entry and purge it
        static::$db->execInTransaction(
            "INSERT INTO test__log (channel, level, message, time)
             VALUES ('test', 200, 'Very old entry', " . (time() - 86400 * 10) . ")"
        );

        // Purge entries older than 5 days
        $ctrl = $this->makeController('debug_ctrl', [], ['days' => 5]);
        $res  = $this->callController($ctrl, 'purgeLogs');

        $this->assertSame('success', $res['status']);
        $this->assertSame(1, $res['deleted']); // only the 10-day-old entry
    }
}
