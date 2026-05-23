<?php

namespace Tests\Integration;

use PHPUnit\Framework\TestCase;
use DB\DB;
use DB\System\Manage;
use DB\System\Migrations\M014_GeofaceConfigToDb;
use Config\GeofaceConfig;
use Monolog\Logger;
use Monolog\Handler\NullHandler;

/**
 * Tests for M014_GeofaceConfigToDb and the GeofaceConfig helper.
 *
 * Uses explicit $projDir parameters to avoid the PROJ_DIR constant
 * clash with M011MigrationTest (PHP constants cannot be redefined).
 */
class M014MigrationTest extends TestCase
{
    private static DB     $db;
    private static Manage $manage;
    private static string $tmpDir;

    private static array $fixtureLayers = [
        ['type' => 'tiles',  'label' => 'OSM',  'path' => 'https://{s}.tile.openstreetmap.org/{z}/{x}/{y}.png', 'layertype' => 'base'],
        ['type' => 'wms',    'label' => 'WMS1', 'path' => 'https://example.com/wms', 'wmslayers' => 'layer1',   'layertype' => 'overlay'],
    ];

    public static function setUpBeforeClass(): void
    {
        $log = new Logger('test');
        $log->pushHandler(new NullHandler());

        static::$db     = new DB('test', ['db_engine' => 'sqlite', 'db_path' => ':memory:']);
        static::$db->setLog($log);
        static::$manage = new Manage(static::$db);

        // Temp project directory with geodata/index.json fixture.
        static::$tmpDir = sys_get_temp_dir() . '/bdus_m014_test_' . uniqid();
        mkdir(static::$tmpDir . '/geodata', 0755, true);

        file_put_contents(
            static::$tmpDir . '/geodata/index.json',
            json_encode(static::$fixtureLayers, JSON_PRETTY_PRINT)
        );
    }

    public static function tearDownAfterClass(): void
    {
        @unlink(static::$tmpDir . '/geodata/index.json');
        @rmdir(static::$tmpDir . '/geodata');
        @rmdir(static::$tmpDir);
    }

    // ── Migration ─────────────────────────────────────────────────────────────

    public function testMigrationCreatesTable(): void
    {
        M014_GeofaceConfigToDb::run(static::$manage, static::$tmpDir);

        $rows = static::$db->query(
            "SELECT name FROM sqlite_master WHERE type='table' AND name='bdus_cfg_geoface'",
            [], 'read'
        );
        $this->assertNotEmpty($rows, 'bdus_cfg_geoface table was not created');
    }

    public function testMigrationImportsLayersFromFile(): void
    {
        $row = static::$db->query(
            'SELECT layers FROM bdus_cfg_geoface WHERE id = 1',
            [], 'read'
        );
        $this->assertNotEmpty($row, 'No row inserted by migration');

        $layers = json_decode($row[0]['layers'], true);
        $this->assertIsArray($layers);
        $this->assertCount(count(static::$fixtureLayers), $layers);
        $this->assertSame(static::$fixtureLayers[0]['label'], $layers[0]['label']);
        $this->assertSame(static::$fixtureLayers[1]['type'],  $layers[1]['type']);
    }

    public function testMigrationIsIdempotent(): void
    {
        // Running a second time must not throw or duplicate the row.
        M014_GeofaceConfigToDb::run(static::$manage, static::$tmpDir);

        $count = static::$db->query(
            'SELECT COUNT(*) AS c FROM bdus_cfg_geoface',
            [], 'read'
        );
        $this->assertSame(1, (int)($count[0]['c'] ?? 0));
    }

    public function testMigrationWithoutIndexFileInsertsEmptyArray(): void
    {
        // Create a second fresh DB — no file in the given dir.
        $db2     = new DB('test2', ['db_engine' => 'sqlite', 'db_path' => ':memory:']);
        $manage2 = new Manage($db2);

        $emptyDir = sys_get_temp_dir() . '/bdus_m014_empty_' . uniqid();
        mkdir($emptyDir . '/geodata', 0755, true);

        M014_GeofaceConfigToDb::run($manage2, $emptyDir);

        $row = $db2->query('SELECT layers FROM bdus_cfg_geoface WHERE id = 1', [], 'read');
        $this->assertNotEmpty($row);
        $this->assertSame('[]', $row[0]['layers']);

        @rmdir($emptyDir . '/geodata');
        @rmdir($emptyDir);
    }

    // ── GeofaceConfig helper ──────────────────────────────────────────────────

    public function testIsAvailableReturnsTrueAfterMigration(): void
    {
        $this->assertTrue(GeofaceConfig::isAvailable(static::$db));
    }

    public function testGetLayersFromDb(): void
    {
        $layers = GeofaceConfig::getLayers(static::$db);
        $this->assertIsArray($layers);
        $this->assertCount(count(static::$fixtureLayers), $layers);
        $this->assertSame(static::$fixtureLayers[0]['label'], $layers[0]['label']);
    }

    public function testSaveLayersToDb(): void
    {
        $newLayers = [
            ['type' => 'tiles', 'label' => 'Updated', 'path' => 'https://new.tiles/{z}/{x}/{y}.png', 'layertype' => 'base'],
        ];

        $ok = GeofaceConfig::saveLayers(static::$db, $newLayers);
        $this->assertTrue($ok);

        $read = GeofaceConfig::getLayers(static::$db);
        $this->assertCount(1, $read);
        $this->assertSame('Updated', $read[0]['label']);
    }

    public function testGetLayersFallsBackToFileWhenDbUnavailable(): void
    {
        // Restore fixture file (previous save test may have changed DB row, not file).
        file_put_contents(
            static::$tmpDir . '/geodata/index.json',
            json_encode(static::$fixtureLayers)
        );

        // null DB → falls back to file via explicit projDir.
        $layers = GeofaceConfig::getLayers(null, static::$tmpDir);
        $this->assertIsArray($layers);
        $this->assertCount(count(static::$fixtureLayers), $layers);
    }

    public function testIsAvailableReturnsFalseOnEmptyDb(): void
    {
        $freshDb = new DB('fresh', ['db_engine' => 'sqlite', 'db_path' => ':memory:']);
        $this->assertFalse(GeofaceConfig::isAvailable($freshDb));
    }
}
