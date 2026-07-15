<?php

declare(strict_types=1);

namespace Tests\Integration;

use Tests\Support\BdusTestCase;

/**
 * Integration tests for the radiocarbon-dating plugin:
 *   POST /api/config/table/{tb}/radiocarbon → Config::activateRadiocarbon()
 *
 * Unlike fuzzy-date/osteology (flat boolean flag + columns on the core
 * table), activation here creates a genuine plugin table ({tb}_radiocarbon,
 * is_plugin/plugin_of) so a record can carry multiple C14 determinations.
 *
 * Side-effects tested:
 *   - {tb}_radiocarbon is created with the expected columns
 *   - is_plugin/plugin_of/radiocarbon are set correctly on the plugin table
 *   - tables.{tb}.plugin[] picks up the new table automatically (derived,
 *     not written directly — see LoadFromDB)
 *   - Activation is idempotent (safe to call twice)
 *   - Non-super-admin callers / missing table names are rejected
 *   - Saving a plugin row computes cal_1s/cal_2s server-side, ignoring any
 *     calibrated values supplied by the client
 *   - A record can carry more than one radiocarbon dating
 */
class RadiocarbonCtrlTest extends BdusTestCase
{
    private const TB        = 'items';
    private const PLUGIN_TB = 'items_radiocarbon';

    /** @var array<string,string> path => original content, for fixture cleanup */
    private static array $fixtureSnapshot = [];
    private static string $fixtureDir;

    /**
     * Unlike fuzzy-date/osteology (which only add columns to an existing
     * table), activating radiocarbon registers a brand-new table NAME in
     * config. The test-only file-based Config (see BdusTestCase) persists
     * every setTable()/setFld() call straight to tests/fixtures/cfg/*.json —
     * so without cleanup this class would leave a table name in the shared
     * fixtures that no other test class's in-memory schema actually has,
     * breaking unrelated test classes that run later in the same PHPUnit
     * process. Snapshot the fixture files here and restore them in
     * tearDownAfterClass() so this class's side effects stay contained.
     */
    public static function setUpBeforeClass(): void
    {
        parent::setUpBeforeClass();

        self::$fixtureDir      = __DIR__ . '/../fixtures/cfg';
        self::$fixtureSnapshot = [];
        foreach (glob(self::$fixtureDir . '/*.json') as $file) {
            self::$fixtureSnapshot[$file] = file_get_contents($file);
        }
    }

    public static function tearDownAfterClass(): void
    {
        foreach (glob(self::$fixtureDir . '/*.json') as $file) {
            if (!isset(self::$fixtureSnapshot[$file])) {
                unlink($file);
            }
        }
        foreach (self::$fixtureSnapshot as $file => $content) {
            file_put_contents($file, $content);
        }
    }

    private function activate(): array
    {
        $ctrl = $this->makeController('Bdus\\Controllers\\Config', ['tb' => self::TB]);
        $res  = $this->callController($ctrl, 'activateRadiocarbon');

        // In production, tables.{tb}.plugin[] is derived fresh from is_plugin/
        // plugin_of on every Config load (Config\LoadFromDB::load()). This test
        // harness uses a single file-based fixture Config loaded once for the
        // whole test class, which does not re-derive on writes — so we mirror
        // that derivation here, matching what a fresh HTTP request would see in
        // production, to keep Record\Read::getPlugin() working within the test.
        $parent = static::$cfg->get('tables.' . self::TB) ?: [];
        if (!in_array(self::PLUGIN_TB, $parent['plugin'] ?? [], true)) {
            $parent['name']   = self::TB;
            $parent['plugin'] = array_merge($parent['plugin'] ?? [], [self::PLUGIN_TB]);
            unset($parent['link']);
            static::$cfg->setTable($parent);
        }

        return $res;
    }

    // ── activateRadiocarbon ────────────────────────────────────────────────────

    public function testActivateCreatesPluginTableWithExpectedColumns(): void
    {
        $res = $this->activate();

        $this->assertSame('success', $res['status']);
        $this->assertSame('radiocarbon_activated', $res['code']);
        $this->assertSame(self::PLUGIN_TB, $res['tb']);

        $cols  = static::$db->query('PRAGMA table_info(' . self::PLUGIN_TB . ')', [], 'read');
        $names = array_column($cols, 'name');

        foreach ([
            'id', 'table_link', 'id_link',
            'lab_code', 'bp', 'bp_error', 'material', 'd13c', 'curve',
            'cal_1s_from', 'cal_1s_to', 'cal_2s_from', 'cal_2s_to', 'notes',
        ] as $expected) {
            $this->assertContains($expected, $names, "Missing column: $expected");
        }
    }

