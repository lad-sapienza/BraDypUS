<?php

namespace Tests\Integration;

use Tests\Support\BdusTestCase;

/**
 * Integration tests for config_ctrl v5 JSON endpoints.
 *
 * Tests cover the seven new JSON methods added for the v5 migration:
 *   getTableList, getAppProperties, getTableConfig, getFldStructure,
 *   getFldConfig, getGeoFaceConfig, getValidationReport
 *
 * All existing v4 Twig methods are intentionally NOT tested here — they
 * are left untouched by the migration.
 *
 * Fixture tables:  test__items  (regular), test__tags (plugin)
 * Fixture fields on test__items: id, creator, name, description, status
 */
class ConfigCtrlTest extends BdusTestCase
{
    private const TB       = 'test__items';
    private const TB_PLUG  = 'test__tags';
    private const FLD      = 'name';

    // ── Setup / teardown ──────────────────────────────────────────────────

    public static function setUpBeforeClass(): void
    {
        parent::setUpBeforeClass();

        // Populate a geodata/index.json so getGeoFaceConfig has something to read.
        $layers = [
            ['label' => 'OSM', 'type' => 'tiles', 'path' => 'https://tile.osm.org/{z}/{x}/{y}.png', 'wmslayers' => '', 'layertype' => 'base'],
        ];
        file_put_contents(
            PROJ_DIR . 'geodata/index.json',
            json_encode($layers)
        );

        // Drop a dummy local geo file so the local_files list is non-empty.
        file_put_contents(PROJ_DIR . 'geodata/test_layer.geojson', '{}');
    }

    public static function tearDownAfterClass(): void
    {
        @unlink(PROJ_DIR . 'geodata/index.json');
        @unlink(PROJ_DIR . 'geodata/test_layer.geojson');
        parent::tearDownAfterClass();
    }

    // ── getTableList ──────────────────────────────────────────────────────

    public function testGetTableListReturnsSuccess(): void
    {
        $ctrl = $this->makeController('config_ctrl');
        $res  = $this->callController($ctrl, 'getTableList');

        $this->assertSame('success', $res['status']);
        $this->assertIsArray($res['tables']);
    }

    public function testGetTableListContainsFixtureTables(): void
    {
        $ctrl  = $this->makeController('config_ctrl');
        $res   = $this->callController($ctrl, 'getTableList');
        $names = array_column($res['tables'], 'name');

        $this->assertContains(self::TB,      $names);
        $this->assertContains(self::TB_PLUG, $names);
    }

    public function testGetTableListItemsHaveRequiredKeys(): void
    {
        $ctrl = $this->makeController('config_ctrl');
        $res  = $this->callController($ctrl, 'getTableList');

        foreach ($res['tables'] as $table) {
            $this->assertArrayHasKey('name',      $table);
            $this->assertArrayHasKey('label',     $table);
            $this->assertArrayHasKey('is_plugin', $table);
        }
    }

    public function testGetTableListMarksPluginCorrectly(): void
    {
        $ctrl   = $this->makeController('config_ctrl');
        $res    = $this->callController($ctrl, 'getTableList');
        $byName = array_column($res['tables'], null, 'name');

        $this->assertSame('1', $byName[self::TB_PLUG]['is_plugin']);
        // regular table must NOT be flagged as plugin
        $this->assertNotSame('1', $byName[self::TB]['is_plugin'] ?? '0');
    }

    public function testGetTableListRequiresSuperAdmin(): void
    {
        $_SESSION['user']['privilege'] = 11;
        $ctrl = $this->makeController('config_ctrl');
        $res  = $this->callController($ctrl, 'getTableList');
        $_SESSION['user']['privilege'] = 1;

        $this->assertSame('error',                $res['status']);
        $this->assertSame('not_enough_privilege', $res['code']);
    }

    // ── getAppProperties ─────────────────────────────────────────────────

    public function testGetAppPropertiesReturnsSuccess(): void
    {
        $ctrl = $this->makeController('config_ctrl');
        $res  = $this->callController($ctrl, 'getAppProperties');

        $this->assertSame('success', $res['status']);
    }

    public function testGetAppPropertiesHasMainBlock(): void
    {
        $ctrl = $this->makeController('config_ctrl');
        $res  = $this->callController($ctrl, 'getAppProperties');

        $this->assertArrayHasKey('main', $res);
        $this->assertArrayHasKey('name', $res['main']);
        $this->assertSame('bdus_test', $res['main']['name']);
    }

