<?php

namespace Tests\Integration;

use Tests\Support\BdusTestCase;

/**
 * Integration tests for GeoFace field-based theming (issue tracker #53):
 * Geoface::getGeoJson() colorBy resolution + Geoface::saveColorField().
 *
 * Kept separate from GeofaceCtrlTest.php (own geodata fixtures, own table
 * config mutations) since BdusTestCase gives each test class a fresh
 * in-memory DB, avoiding any interference between the two.
 */
class GeofaceColorFieldTest extends BdusTestCase
{
    private const TB = 'items';

    public static function setUpBeforeClass(): void
    {
        parent::setUpBeforeClass();

        // Geometries for items 1, 2, 4, 5 — status: active, inactive, pending, active.
        static::$db->execInTransaction(
            "INSERT INTO bdus_geodata (table_link, id_link, geometry) VALUES
                ('items', 1, 'POINT(12.0 41.0)'),
                ('items', 2, 'POINT(12.1 41.1)'),
                ('items', 4, 'POINT(12.2 41.2)'),
                ('items', 5, 'POINT(12.3 41.3)')"
        );

        // Numeric values for the numeric-legend path (score is check:int, stored as TEXT).
        static::$db->execInTransaction("UPDATE items SET score = '10' WHERE id = 1");
        static::$db->execInTransaction("UPDATE items SET score = '20' WHERE id = 2");
        static::$db->execInTransaction("UPDATE items SET score = '90' WHERE id = 4");
        static::$db->execInTransaction("UPDATE items SET score = '50' WHERE id = 5");
    }

    /** Reset the persisted color field before every test — tests share static::$cfg. */
    protected function setUp(): void
    {
        self::resetTbExtra();
    }

    /**
     * BdusTestCase's Config is file-backed (tests/fixtures/cfg/*.json, not the
     * in-memory DB) — setTable() really rewrites tables.json on disk. Reset
     * once more after the last test in this class so the shared fixture is
     * left clean, same convention as RadiocarbonCtrlTest (see
     * project_test_conventions memory, "File-based test Config PERSISTS
     * writes to disk").
     */
    public static function tearDownAfterClass(): void
    {
        self::resetTbExtra();
        parent::tearDownAfterClass();
    }

    private static function resetTbExtra(): void
    {
        $tbData = static::$cfg->get('tables.' . self::TB);
        unset($tbData['geoface_color_field'], $tbData['some_other_flag']);
        static::$cfg->setTable($tbData);
    }

    // ── getGeoJson: colorBy resolution ──────────────────────────────────────

    public function testMetaColorByFieldIsNullByDefault(): void
    {
        $ctrl = $this->makeController('Bdus\\Controllers\\Geoface', ['tb' => self::TB]);
        $res  = $this->callController($ctrl, 'getGeoJson');

        $this->assertSame('success', $res['status']);
        $this->assertArrayHasKey('colorByField', $res['meta']);
        $this->assertNull($res['meta']['colorByField']);

        $feature = $res['geojson']['features'][0];
        $this->assertArrayNotHasKey('__geoface_color', $feature['properties']);
    }

    public function testExplicitColorByAddsThemeValueToProperties(): void
    {
        $ctrl = $this->makeController('Bdus\\Controllers\\Geoface', ['tb' => self::TB, 'colorBy' => 'status']);
        $res  = $this->callController($ctrl, 'getGeoJson');

        $this->assertSame('status', $res['meta']['colorByField']);
        foreach ($res['geojson']['features'] as $feature) {
            $this->assertArrayHasKey('__geoface_color', $feature['properties']);
        }
    }

    public function testExplicitColorByWorksWithNumericField(): void
    {
        $ctrl = $this->makeController('Bdus\\Controllers\\Geoface', ['tb' => self::TB, 'colorBy' => 'score']);
        $res  = $this->callController($ctrl, 'getGeoJson');

        $this->assertSame('score', $res['meta']['colorByField']);
        $values = array_column(array_column($res['geojson']['features'], 'properties'), '__geoface_color');
        sort($values);
        $this->assertSame(['10', '20', '50', '90'], $values);
    }

    public function testUnknownColorByFieldIsIgnoredNotSqlInjected(): void
    {
        $ctrl = $this->makeController('Bdus\\Controllers\\Geoface', [
            'tb'      => self::TB,
            'colorBy' => 'not_a_real_field; DROP TABLE items; --',
        ]);
        $res = $this->callController($ctrl, 'getGeoJson');

        $this->assertSame('success', $res['status']);
        $this->assertNull($res['meta']['colorByField']);
        $this->assertArrayNotHasKey('__geoface_color', $res['geojson']['features'][0]['properties']);

        // The table must still exist — confirms the field name never reached raw SQL.
        $check = static::$db->query('SELECT COUNT(*) as c FROM items', []);
        $this->assertGreaterThan(0, (int) $check[0]['c']);
    }

