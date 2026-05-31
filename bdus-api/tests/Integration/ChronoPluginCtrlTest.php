<?php

declare(strict_types=1);

namespace Tests\Integration;

use Tests\Support\BdusTestCase;

/**
 * Integration tests for the fuzzy-date plugin endpoints:
 *   POST   /api/config/table/{tb}/fuzzy-date  → Config::activateFuzzyDate()
 *   DELETE /api/config/table/{tb}/fuzzy-date  → Config::deactivateFuzzyDate()
 *
 * Side-effects tested:
 *   - The five chrono_* columns are created in the core table on activation
 *   - The config flag fuzzy_date is set/cleared correctly
 *   - buildTableSchema() reflects has_fuzzy_date
 *   - Saved chrono values survive a round-trip through saveRecord / getRecord
 *   - Non-super-admin callers are rejected
 *   - Missing / unknown table names are rejected
 *   - Activation is idempotent (safe to call twice)
 */
class ChronoPluginCtrlTest extends BdusTestCase
{
    private const TB = 'items';

    // ── activateFuzzyDate ─────────────────────────────────────────────────────

    public function testActivateAddsFiveColumns(): void
    {
        $ctrl = $this->makeController('Bdus\\Controllers\\Config', ['tb' => self::TB]);
        $res  = $this->callController($ctrl, 'activateFuzzyDate');

        $this->assertSame('success',           $res['status']);
        $this->assertSame('fuzzy_date_activated', $res['code']);

        // Verify columns actually exist in the SQLite schema
        $cols = static::$db->query('PRAGMA table_info(' . self::TB . ')', [], 'read');
        $names = array_column($cols, 'name');

        $this->assertContains('chrono_from',      $names);
        $this->assertContains('chrono_to',        $names);
        $this->assertContains('chrono_label',     $names);
        $this->assertContains('chrono_certainty', $names);
        $this->assertContains('chrono_period',    $names);
    }

    public function testActivateSetsConfigFlag(): void
    {
        $ctrl = $this->makeController('Bdus\\Controllers\\Config', ['tb' => self::TB]);
        $this->callController($ctrl, 'activateFuzzyDate');

        // Config should now report fuzzy_date = true
        $cfgCtrl = $this->makeController('Bdus\\Controllers\\Config', ['tb' => self::TB]);
        $cfg     = $this->callController($cfgCtrl, 'getTableConfig');

        $this->assertTrue((bool)($cfg['table']['fuzzy_date'] ?? false));
    }

    public function testActivateIsIdempotent(): void
    {
        $ctrl = $this->makeController('Bdus\\Controllers\\Config', ['tb' => self::TB]);
        $this->callController($ctrl, 'activateFuzzyDate');

        // Second call must succeed without error (ADD COLUMN if not exists)
        $ctrl2 = $this->makeController('Bdus\\Controllers\\Config', ['tb' => self::TB]);
        $res   = $this->callController($ctrl2, 'activateFuzzyDate');

        $this->assertSame('success', $res['status']);
    }

    public function testActivateRequiresSuperAdmin(): void
    {
        $this->setPrivilege(11);
        $ctrl = $this->makeController('Bdus\\Controllers\\Config', ['tb' => self::TB]);
        $res  = $this->callController($ctrl, 'activateFuzzyDate');
        $this->setPrivilege(1);

        $this->assertSame('error',                $res['status']);
        $this->assertSame('not_enough_privilege', $res['code']);
    }

    public function testActivateMissingTableReturnsError(): void
    {
        $ctrl = $this->makeController('Bdus\\Controllers\\Config', ['tb' => '']);
        $res  = $this->callController($ctrl, 'activateFuzzyDate');

        $this->assertSame('error',         $res['status']);
        $this->assertSame('missing_table', $res['code']);
    }

    // ── buildTableSchema reflects has_fuzzy_date ──────────────────────────────

    public function testHasFuzzyDateFalseAfterDeactivation(): void
    {
        // Activate then immediately deactivate to ensure a clean false state
        $ctrl = $this->makeController('Bdus\\Controllers\\Config', ['tb' => self::TB]);
        $this->callController($ctrl, 'activateFuzzyDate');

        $ctrl2 = $this->makeController('Bdus\\Controllers\\Config', ['tb' => self::TB]);
        $this->callController($ctrl2, 'deactivateFuzzyDate');

        $recCtrl = $this->makeController('Bdus\\Controllers\\Record', ['tb' => self::TB]);
        $res     = $this->callController($recCtrl, 'getRecord');

        $this->assertFalse($res['schema']['has_fuzzy_date'] ?? true);
    }

    public function testHasFuzzyDateTrueAfterActivation(): void
    {
        $cfgCtrl = $this->makeController('Bdus\\Controllers\\Config', ['tb' => self::TB]);
        $this->callController($cfgCtrl, 'activateFuzzyDate');

        $ctrl = $this->makeController('Bdus\\Controllers\\Record', ['tb' => self::TB]);
        $res  = $this->callController($ctrl, 'getRecord');

        $this->assertTrue($res['schema']['has_fuzzy_date']);
    }

    // ── deactivateFuzzyDate ───────────────────────────────────────────────────

