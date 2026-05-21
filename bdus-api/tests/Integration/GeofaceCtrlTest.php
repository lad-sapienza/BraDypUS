<?php

namespace Tests\Integration;

use Tests\Support\BdusTestCase;

/**
 * Integration tests for geoface_ctrl v5 methods.
 *
 * Uses the shared in-memory SQLite DB from BdusTestCase which already
 * creates geodata. We seed one geometry in setUpBeforeClass so
 * that getGeoJson has data to return.
 */
class GeofaceCtrlTest extends BdusTestCase
{
    private const TB = 'items';

    private static int $geoId;

    // ── Additional seed data ──────────────────────────────────────────────

    public static function setUpBeforeClass(): void
    {
        parent::setUpBeforeClass();

        // Seed one geometry linked to item 1 (POINT in WKT)
        static::$db->execInTransaction(
            "INSERT INTO bdus_geodata (table_link, id_link, geometry) VALUES ('items', 1, 'POINT(12.5 41.9)')"
        );

        // Store the inserted id for later assertions
        $row = static::$db->query(
            "SELECT id FROM bdus_geodata WHERE table_link = 'items' AND id_link = 1 ORDER BY id DESC LIMIT 1"
        );
        static::$geoId = (int)($row[0]['id'] ?? 0);
    }

    // ── getGeoJson ────────────────────────────────────────────────────────

    public function testGetGeoJsonReturnsSuccess(): void
    {
        $ctrl = $this->makeController('geoface_ctrl', ['tb' => self::TB]);
        $res  = $this->callController($ctrl, 'getGeoJson');

        $this->assertSame('success', $res['status']);
    }

    public function testGetGeoJsonHasFeatureCollection(): void
    {
        $ctrl = $this->makeController('geoface_ctrl', ['tb' => self::TB]);
        $res  = $this->callController($ctrl, 'getGeoJson');

        $this->assertArrayHasKey('geojson', $res);
        $this->assertSame('FeatureCollection', $res['geojson']['type']);
        $this->assertIsArray($res['geojson']['features']);
        $this->assertGreaterThanOrEqual(1, count($res['geojson']['features']));
    }

    public function testGetGeoJsonHasMeta(): void
    {
        $ctrl = $this->makeController('geoface_ctrl', ['tb' => self::TB]);
        $res  = $this->callController($ctrl, 'getGeoJson');

        $this->assertArrayHasKey('meta', $res);
        $meta = $res['meta'];
        $this->assertArrayHasKey('tb_id',          $meta);
        $this->assertArrayHasKey('tb_label',        $meta);
        $this->assertArrayHasKey('canUserEdit',     $meta);
        $this->assertArrayHasKey('layers',          $meta);
        $this->assertArrayHasKey('preview_fields',  $meta);
        $this->assertArrayHasKey('id_field',        $meta);
        $this->assertSame(self::TB, $meta['tb_id']);
    }

    public function testGetGeoJsonFeatureHasGeoId(): void
    {
        $ctrl = $this->makeController('geoface_ctrl', ['tb' => self::TB]);
        $res  = $this->callController($ctrl, 'getGeoJson');

        $feature = $res['geojson']['features'][0];
        $this->assertArrayHasKey('properties', $feature);
        $this->assertArrayHasKey('geo_id', $feature['properties']);
    }

    public function testGetGeoJsonShortSqlFilter(): void
    {
        // id|=|1 should return exactly the geometry for item 1
        $ctrl = $this->makeController('geoface_ctrl', [
            'tb'          => self::TB,
            'search_type' => 'shortSql',
            'where'       => 'id|=|1',
        ]);
        $res = $this->callController($ctrl, 'getGeoJson');

        $this->assertSame('success', $res['status']);
        $this->assertCount(1, $res['geojson']['features']);
    }

    public function testGetGeoJsonSqlExpertFilter(): void
    {
        $ctrl = $this->makeController('geoface_ctrl', [
            'tb'          => self::TB,
            'search_type' => 'sqlExpert',
            'querytext'   => self::TB . '.id = 1',
        ]);
        $res = $this->callController($ctrl, 'getGeoJson');

        $this->assertSame('success', $res['status']);
        $this->assertCount(1, $res['geojson']['features']);
    }

