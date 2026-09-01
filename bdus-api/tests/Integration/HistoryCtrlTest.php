<?php

namespace Tests\Integration;

use Tests\Support\BdusTestCase;

/**
 * Integration tests for MyHistory::getHistory().
 */
class HistoryCtrlTest extends BdusTestCase
{
    protected static function seedData(): void
    {
        parent::seedData();

        $now = time();
        static::$db->execInTransaction(
            "INSERT INTO bdus_versions (userid, time, tb, rowid, content, editsql, editvalues, operation)
             VALUES
               (1, {$now},          'items', 1, '{\"name\":\"Alpha\"}', NULL, NULL, 'update'),
               (1, " . ($now - 10) . ", 'items', 2, '{\"name\":\"Beta\"}',  NULL, NULL, 'update'),
               (2, " . ($now - 20) . ", 'tags',  1, '{\"label\":\"x\"}',    NULL, NULL, 'update')"
        );
    }

    // ── getHistory ────────────────────────────────────────────────────────────

    public function testGetHistoryReturnsExpectedShape(): void
    {
        $ctrl = $this->makeController('Bdus\\Controllers\\MyHistory');
        $res  = $this->callController($ctrl, 'getHistory');

        $this->assertSame('success', $res['status']);
        $this->assertArrayHasKey('total', $res);
        $this->assertArrayHasKey('data',  $res);
        $this->assertSame(3, $res['total']);
    }

    public function testGetHistoryRowShape(): void
    {
        $ctrl = $this->makeController('Bdus\\Controllers\\MyHistory');
        $res  = $this->callController($ctrl, 'getHistory');

        $row = $res['data'][0];
        foreach (['id', 'user', 'time', 'tb', 'rowid', 'content'] as $key) {
            $this->assertArrayHasKey($key, $row, "Missing key: $key");
        }
    }

    public function testGetHistoryDefaultsNewestFirst(): void
    {
        $ctrl = $this->makeController('Bdus\\Controllers\\MyHistory');
        $res  = $this->callController($ctrl, 'getHistory');

        $ids = array_column($res['data'], 'id');
        $this->assertGreaterThan($ids[1], $ids[0], 'Rows should be newest first');
    }

    public function testGetHistoryTimeIsFormattedString(): void
    {
        $ctrl = $this->makeController('Bdus\\Controllers\\MyHistory');
        $res  = $this->callController($ctrl, 'getHistory');

        $time = $res['data'][0]['time'];
        $this->assertMatchesRegularExpression('/^\d{4}-\d{2}-\d{2} \d{2}:\d{2}:\d{2}$/', $time);
    }

    public function testGetHistoryFilterByTb(): void
    {
        $ctrl = $this->makeController('Bdus\\Controllers\\MyHistory', ['tb' => 'tags']);
        $res  = $this->callController($ctrl, 'getHistory');

        $this->assertSame(1, $res['total']);
        $this->assertSame('tags', $res['data'][0]['tb']);
    }

    public function testGetHistoryFilterByUser(): void
    {
        $ctrl = $this->makeController('Bdus\\Controllers\\MyHistory', ['user' => '2']);
        $res  = $this->callController($ctrl, 'getHistory');

        $this->assertSame(1, $res['total']);
    }

    public function testGetHistoryPaginationRespectsPerPage(): void
    {
        $ctrl = $this->makeController('Bdus\\Controllers\\MyHistory', ['page' => 1, 'per_page' => 1]);
        $res  = $this->callController($ctrl, 'getHistory');

        $this->assertSame(3, $res['total']); // total unchanged
        $this->assertCount(1, $res['data']); // page size respected
    }

    public function testGetHistoryPageTwoReturnsOffsetResults(): void
    {
        $ctrl = $this->makeController('Bdus\\Controllers\\MyHistory', ['page' => 2, 'per_page' => 2]);
        $res  = $this->callController($ctrl, 'getHistory');

        $this->assertSame(3, $res['total']);
        $this->assertCount(1, $res['data']);
    }

    public function testGetHistoryNotEnoughPrivilege(): void
    {
        $this->setPrivilege(99);

        $ctrl = $this->makeController('Bdus\\Controllers\\MyHistory');
        $res  = $this->callController($ctrl, 'getHistory');

        $this->assertSame('error', $res['status']);
        $this->assertSame('not_enough_privilege', $res['code']);

        $this->setPrivilege(1);
    }
}