    public function testDeactivateClearsConfigFlag(): void
    {
        // Activate first
        $ctrl = $this->makeController('Bdus\\Controllers\\Config', ['tb' => self::TB]);
        $this->callController($ctrl, 'activateFuzzyDate');

        // Then deactivate
        $ctrl2 = $this->makeController('Bdus\\Controllers\\Config', ['tb' => self::TB]);
        $res   = $this->callController($ctrl2, 'deactivateFuzzyDate');

        $this->assertSame('success',              $res['status']);
        $this->assertSame('fuzzy_date_deactivated', $res['code']);

        // Config flag should now be false
        $cfgCtrl = $this->makeController('Bdus\\Controllers\\Config', ['tb' => self::TB]);
        $cfg     = $this->callController($cfgCtrl, 'getTableConfig');
        $this->assertFalse((bool)($cfg['table']['fuzzy_date'] ?? true));
    }

    public function testDeactivatePreservesColumns(): void
    {
        // Activate → deactivate → columns must still exist
        $ctrl = $this->makeController('Bdus\\Controllers\\Config', ['tb' => self::TB]);
        $this->callController($ctrl, 'activateFuzzyDate');

        $ctrl2 = $this->makeController('Bdus\\Controllers\\Config', ['tb' => self::TB]);
        $this->callController($ctrl2, 'deactivateFuzzyDate');

        $cols  = static::$db->query('PRAGMA table_info(' . self::TB . ')', [], 'read');
        $names = array_column($cols, 'name');

        $this->assertContains('chrono_from', $names, 'Columns must be preserved on deactivation');
        $this->assertContains('chrono_to',   $names);
    }

    public function testDeactivateRequiresSuperAdmin(): void
    {
        $this->setPrivilege(11);
        $ctrl = $this->makeController('Bdus\\Controllers\\Config', ['tb' => self::TB]);
        $res  = $this->callController($ctrl, 'deactivateFuzzyDate');
        $this->setPrivilege(1);

        $this->assertSame('error',                $res['status']);
        $this->assertSame('not_enough_privilege', $res['code']);
    }

    public function testDeactivateMissingTableReturnsError(): void
    {
        $ctrl = $this->makeController('Bdus\\Controllers\\Config', ['tb' => '']);
        $res  = $this->callController($ctrl, 'deactivateFuzzyDate');

        $this->assertSame('error',         $res['status']);
        $this->assertSame('missing_table', $res['code']);
    }

    // ── chrono values round-trip through save / getRecord ────────────────────

    public function testChronoValuesRoundTrip(): void
    {
        // Activate plugin
        $cfgCtrl = $this->makeController('Bdus\\Controllers\\Config', ['tb' => self::TB]);
        $this->callController($cfgCtrl, 'activateFuzzyDate');

        // Save a record with chrono values
        $saveCtrl = $this->makeController(
            'Bdus\\Controllers\\Record',
            ['tb' => self::TB],
            [
                'id'   => null,
                'core' => [
                    'name'             => 'Chrono test record',
                    'chrono_from'      => -325,
                    'chrono_to'        => -301,
                    'chrono_label'     => 'Late 4th cent. BCE',
                    'chrono_certainty' => 'probable',
                    'chrono_period'    => 'Hellenistic',
                ],
                'plugins' => [],
            ]
        );
        $saved = $this->callController($saveCtrl, 'saveRecord');
        $this->assertSame('success', $saved['status'], 'saveRecord must succeed');

        $newId = $saved['id'];

        // Fetch the record back
        $getCtrl = $this->makeController(
            'Bdus\\Controllers\\Record',
            ['tb' => self::TB, 'id' => $newId]
        );
        $record = $this->callController($getCtrl, 'getRecord');

        $core = $record['core'];
        $this->assertSame(-325,           $core['chrono_from']['val']      ?? null);
        $this->assertSame(-301,           $core['chrono_to']['val']        ?? null);
        $this->assertSame('Late 4th cent. BCE', $core['chrono_label']['val']  ?? null);
        $this->assertSame('probable',     $core['chrono_certainty']['val'] ?? null);
        $this->assertSame('Hellenistic',  $core['chrono_period']['val']    ?? null);
    }

    public function testChronoNullValuesRoundTrip(): void
    {
        // Activate plugin
        $cfgCtrl = $this->makeController('Bdus\\Controllers\\Config', ['tb' => self::TB]);
        $this->callController($cfgCtrl, 'activateFuzzyDate');

        // Save with ante-quem: from=NULL, to=-300
        $saveCtrl = $this->makeController(
            'Bdus\\Controllers\\Record',
            ['tb' => self::TB],
            [
                'id'   => null,
                'core' => [
                    'name'         => 'Ante quem record',
                    'chrono_from'  => null,
                    'chrono_to'    => -300,
                    'chrono_label' => 'Ante quem: 4th cent. BCE',
                ],
                'plugins' => [],
            ]
        );
        $saved = $this->callController($saveCtrl, 'saveRecord');
        $this->assertSame('success', $saved['status']);

        $newId = $saved['id'];

        $getCtrl = $this->makeController(
            'Bdus\\Controllers\\Record',
            ['tb' => self::TB, 'id' => $newId]
        );
        $record = $this->callController($getCtrl, 'getRecord');
        $core   = $record['core'];

        $this->assertArrayHasKey('chrono_from', $core, 'chrono_from key must exist even when NULL');
        $this->assertNull($core['chrono_from']['val'], 'from must be NULL for ante quem');
        $this->assertSame(-300, $core['chrono_to']['val'] ?? null);
    }
}
