<?php

namespace Tests\Integration;

use Tests\Support\BdusTestCase;

/**
 * Integration tests for chart_ctrl v5 endpoints:
 *   getData(), listCharts(), saveChart(), shareChart(), unshareChart(), deleteChart()
 *
 * The charts table is created in createSchema() and seeded in seedData().
 * Tests use the items table (5 seeded rows) for chart data queries.
 */
class ChartCtrlTest extends BdusTestCase
{
    // ── Schema extension ──────────────────────────────────────────────────────

    protected static function createSchema(): void
    {
        parent::createSchema();

        // users table required for charts FK
        static::$db->execInTransaction('
            CREATE TABLE users (
                id        INTEGER PRIMARY KEY AUTOINCREMENT,
                name      TEXT    NOT NULL,
                email     TEXT    NOT NULL,
                privilege INTEGER NOT NULL DEFAULT 99
            )
        ');

        static::$db->execInTransaction('
            CREATE TABLE charts (
                id         INTEGER PRIMARY KEY AUTOINCREMENT,
                user_id    INTEGER NOT NULL,
                created_at INTEGER,
                name       TEXT    NOT NULL,
                definition TEXT,
                is_global  INTEGER
            )
        ');
    }

    // ── Seed extension ────────────────────────────────────────────────────────

    protected static function seedData(): void
    {
        parent::seedData();

        static::$db->execInTransaction(
            "INSERT INTO users (id, name, email, privilege) VALUES (1, 'Test Admin', 'test@example.com', 1)"
        );

        // Seed one chart owned by user 1
        static::$db->execInTransaction(
            "INSERT INTO charts (user_id, created_at, name, definition, is_global)
             VALUES (1, " . time() . ", 'My first chart',
                     '{\"tb\":\"items\",\"type\":\"metric\",\"field\":\"id\",\"function\":\"COUNT\"}', 0)"
        );
    }

    // ── getData: metric ───────────────────────────────────────────────────────

    public function testGetDataMetricSuccess(): void
    {
        $ctrl = $this->makeController('chart_ctrl', [], [
            'definition' => [
                'tb'       => 'items',
                'type'     => 'metric',
                'field'    => 'id',
                'function' => 'COUNT',
            ],
        ]);
        $res = $this->callController($ctrl, 'getData');

        $this->assertSame('success', $res['status']);
        $this->assertSame('metric', $res['type']);
        $this->assertArrayHasKey('value', $res);
        $this->assertEquals(5, (int) $res['value']); // 5 seeded items
    }

    // ── getData: bar ──────────────────────────────────────────────────────────

    public function testGetDataBarSuccess(): void
    {
        $ctrl = $this->makeController('chart_ctrl', [], [
            'definition' => [
                'tb'         => 'items',
                'type'       => 'bar',
                'x_field'    => 'status',
                'y_field'    => 'id',
                'y_function' => 'COUNT',
            ],
        ]);
        $res = $this->callController($ctrl, 'getData');

        $this->assertSame('success', $res['status']);
        $this->assertSame('bar', $res['type']);
        $this->assertArrayHasKey('labels', $res);
        $this->assertArrayHasKey('data', $res);
        $this->assertIsArray($res['labels']);
        $this->assertIsArray($res['data']);
        $this->assertNotEmpty($res['labels']);
    }

    // ── getData: invalid type ─────────────────────────────────────────────────

    public function testGetDataInvalidType(): void
    {
        $ctrl = $this->makeController('chart_ctrl', [], [
            'definition' => [
                'tb'   => 'items',
                'type' => 'treemap', // not a valid type
            ],
        ]);
        $res = $this->callController($ctrl, 'getData');

        $this->assertSame('error', $res['status']);
        $this->assertSame('invalid_chart_type', $res['code']);
    }

    // ── getData: invalid field ────────────────────────────────────────────────

    public function testGetDataInvalidField(): void
    {
        $ctrl = $this->makeController('chart_ctrl', [], [
            'definition' => [
                'tb'       => 'items',
                'type'     => 'metric',
                'field'    => 'nonexistent_column',
                'function' => 'COUNT',
            ],
        ]);
        $res = $this->callController($ctrl, 'getData');

        $this->assertSame('error', $res['status']);
        $this->assertSame('invalid_chart_field', $res['code']);
    }

    // ── listCharts ────────────────────────────────────────────────────────────

    public function testListChartsSuccess(): void
    {
        $ctrl = $this->makeController('chart_ctrl');
        $res  = $this->callController($ctrl, 'listCharts');

        $this->assertSame('success', $res['status']);
        $this->assertArrayHasKey('charts', $res);
        $this->assertIsArray($res['charts']);
    }

    public function testListChartsContainsSeedRow(): void
    {
        $ctrl = $this->makeController('chart_ctrl');
        $res  = $this->callController($ctrl, 'listCharts');

        $this->assertNotEmpty($res['charts']);
        $names = array_column($res['charts'], 'name');
        $this->assertContains('My first chart', $names);
    }

    // ── saveChart ─────────────────────────────────────────────────────────────

    public function testSaveChartSuccess(): void
    {
        $ctrl = $this->makeController('chart_ctrl', [], [
            'name'       => 'Test chart',
            'definition' => [
                'tb'       => 'items',
                'type'     => 'metric',
                'field'    => 'id',
                'function' => 'COUNT',
            ],
        ]);
        $res = $this->callController($ctrl, 'saveChart');

        $this->assertSame('success', $res['status']);
        $this->assertSame('ok_save_chart', $res['code']);
    }

    public function testSaveChartReturnsSavedObject(): void
    {
        $ctrl = $this->makeController('chart_ctrl', [], [
            'name'       => 'Shaped chart',
            'definition' => [
                'tb'       => 'items',
                'type'     => 'metric',
                'field'    => 'id',
                'function' => 'COUNT',
            ],
        ]);
        $res = $this->callController($ctrl, 'saveChart');

        $this->assertSame('success', $res['status']);
        $this->assertArrayHasKey('chart', $res);
        $c = $res['chart'];
        foreach (['id', 'name', 'definition', 'is_global', 'owned_by_me'] as $k) {
            $this->assertArrayHasKey($k, $c, "Missing key: $k in returned chart");
        }
        $this->assertSame('Shaped chart', $c['name']);
        $this->assertSame(0, (int) $c['is_global']);
        $this->assertTrue($c['owned_by_me']);
        $this->assertIsArray($c['definition']);
        $this->assertSame('items', $c['definition']['tb']);
    }

    public function testSaveChartMissingName(): void
    {
        $ctrl = $this->makeController('chart_ctrl', [], [
            'definition' => [
                'tb'       => 'items',
                'type'     => 'metric',
                'field'    => 'id',
                'function' => 'COUNT',
            ],
        ]);
        $res = $this->callController($ctrl, 'saveChart');

        $this->assertSame('error', $res['status']);
        $this->assertSame('parameter_missing', $res['code']);
    }

    // ── shareChart ────────────────────────────────────────────────────────────

    public function testShareChartSuccess(): void
    {
        // Save a fresh chart
        $save = $this->makeController('chart_ctrl', [], [
            'name'       => 'To share',
            'definition' => [
                'tb'       => 'items',
                'type'     => 'metric',
                'field'    => 'id',
                'function' => 'COUNT',
            ],
        ]);
        $saved = $this->callController($save, 'saveChart');
        $id    = $saved['chart']['id'];

        $ctrl = $this->makeController('chart_ctrl', [], ['id' => $id]);
        $res  = $this->callController($ctrl, 'shareChart');

        $this->assertSame('success', $res['status']);
        $this->assertSame('ok_sharing_chart', $res['code']);

        // Verify is_global=1 in list
        $list = $this->callController($this->makeController('chart_ctrl'), 'listCharts');
        $row  = array_values(array_filter($list['charts'], fn($c) => (int)$c['id'] === (int)$id))[0] ?? null;
        $this->assertNotNull($row);
        $this->assertSame(1, (int) $row['is_global']);
    }

    // ── deleteChart ───────────────────────────────────────────────────────────

    public function testDeleteChartSuccess(): void
    {
        // Save a chart to delete
        $save = $this->makeController('chart_ctrl', [], [
            'name'       => 'To delete',
            'definition' => [
                'tb'       => 'items',
                'type'     => 'metric',
                'field'    => 'id',
                'function' => 'COUNT',
            ],
        ]);
        $saved = $this->callController($save, 'saveChart');
        $id    = $saved['chart']['id'];

        $ctrl = $this->makeController('chart_ctrl', [], ['id' => $id]);
        $res  = $this->callController($ctrl, 'deleteChart');

        $this->assertSame('success', $res['status']);
        $this->assertSame('ok_chart_erase', $res['code']);

        // Verify it is gone
        $list = $this->callController($this->makeController('chart_ctrl'), 'listCharts');
        $ids  = array_column($list['charts'], 'id');
        $this->assertNotContains($id, $ids);
    }

    public function testDeleteChartNotFound(): void
    {
        $ctrl = $this->makeController('chart_ctrl', [], ['id' => 999999]);
        $res  = $this->callController($ctrl, 'deleteChart');

        $this->assertSame('error', $res['status']);
        $this->assertSame('chart_not_found', $res['code']);
    }
}