    public function testGetAppPropertiesHasDbEngines(): void
    {
        $ctrl = $this->makeController('config_ctrl');
        $res  = $this->callController($ctrl, 'getAppProperties');

        $this->assertContains('sqlite', $res['db_engines']);
        $this->assertContains('mysql',  $res['db_engines']);
        $this->assertContains('pgsql',  $res['db_engines']);
    }

    public function testGetAppPropertiesHasStatusOptions(): void
    {
        $ctrl = $this->makeController('config_ctrl');
        $res  = $this->callController($ctrl, 'getAppProperties');

        $this->assertContains('on',     $res['status_options']);
        $this->assertContains('frozen', $res['status_options']);
        $this->assertContains('off',    $res['status_options']);
    }

    public function testGetAppPropertiesHasLangs(): void
    {
        $ctrl = $this->makeController('config_ctrl');
        $res  = $this->callController($ctrl, 'getAppProperties');

        $this->assertIsArray($res['langs']);
        $this->assertNotEmpty($res['langs']);
        $this->assertContains('en', $res['langs']);
    }

    public function testGetAppPropertiesRequiresSuperAdmin(): void
    {
        $_SESSION['user']['privilege'] = 11;
        $ctrl = $this->makeController('config_ctrl');
        $res  = $this->callController($ctrl, 'getAppProperties');
        $_SESSION['user']['privilege'] = 1;

        $this->assertSame('error', $res['status']);
        $this->assertSame('not_enough_privilege', $res['code']);
    }

    // ── getTableConfig ───────────────────────────────────────────────────

    public function testGetTableConfigReturnsSuccess(): void
    {
        $ctrl = $this->makeController('config_ctrl', ['tb' => self::TB]);
        $res  = $this->callController($ctrl, 'getTableConfig');

        $this->assertSame('success', $res['status']);
    }

    public function testGetTableConfigReturnsTableData(): void
    {
        $ctrl = $this->makeController('config_ctrl', ['tb' => self::TB]);
        $res  = $this->callController($ctrl, 'getTableConfig');

        $this->assertSame(self::TB,  $res['table']['name']);
        $this->assertSame('Items',   $res['table']['label']);
    }

    public function testGetTableConfigHasRequiredResponseKeys(): void
    {
        $ctrl = $this->makeController('config_ctrl', ['tb' => self::TB]);
        $res  = $this->callController($ctrl, 'getTableConfig');

        foreach (['table', 'field_labels', 'templates', 'available_plugins', 'available_tables'] as $key) {
            $this->assertArrayHasKey($key, $res, "Response missing key: $key");
        }
    }

    public function testGetTableConfigFieldLabelsContainFixtureFields(): void
    {
        $ctrl = $this->makeController('config_ctrl', ['tb' => self::TB]);
        $res  = $this->callController($ctrl, 'getTableConfig');

        $this->assertArrayHasKey('name',   $res['field_labels']);
        $this->assertArrayHasKey('status', $res['field_labels']);
    }

    public function testGetTableConfigAvailablePluginsContainsTagsTable(): void
    {
        $ctrl = $this->makeController('config_ctrl', ['tb' => self::TB]);
        $res  = $this->callController($ctrl, 'getTableConfig');

        // test__tags is a plugin table — must appear in available_plugins
        $this->assertArrayHasKey(self::TB_PLUG, $res['available_plugins']);
    }

    public function testGetTableConfigWithoutTbReturnsDefaults(): void
    {
        $ctrl = $this->makeController('config_ctrl');
        $res  = $this->callController($ctrl, 'getTableConfig');

        // "add new" mode: table data should have default placeholder values
        $this->assertArrayHasKey('preview', $res['table']);
        $this->assertArrayHasKey('link',    $res['table']);
        $this->assertArrayHasKey('plugin',  $res['table']);
    }

    public function testGetTableConfigRequiresSuperAdmin(): void
    {
        $_SESSION['user']['privilege'] = 11;
        $ctrl = $this->makeController('config_ctrl', ['tb' => self::TB]);
        $res  = $this->callController($ctrl, 'getTableConfig');
        $_SESSION['user']['privilege'] = 1;

        $this->assertSame('error', $res['status']);
    }

    // ── getFldStructure ──────────────────────────────────────────────────

