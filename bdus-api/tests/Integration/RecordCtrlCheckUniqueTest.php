<?php

namespace Tests\Integration;

use Tests\Support\BdusTestCase;

/**
 * Integration tests for GET /api/record/{tb}/check-unique.
 *
 * Uses the same `items` fixture as RecordCtrlValidationTest.
 * The `name` field has check="not_empty no_dupl".
 * Seeded rows: id=1 name='Alpha item', id=2 name='Beta item'.
 */
class RecordCtrlCheckUniqueTest extends BdusTestCase
{
    private const TB = 'items';

    // ── unique value ──────────────────────────────────────────────────────────

    public function testReturnsTrueForNewUniqueValue(): void
    {
        $ctrl = $this->makeController(
            'Bdus\\Controllers\\Record',
            ['tb' => self::TB, 'field' => 'name', 'value' => 'Completely new value']
        );
        $res = $this->callController($ctrl, 'checkUnique');

        $this->assertSame('success', $res['status']);
        $this->assertTrue($res['unique']);
    }

    // ── duplicate value ───────────────────────────────────────────────────────

    public function testReturnsFalseForExistingValueOnInsert(): void
    {
        $ctrl = $this->makeController(
            'Bdus\\Controllers\\Record',
            ['tb' => self::TB, 'field' => 'name', 'value' => 'Alpha item']
        );
        $res = $this->callController($ctrl, 'checkUnique');

        $this->assertSame('success', $res['status']);
        $this->assertFalse($res['unique']);
    }

    // ── self-exclusion on UPDATE ──────────────────────────────────────────────

    public function testReturnsTrueWhenValueBelongsToSameRecord(): void
    {
        // id=1 already has name='Alpha item' — updating it with its own value is fine
        $ctrl = $this->makeController(
            'Bdus\\Controllers\\Record',
            ['tb' => self::TB, 'field' => 'name', 'value' => 'Alpha item', 'id' => 1]
        );
        $res = $this->callController($ctrl, 'checkUnique');

        $this->assertSame('success', $res['status']);
        $this->assertTrue($res['unique'], 'A record updating its own value should be considered unique');
    }

    public function testReturnsFalseWhenValueBelongsToAnotherRecord(): void
    {
        // id=2 trying to take 'Alpha item' from id=1 → duplicate
        $ctrl = $this->makeController(
            'Bdus\\Controllers\\Record',
            ['tb' => self::TB, 'field' => 'name', 'value' => 'Alpha item', 'id' => 2]
        );
        $res = $this->callController($ctrl, 'checkUnique');

        $this->assertSame('success', $res['status']);
        $this->assertFalse($res['unique']);
    }

    // ── parameter validation ──────────────────────────────────────────────────

    public function testRejectsMissingField(): void
    {
        $ctrl = $this->makeController(
            'Bdus\\Controllers\\Record',
            ['tb' => self::TB, 'value' => 'something']
        );
        $res = $this->callController($ctrl, 'checkUnique');

        $this->assertSame('error', $res['status']);
        $this->assertSame('parameter_missing', $res['code']);
    }

    public function testRejectsMissingValue(): void
    {
        $ctrl = $this->makeController(
            'Bdus\\Controllers\\Record',
            ['tb' => self::TB, 'field' => 'name']
        );
        $res = $this->callController($ctrl, 'checkUnique');

        $this->assertSame('error', $res['status']);
        $this->assertSame('parameter_missing', $res['code']);
    }

    // ── security: unknown field rejected ─────────────────────────────────────

    public function testRejectsFieldNotInTableConfig(): void
    {
        $ctrl = $this->makeController(
            'Bdus\\Controllers\\Record',
            ['tb' => self::TB, 'field' => 'nonexistent_column', 'value' => 'x']
        );
        $res = $this->callController($ctrl, 'checkUnique');

        $this->assertSame('error', $res['status']);
        $this->assertSame('field_not_found', $res['code']);
    }
}