    // ── saveNew ───────────────────────────────────────────────────────────

    public function testSaveNewSuccess(): void
    {
        $geomJson = json_encode(['type' => 'Point', 'coordinates' => [13.0, 42.0]]);

        $ctrl = $this->makeController(
            'geoface_ctrl',
            [],
            ['tb' => self::TB, 'id' => 2, 'geometry' => $geomJson]
        );
        $res = $this->callController($ctrl, 'saveNew');

        $this->assertSame('success', $res['status']);
        $this->assertSame('ok_insert_geodata', $res['code']);
        $this->assertArrayHasKey('geo_id', $res);
        $this->assertNotNull($res['geo_id']);
    }

    public function testSaveNewMissingParamsReturnsError(): void
    {
        $ctrl = $this->makeController('geoface_ctrl', [], ['tb' => self::TB]);
        $res  = $this->callController($ctrl, 'saveNew');

        $this->assertSame('error', $res['status']);
        $this->assertSame('parameter_missing', $res['code']);
    }

    // ── updateGeometry ────────────────────────────────────────────────────

    public function testUpdateGeometrySuccess(): void
    {
        $newGeom = json_encode(['type' => 'Point', 'coordinates' => [14.0, 43.0]]);

        $ctrl = $this->makeController(
            'geoface_ctrl',
            [],
            ['geodata' => [['id' => static::$geoId, 'geometry' => $newGeom]]]
        );
        $res = $this->callController($ctrl, 'updateGeometry');

        $this->assertSame('success', $res['status']);
        $this->assertSame('ok_update_geometry', $res['code']);

        // Verify the geometry was actually updated in the DB
        $row = static::$db->query(
            'SELECT geometry FROM bdus_geodata WHERE id = ?',
            [static::$geoId]
        );
        $this->assertNotEmpty($row);
        $this->assertStringContainsString('POINT', strtoupper($row[0]['geometry']));
    }

    public function testUpdateGeometryMissingParamsReturnsError(): void
    {
        $ctrl = $this->makeController('geoface_ctrl', [], []);
        $res  = $this->callController($ctrl, 'updateGeometry');

        $this->assertSame('error', $res['status']);
        $this->assertSame('parameter_missing', $res['code']);
    }

    // ── eraseGeometry ─────────────────────────────────────────────────────

    public function testEraseGeometrySuccess(): void
    {
        // Insert a temporary geometry to delete
        static::$db->execInTransaction(
            "INSERT INTO bdus_geodata (table_link, id_link, geometry) VALUES ('items', 3, 'POINT(10.0 40.0)')"
        );
        $row = static::$db->query(
            "SELECT id FROM bdus_geodata WHERE table_link = 'items' AND id_link = 3 ORDER BY id DESC LIMIT 1"
        );
        $tmpId = (int)($row[0]['id'] ?? 0);
        $this->assertGreaterThan(0, $tmpId);

        $ctrl = $this->makeController('geoface_ctrl', [], ['ids' => [$tmpId]]);
        $res  = $this->callController($ctrl, 'eraseGeometry');

        $this->assertSame('success', $res['status']);
        $this->assertSame('ok_delete_geodata', $res['code']);

        // Verify it is gone
        $check = static::$db->query('SELECT id FROM bdus_geodata WHERE id = ?', [$tmpId]);
        $this->assertEmpty($check);
    }

    public function testEraseGeometryMissingParamsReturnsError(): void
    {
        $ctrl = $this->makeController('geoface_ctrl', [], []);
        $res  = $this->callController($ctrl, 'eraseGeometry');

        $this->assertSame('error', $res['status']);
        $this->assertSame('parameter_missing', $res['code']);
    }

    public function testEraseGeometryRequiresEditPrivilege(): void
    {
        $this->setPrivilege(99);   // low-privilege user — canUser('edit') will fail

        $ctrl = $this->makeController('geoface_ctrl', [], ['ids' => [static::$geoId]]);
        $res  = $this->callController($ctrl, 'eraseGeometry');

        $this->assertSame('error', $res['status']);
        $this->assertSame('not_enough_privilege', $res['code']);

        $this->setPrivilege(1);    // restore super-admin
    }
}