    public function testGetFldStructureReturnsSuccess(): void
    {
        $ctrl = $this->makeController('config_ctrl');
        $res  = $this->callController($ctrl, 'getFldStructure');

        $this->assertSame('success', $res['status']);
        $this->assertIsArray($res['structure']);
        $this->assertNotEmpty($res['structure']);
    }

    public function testGetFldStructureContainsAllSchemaProperties(): void
    {
        $ctrl = $this->makeController('config_ctrl');
        $res  = $this->callController($ctrl, 'getFldStructure');
        $keys = array_keys($res['structure']);

        foreach (['name', 'label', 'type', 'db_type', 'check', 'readonly', 'help'] as $prop) {
            $this->assertContains($prop, $keys, "fld_structure missing property: $prop");
        }
    }

    public function testGetFldStructureTypePropertyHasCorrectValues(): void
    {
        $ctrl = $this->makeController('config_ctrl');
        $res  = $this->callController($ctrl, 'getFldStructure');

        $typeValues = $res['structure']['type']['values'];
        foreach (['text', 'date', 'long_text', 'select', 'multi_select', 'boolean'] as $t) {
            $this->assertContains($t, $typeValues);
        }
    }

    public function testGetFldStructureIdFromTbContainsConfiguredTables(): void
    {
        $ctrl = $this->makeController('config_ctrl');
        $res  = $this->callController($ctrl, 'getFldStructure');

        // id_from_tb values should include at least the fixture tables
        $values = $res['structure']['id_from_tb']['values'];
        $this->assertContains(self::TB, $values);
    }

    public function testGetFldStructureRequiresSuperAdmin(): void
    {
        $_SESSION['user']['privilege'] = 11;
        $ctrl = $this->makeController('config_ctrl');
        $res  = $this->callController($ctrl, 'getFldStructure');
        $_SESSION['user']['privilege'] = 1;

        $this->assertSame('error', $res['status']);
    }

    // ── getFldConfig ─────────────────────────────────────────────────────

    public function testGetFldConfigReturnsSuccess(): void
    {
        $ctrl = $this->makeController('config_ctrl', ['tb' => self::TB, 'fld' => self::FLD]);
        $res  = $this->callController($ctrl, 'getFldConfig');

        $this->assertSame('success', $res['status']);
    }

    public function testGetFldConfigReturnsFieldData(): void
    {
        $ctrl = $this->makeController('config_ctrl', ['tb' => self::TB, 'fld' => self::FLD]);
        $res  = $this->callController($ctrl, 'getFldConfig');

        $this->assertSame(self::FLD, $res['field']['name']);
        $this->assertSame('Name',    $res['field']['label']);
        $this->assertSame('text',    $res['field']['type']);
    }

    public function testGetFldConfigReturnsStructure(): void
    {
        $ctrl = $this->makeController('config_ctrl', ['tb' => self::TB, 'fld' => self::FLD]);
        $res  = $this->callController($ctrl, 'getFldConfig');

        $this->assertIsArray($res['structure']);
        $this->assertArrayHasKey('name', $res['structure']);
        $this->assertArrayHasKey('type', $res['structure']);
    }

    public function testGetFldConfigWithNoFldReturnsEmptyField(): void
    {
        $ctrl = $this->makeController('config_ctrl', ['tb' => self::TB]);
        $res  = $this->callController($ctrl, 'getFldConfig');

        $this->assertSame('success', $res['status']);
        $this->assertSame([], $res['field']);
    }

    public function testGetFldConfigWithNoTbNoFldReturnsEmpty(): void
    {
        $ctrl = $this->makeController('config_ctrl');
        $res  = $this->callController($ctrl, 'getFldConfig');

        $this->assertSame('success', $res['status']);
        $this->assertSame([], $res['field']);
    }

    public function testGetFldConfigRequiresSuperAdmin(): void
    {
        $_SESSION['user']['privilege'] = 11;
        $ctrl = $this->makeController('config_ctrl', ['tb' => self::TB, 'fld' => self::FLD]);
        $res  = $this->callController($ctrl, 'getFldConfig');
        $_SESSION['user']['privilege'] = 1;

        $this->assertSame('error', $res['status']);
    }

    // ── getGeoFaceConfig ──────────────────────────────────────────────────

    public function testGetGeoFaceConfigReturnsSuccess(): void
    {
        $ctrl = $this->makeController('config_ctrl');
        $res  = $this->callController($ctrl, 'getGeoFaceConfig');

        $this->assertSame('success', $res['status']);
    }

