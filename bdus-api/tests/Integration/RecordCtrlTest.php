<?php

namespace Tests\Integration;

use Tests\Support\BdusTestCase;

/**
 * Integration tests for record_ctrl::getRecords().
 *
 * Instantiates the controller directly (no HTTP), injects the in-memory DB
 * and test Config, captures JSON output, and asserts on the decoded response.
 */
class RecordCtrlTest extends BdusTestCase
{
    private const TB = 'items';

    // ── all ───────────────────────────────────────────────────────────────

    public function testGetRecordsAllReturnsExpectedShape(): void
    {
        $ctrl = $this->makeController('record_ctrl', [
            'tb'          => self::TB,
            'page'        => 1,
            'per_page'    => 30,
            'sort_field'  => '',
            'sort_dir'    => 'asc',
            'search_type' => 'all',
        ]);
        $res = $this->callController($ctrl, 'getRecords');

        $this->assertArrayHasKey('total',  $res);
        $this->assertArrayHasKey('fields', $res);
        $this->assertArrayHasKey('data',   $res);
        $this->assertSame(5, $res['total']);
        $this->assertCount(5, $res['data']);
    }

    public function testGetRecordsFieldsHaveNameAndLabel(): void
    {
        $ctrl = $this->makeController('record_ctrl', ['tb' => self::TB, 'search_type' => 'all']);
        $res  = $this->callController($ctrl, 'getRecords');

        foreach ($res['fields'] as $f) {
            $this->assertArrayHasKey('name',  $f);
            $this->assertArrayHasKey('label', $f);
        }
    }

    public function testGetRecordsPaginates(): void
    {
        $ctrl = $this->makeController('record_ctrl', [
            'tb'          => self::TB,
            'page'        => 1,
            'per_page'    => 2,
            'search_type' => 'all',
        ]);
        $res = $this->callController($ctrl, 'getRecords');
        $this->assertSame(5, $res['total']);  // total unchanged
        $this->assertCount(2, $res['data']);  // page size respected
    }

    // ── fast search ───────────────────────────────────────────────────────

    public function testGetRecordsFastSearchFilters(): void
    {
        $ctrl = $this->makeController('record_ctrl', [
            'tb'          => self::TB,
            'search_type' => 'fast',
            'search'      => 'Alpha',
            'page'        => 1,
            'per_page'    => 30,
        ]);
        $res = $this->callController($ctrl, 'getRecords');

        $this->assertSame(1, $res['total']);
        $this->assertStringContainsStringIgnoringCase('Alpha', $res['data'][0]['name']);
    }

    // ── advanced search ───────────────────────────────────────────────────

    public function testGetRecordsFilterSearch(): void
    {
        $ctrl = $this->makeController(
            'record_ctrl',
            ['tb' => self::TB],
            [
                'page'    => 1,
                'per_page'=> 30,
                'filter'  => ['status' => ['_eq' => 'active']],
            ]
        );
        $res = $this->callController($ctrl, 'getRecords');

        $this->assertSame(3, $res['total']);
        foreach ($res['data'] as $row) {
            $this->assertSame('active', $row['status']);
        }
    }

    public function testGetRecordsFilterPluginCrossTable(): void
    {
        // item 1 has tags 'tag-a' and 'tag-b' — only 1 item has any tags
        $ctrl = $this->makeController(
            'record_ctrl',
            ['tb' => self::TB],
            ['filter' => ['tags' => ['label' => ['_icontains' => 'tag']]]]
        );
        $res = $this->callController($ctrl, 'getRecords');
        $this->assertSame(1, $res['total']);
    }

    // ── SQL expert ────────────────────────────────────────────────────────

    public function testGetRecordsSqlExpert(): void
    {
        $ctrl = $this->makeController(
            'record_ctrl',
            ['tb' => self::TB],
            ['search_type' => 'sqlExpert', 'querytext' => "name LIKE 'Alpha%'", 'join' => '']
        );
        $res = $this->callController($ctrl, 'getRecords');
        $this->assertSame(1, $res['total']);
    }

    public function testGetRecordsSqlExpertEmptyQuerytextReturnsAll(): void
    {
        $ctrl = $this->makeController(
            'record_ctrl',
            ['tb' => self::TB],
            ['search_type' => 'sqlExpert', 'querytext' => '', 'join' => '']
        );
        $res = $this->callController($ctrl, 'getRecords');
        $this->assertSame(5, $res['total']);
    }

    public function testGetRecordsSqlExpertInvalidColumnReturnsError(): void
    {
        $ctrl = $this->makeController(
            'record_ctrl',
            ['tb' => self::TB],
            ['search_type' => 'sqlExpert', 'querytext' => "nonexistent_col = 'x'", 'join' => '']
        );
        $res = $this->callController($ctrl, 'getRecords');
        $this->assertSame('error', $res['status']);
        $this->assertSame('db_error', $res['code']);
        // Raw DB engine detail must include the column name
        $this->assertStringContainsStringIgnoringCase('no such column', $res['detail']);
    }

    // ── JSON filter ──────────────────────────────────────────────────────

    public function testGetRecordsJsonFilterIdEq(): void
    {
        $ctrl = $this->makeController('record_ctrl', [
            'tb'     => self::TB,
            'filter' => ['id' => ['_eq' => 1]],
        ]);
        $res = $this->callController($ctrl, 'getRecords');
        $this->assertSame(1, $res['total']);
        $this->assertSame(1, (int)$res['data'][0]['id']);
    }

    public function testGetRecordsJsonFilterAndCondition(): void
    {
        $ctrl = $this->makeController('record_ctrl', [
            'tb'     => self::TB,
            'filter' => [
                'status' => ['_eq'        => 'active'],
                'name'   => ['_icontains' => 'Alpha'],
            ],
        ]);
        $res = $this->callController($ctrl, 'getRecords');
        $this->assertSame(1, $res['total']);
        $this->assertSame('Alpha item', $res['data'][0]['name']);
    }

    public function testGetRecordsJsonFilterOrCondition(): void
    {
        $ctrl = $this->makeController('record_ctrl', [
            'tb'     => self::TB,
            'filter' => ['_or' => [
                ['status' => ['_eq' => 'active']],
                ['status' => ['_eq' => 'pending']],
            ]],
        ]);
        $res = $this->callController($ctrl, 'getRecords');
        $this->assertGreaterThan(0, $res['total']);
    }

    public function testGetRecordsJsonFilterInOperator(): void
    {
        $ctrl = $this->makeController('record_ctrl', [
            'tb'     => self::TB,
            'filter' => ['id' => ['_in' => [1, 2]]],
        ]);
        $res = $this->callController($ctrl, 'getRecords');
        $this->assertSame(2, $res['total']);
    }

    // ── missing tb ───────────────────────────────────────────────────────

    public function testGetRecordsMissingTbReturnsError(): void
    {
        $ctrl = $this->makeController('record_ctrl', [/* no tb */]);
        $res  = $this->callController($ctrl, 'getRecords');
        $this->assertSame('error', $res['status']);
    }
}