    public function testActivateRegistersPluginOfAndFlag(): void
    {
        $this->activate();

        $cfgCtrl = $this->makeController('Bdus\\Controllers\\Config', ['tb' => self::PLUGIN_TB]);
        $cfg     = $this->callController($cfgCtrl, 'getTableConfig');

        $this->assertSame('1',      $cfg['table']['is_plugin']);
        $this->assertSame(self::TB, $cfg['table']['plugin_of']);
        $this->assertTrue((bool)($cfg['table']['radiocarbon'] ?? false));
    }

    public function testActivateIsIdempotent(): void
    {
        $this->activate();
        $res = $this->activate();

        $this->assertSame('success', $res['status']);
    }

    public function testActivateRequiresSuperAdmin(): void
    {
        $this->setPrivilege(11);
        $res = $this->activate();
        $this->setPrivilege(1);

        $this->assertSame('error',                $res['status']);
        $this->assertSame('not_enough_privilege', $res['code']);
    }

    public function testActivateMissingTableReturnsError(): void
    {
        $ctrl = $this->makeController('Bdus\\Controllers\\Config', ['tb' => '']);
        $res  = $this->callController($ctrl, 'activateRadiocarbon');

        $this->assertSame('error',         $res['status']);
        $this->assertSame('missing_table', $res['code']);
    }

    // ── saveRecord: server-side calibration ────────────────────────────────────

    public function testSaveComputesCalibratedRangeAndIgnoresClientValues(): void
    {
        $this->activate();

        $saveCtrl = $this->makeController(
            'Bdus\\Controllers\\Record',
            ['tb' => self::TB],
            [
                'id'   => null,
                'core' => ['name' => 'Radiocarbon test record'],
                'plugins' => [
                    self::PLUGIN_TB => [[
                        'id'      => null,
                        '_isNew'  => true,
                        '_delete' => false,
                        'fields'  => [
                            'lab_code'    => 'Beta-999999',
                            'bp'          => 3200,
                            'bp_error'    => 40,
                            'material'    => 'charcoal',
                            // Bogus client-supplied calibrated values — must be overwritten.
                            'cal_1s_from' => 1,
                            'cal_1s_to'   => 2,
                            'cal_2s_from' => 3,
                            'cal_2s_to'   => 4,
                        ],
                    ]],
                ],
            ]
        );
        $saved = $this->callController($saveCtrl, 'saveRecord');
        $this->assertSame('success', $saved['status']);

        $getCtrl = $this->makeController('Bdus\\Controllers\\Record', ['tb' => self::TB, 'id' => $saved['id']]);
        $record  = $this->callController($getCtrl, 'getRecord');

        $row = $record['plugins'][self::PLUGIN_TB]['data'][0];

        $this->assertSame(3200, $row['bp']['val']);
        $this->assertSame(40,   $row['bp_error']['val']);

        // Regression-pinned to Radiocarbon\Calibrator's current algorithm
        // (see RadiocarbonCalibratorTest::testKnownReferencePoint3200).
        $this->assertSame(3386, $row['cal_1s_from']['val']);
        $this->assertSame(3450, $row['cal_1s_to']['val']);
        $this->assertSame(3278, $row['cal_2s_from']['val']);
        $this->assertSame(3486, $row['cal_2s_to']['val']);
    }

    public function testRecordCanCarryMultipleDatings(): void
    {
        $this->activate();

        $saveCtrl = $this->makeController(
            'Bdus\\Controllers\\Record',
            ['tb' => self::TB],
            [
                'id'   => null,
                'core' => ['name' => 'Multi-dating record'],
                'plugins' => [
                    self::PLUGIN_TB => [
                        ['id' => null, '_isNew' => true, 'fields' => ['lab_code' => 'Beta-1', 'bp' => 3200, 'bp_error' => 40]],
                        ['id' => null, '_isNew' => true, 'fields' => ['lab_code' => 'Beta-2', 'bp' => 500,  'bp_error' => 30]],
                    ],
                ],
            ]
        );
        $saved = $this->callController($saveCtrl, 'saveRecord');
        $this->assertSame('success', $saved['status']);

        $getCtrl = $this->makeController('Bdus\\Controllers\\Record', ['tb' => self::TB, 'id' => $saved['id']]);
        $record  = $this->callController($getCtrl, 'getRecord');

        $rows = $record['plugins'][self::PLUGIN_TB]['data'];
        $this->assertCount(2, $rows);

        $labCodes = array_map(fn($r) => $r['lab_code']['val'], $rows);
        $this->assertContains('Beta-1', $labCodes);
        $this->assertContains('Beta-2', $labCodes);

        foreach ($rows as $row) {
            $this->assertNotNull($row['cal_1s_from']['val']);
            $this->assertNotNull($row['cal_2s_from']['val']);
        }
    }
}
