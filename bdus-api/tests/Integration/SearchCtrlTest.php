<?php

namespace Tests\Integration;

use Tests\Support\BdusTestCase;

/**
 * Integration tests for search_ctrl.
 */
class SearchCtrlTest extends BdusTestCase
{
    private const TB = 'items';

    // ── getAdvancedConfig ─────────────────────────────────────────────────

    public function testGetAdvancedConfigReturnsCorrectShape(): void
    {
        $ctrl = $this->makeController('search_ctrl', ['tb' => self::TB]);
        $res  = $this->callController($ctrl, 'getAdvancedConfig');

        $this->assertArrayHasKey('fields',     $res);
        $this->assertArrayHasKey('operators',  $res);
        $this->assertArrayHasKey('connectors', $res);

        $this->assertIsArray($res['fields']);
        $this->assertIsArray($res['operators']);
        $this->assertIsArray($res['connectors']);
    }

    public function testGetAdvancedConfigFieldsHaveValueAndLabel(): void
    {
        $ctrl   = $this->makeController('search_ctrl', ['tb' => self::TB]);
        $res    = $this->callController($ctrl, 'getAdvancedConfig');

        foreach ($res['fields'] as $f) {
            $this->assertArrayHasKey('value', $f);
            $this->assertArrayHasKey('label', $f);
            // value must be in the form "tb:field"
            $this->assertStringContainsString(':', $f['value']);
        }
    }

    public function testGetAdvancedConfigIncludesExpectedFields(): void
    {
        $ctrl    = $this->makeController('search_ctrl', ['tb' => self::TB]);
        $res     = $this->callController($ctrl, 'getAdvancedConfig');
        $values  = array_column($res['fields'], 'value');

        $this->assertContains('items:name',        $values);
        $this->assertContains('items:description', $values);
        $this->assertContains('items:status',      $values);
    }

    public function testGetAdvancedConfigOperatorsCount(): void
    {
        $ctrl = $this->makeController('search_ctrl', ['tb' => self::TB]);
        $res  = $this->callController($ctrl, 'getAdvancedConfig');
        // We define 9 operators
        $this->assertCount(9, $res['operators']);
    }

    public function testGetAdvancedConfigOperatorsHaveValueAndKey(): void
    {
        $ctrl = $this->makeController('search_ctrl', ['tb' => self::TB]);
        $res  = $this->callController($ctrl, 'getAdvancedConfig');

        foreach ($res['operators'] as $op) {
            $this->assertArrayHasKey('value', $op, 'Operator missing value');
            $this->assertArrayHasKey('key',   $op, 'Operator missing key (i18n key, no label)');
            $this->assertArrayNotHasKey('label', $op, 'Operator must not carry translated label');
        }
        // Spot-check a known pair
        $byValue = array_column($res['operators'], 'key', 'value');
        $this->assertSame('contains',   $byValue['_icontains']);
        $this->assertSame('is_exactly', $byValue['_eq']);
    }

    public function testGetAdvancedConfigConnectorsAreAndOrXor(): void
    {
        $ctrl = $this->makeController('search_ctrl', ['tb' => self::TB]);
        $res  = $this->callController($ctrl, 'getAdvancedConfig');

        // connectors are now plain strings: ['AND', 'OR', 'XOR']
        $this->assertContains('AND', $res['connectors']);
        $this->assertContains('OR',  $res['connectors']);
        $this->assertContains('XOR', $res['connectors']);
    }

    public function testGetAdvancedConfigMissingTbReturnsError(): void
    {
        $ctrl = $this->makeController('search_ctrl', [/* no tb */]);
        $res  = $this->callController($ctrl, 'getAdvancedConfig');
        $this->assertSame('error', $res['status']);
    }

    // ── getUsedValues ─────────────────────────────────────────────────────

    public function testGetUsedValuesReturnsArray(): void
    {
        $ctrl = $this->makeController('search_ctrl', [
            'tb'  => self::TB,
            'fld' => 'status',
        ]);
        ob_start();
        $ctrl->getUsedValues();
        $raw = ob_get_clean();
        $values = json_decode($raw, true);

        $this->assertSame('success', $values['status']);
        $this->assertIsArray($values['values']);
        $this->assertContains('active',   $values['values']);
        $this->assertContains('inactive', $values['values']);
        $this->assertContains('pending',  $values['values']);
    }

    // ── test (query tester) ───────────────────────────────────────────────

    public function testQueryTesterSuccessOnValidQuery(): void
    {
        $ctrl = $this->makeController(
            'search_ctrl',
            ['tb' => self::TB],
            ['type' => 'all', 'tb' => self::TB]
        );
        $res = $this->callController($ctrl, 'test');
        $this->assertSame('success', $res['status']);
    }
}