    public function testEmptyColorByOverridesPersistedDefault(): void
    {
        $save = $this->makeController('Bdus\\Controllers\\Geoface', [], ['tb' => self::TB, 'field' => 'status']);
        $this->callController($save, 'saveColorField');

        // Explicit empty colorBy must disable theming even though a default is set.
        $ctrl = $this->makeController('Bdus\\Controllers\\Geoface', ['tb' => self::TB, 'colorBy' => '']);
        $res  = $this->callController($ctrl, 'getGeoJson');

        $this->assertNull($res['meta']['colorByField']);
    }

    public function testFallsBackToPersistedDefaultWhenParamAbsent(): void
    {
        $save = $this->makeController('Bdus\\Controllers\\Geoface', [], ['tb' => self::TB, 'field' => 'category']);
        $this->callController($save, 'saveColorField');

        // No colorBy param at all — must pick up the persisted default.
        $ctrl = $this->makeController('Bdus\\Controllers\\Geoface', ['tb' => self::TB]);
        $res  = $this->callController($ctrl, 'getGeoJson');

        $this->assertSame('category', $res['meta']['colorByField']);
    }

    // ── saveColorField ───────────────────────────────────────────────────────

    public function testSaveColorFieldPersists(): void
    {
        $ctrl = $this->makeController('Bdus\\Controllers\\Geoface', [], ['tb' => self::TB, 'field' => 'score']);
        $res  = $this->callController($ctrl, 'saveColorField');
        $this->assertSame('success', $res['status']);

        $check    = $this->makeController('Bdus\\Controllers\\Geoface', ['tb' => self::TB]);
        $checkRes = $this->callController($check, 'getGeoJson');
        $this->assertSame('score', $checkRes['meta']['colorByField']);
    }

    public function testSaveColorFieldClearsWithEmptyValue(): void
    {
        $save = $this->makeController('Bdus\\Controllers\\Geoface', [], ['tb' => self::TB, 'field' => 'status']);
        $this->callController($save, 'saveColorField');

        $clear = $this->makeController('Bdus\\Controllers\\Geoface', [], ['tb' => self::TB, 'field' => '']);
        $res   = $this->callController($clear, 'saveColorField');
        $this->assertSame('success', $res['status']);

        $check    = $this->makeController('Bdus\\Controllers\\Geoface', ['tb' => self::TB]);
        $checkRes = $this->callController($check, 'getGeoJson');
        $this->assertNull($checkRes['meta']['colorByField']);
    }

    public function testSaveColorFieldRejectsUnknownField(): void
    {
        $ctrl = $this->makeController('Bdus\\Controllers\\Geoface', [], ['tb' => self::TB, 'field' => 'does_not_exist']);
        $res  = $this->callController($ctrl, 'saveColorField');

        $this->assertSame('error', $res['status']);
        $this->assertSame('unknown_field', $res['code']);
    }

    public function testSaveColorFieldRejectsUnknownTable(): void
    {
        $ctrl = $this->makeController('Bdus\\Controllers\\Geoface', [], ['tb' => 'no_such_table', 'field' => 'status']);
        $res  = $this->callController($ctrl, 'saveColorField');

        $this->assertSame('error', $res['status']);
        $this->assertSame('unknown_table', $res['code']);
    }

    public function testSaveColorFieldRequiresEditPrivilege(): void
    {
        $this->setPrivilege(99);

        $ctrl = $this->makeController('Bdus\\Controllers\\Geoface', [], ['tb' => self::TB, 'field' => 'status']);
        $res  = $this->callController($ctrl, 'saveColorField');

        $this->assertSame('error', $res['status']);
        $this->assertSame('not_enough_privilege', $res['code']);

        $this->setPrivilege(1);
    }

    public function testSaveColorFieldPreservesOtherExtraKeys(): void
    {
        // Simulate a pre-existing unrelated extra flag on this table (same
        // mechanism real flags like fuzzy_date/geodata use).
        $tbData = static::$cfg->get('tables.' . self::TB);
        $tbData['some_other_flag'] = '1';
        static::$cfg->setTable($tbData);

        $ctrl = $this->makeController('Bdus\\Controllers\\Geoface', [], ['tb' => self::TB, 'field' => 'status']);
        $res  = $this->callController($ctrl, 'saveColorField');
        $this->assertSame('success', $res['status']);

        $this->assertSame('1', static::$cfg->get('tables.' . self::TB . '.some_other_flag'));
        $this->assertSame('status', static::$cfg->get('tables.' . self::TB . '.geoface_color_field'));
    }
}