    public function testGetGeoFaceConfigReturnsLayers(): void
    {
        $ctrl = $this->makeController('config_ctrl');
        $res  = $this->callController($ctrl, 'getGeoFaceConfig');

        $this->assertIsArray($res['layers']);
        $this->assertCount(1, $res['layers']);
        $this->assertSame('OSM',   $res['layers'][0]['label']);
        $this->assertSame('tiles', $res['layers'][0]['type']);
    }

    public function testGetGeoFaceConfigReturnsLocalFiles(): void
    {
        $ctrl = $this->makeController('config_ctrl');
        $res  = $this->callController($ctrl, 'getGeoFaceConfig');

        $this->assertIsArray($res['local_files']);
        $this->assertContains('test_layer.geojson', $res['local_files']);
        // index.json must be excluded from local_files
        $this->assertNotContains('index.json', $res['local_files']);
    }

    public function testGetGeoFaceConfigEmptyWhenNoIndexFile(): void
    {
        // Temporarily hide the index file
        rename(PROJ_DIR . 'geodata/index.json', PROJ_DIR . 'geodata/index.json.bak');

        $ctrl = $this->makeController('config_ctrl');
        $res  = $this->callController($ctrl, 'getGeoFaceConfig');

        rename(PROJ_DIR . 'geodata/index.json.bak', PROJ_DIR . 'geodata/index.json');

        $this->assertSame('success', $res['status']);
        $this->assertSame([], $res['layers']);
    }

    public function testGetGeoFaceConfigRequiresSuperAdmin(): void
    {
        $_SESSION['user']['privilege'] = 11;
        $ctrl = $this->makeController('config_ctrl');
        $res  = $this->callController($ctrl, 'getGeoFaceConfig');
        $_SESSION['user']['privilege'] = 1;

        $this->assertSame('error', $res['status']);
    }

    // ── getValidationReport ───────────────────────────────────────────────

    public function testGetValidationReportReturnsSuccess(): void
    {
        $ctrl = $this->makeController('config_ctrl');
        $res  = $this->callController($ctrl, 'getValidationReport');

        $this->assertSame('success', $res['status']);
        $this->assertIsArray($res['report']);
        $this->assertNotEmpty($res['report']);
    }

    public function testGetValidationReportItemsHaveRequiredKeys(): void
    {
        $ctrl = $this->makeController('config_ctrl');
        $res  = $this->callController($ctrl, 'getValidationReport');

        foreach ($res['report'] as $item) {
            $this->assertArrayHasKey('status', $item);
            $this->assertArrayHasKey('text',   $item);
        }
    }

    public function testGetValidationReportStatusValuesAreValid(): void
    {
        $ctrl    = $this->makeController('config_ctrl');
        $res     = $this->callController($ctrl, 'getValidationReport');
        $allowed = ['success', 'info', 'warning', 'danger', 'head'];

        foreach ($res['report'] as $item) {
            $this->assertContains(
                $item['status'],
                $allowed,
                "Unexpected status value: {$item['status']}"
            );
        }
    }

    public function testGetValidationReportContainsHeadItems(): void
    {
        $ctrl     = $this->makeController('config_ctrl');
        $res      = $this->callController($ctrl, 'getValidationReport');
        $statuses = array_column($res['report'], 'status');

        $this->assertContains('head', $statuses,
            'Report must contain at least one section header (head)');
    }

    public function testGetValidationReportFixItemsHaveCorrectShape(): void
    {
        $ctrl = $this->makeController('config_ctrl');
        $res  = $this->callController($ctrl, 'getValidationReport');

        foreach ($res['report'] as $item) {
            if (!isset($item['fix'])) {
                continue;
            }
            $this->assertIsArray($item['fix']);
            $this->assertContains($item['fix'][0], ['create', 'delete'],
                'fix[0] must be "create" or "delete"');
            $this->assertArrayHasKey('suggest', $item,
                'Items with a fix must also have a suggest text');
        }
    }

    public function testGetValidationReportRequiresSuperAdmin(): void
    {
        $_SESSION['user']['privilege'] = 11;
        $ctrl = $this->makeController('config_ctrl');
        $res  = $this->callController($ctrl, 'getValidationReport');
        $_SESSION['user']['privilege'] = 1;

        $this->assertSame('error', $res['status']);
        $this->assertSame('not_enough_privilege', $res['code']);
    }
}
