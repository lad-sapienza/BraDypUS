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
 * Fixture tables:  items  (regular), tags (plugin)
 * Fixture fields on items: id, creator, name, description, status
 */
class ConfigCtrlTest extends BdusTestCase
{
    private const TB       = 'items';
    private const TB_PLUG  = 'tags';
    private const FLD      = 'name';

    // ── Schema extension ──────────────────────────────────────────────────────

    protected static function createSchema(): void
    {
        parent::createSchema();
        // Add a column unknown to the model so SystemTables::latestStructure()
        // emits at least one ['delete', ...] fix item — needed by the shape test.
        static::$db->exec('ALTER TABLE bdus_log ADD COLUMN rogue_col TEXT');
    }

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
        $ctrl = $this->makeController('Bdus\\Controllers\\Config');
        $res  = $this->callController($ctrl, 'getTableList');

        $this->assertSame('success', $res['status']);
        $this->assertIsArray($res['tables']);
    }

    public function testGetTableListContainsFixtureTables(): void
    {
        $ctrl  = $this->makeController('Bdus\\Controllers\\Config');
        $res   = $this->callController($ctrl, 'getTableList');
        $names = array_column($res['tables'], 'name');

        $this->assertContains(self::TB,      $names);
        $this->assertContains(self::TB_PLUG, $names);
    }

    public function testGetTableListItemsHaveRequiredKeys(): void
    {
        $ctrl = $this->makeController('Bdus\\Controllers\\Config');
        $res  = $this->callController($ctrl, 'getTableList');

        foreach ($res['tables'] as $table) {
            $this->assertArrayHasKey('name',      $table);
            $this->assertArrayHasKey('label',     $table);
            $this->assertArrayHasKey('is_plugin', $table);
        }
    }

    public function testGetTableListMarksPluginCorrectly(): void
    {
        $ctrl   = $this->makeController('Bdus\\Controllers\\Config');
        $res    = $this->callController($ctrl, 'getTableList');
        $byName = array_column($res['tables'], null, 'name');

        $this->assertSame('1', $byName[self::TB_PLUG]['is_plugin']);
        // regular table must NOT be flagged as plugin
        $this->assertNotSame('1', $byName[self::TB]['is_plugin'] ?? '0');
    }

    public function testGetTableListRequiresSuperAdmin(): void
    {
        $this->setPrivilege(11);
        $ctrl = $this->makeController('Bdus\\Controllers\\Config');
        $res  = $this->callController($ctrl, 'getTableList');
        $this->setPrivilege(1);

        $this->assertSame('error',                $res['status']);
        $this->assertSame('not_enough_privilege', $res['code']);
    }

    // ── getAppProperties ─────────────────────────────────────────────────

    public function testGetAppPropertiesReturnsSuccess(): void
    {
        $ctrl = $this->makeController('Bdus\\Controllers\\Config');
        $res  = $this->callController($ctrl, 'getAppProperties');

        $this->assertSame('success', $res['status']);
    }

    public function testGetAppPropertiesHasMainBlock(): void
    {
        $ctrl = $this->makeController('Bdus\\Controllers\\Config');
        $res  = $this->callController($ctrl, 'getAppProperties');

        $this->assertArrayHasKey('main', $res);
        $this->assertArrayHasKey('name', $res['main']);
        $this->assertSame('bdus_test', $res['main']['name']);
    }

    public function testGetAppPropertiesHasDbEngines(): void
    {
        $ctrl = $this->makeController('Bdus\\Controllers\\Config');
        $res  = $this->callController($ctrl, 'getAppProperties');

        $this->assertContains('sqlite', $res['db_engines']);
        $this->assertContains('mysql',  $res['db_engines']);
        $this->assertContains('pgsql',  $res['db_engines']);
    }

    public function testGetAppPropertiesHasStatusOptions(): void
    {
        $ctrl = $this->makeController('Bdus\\Controllers\\Config');
        $res  = $this->callController($ctrl, 'getAppProperties');

        $this->assertContains('on',     $res['status_options']);
        $this->assertContains('frozen', $res['status_options']);
        $this->assertContains('off',    $res['status_options']);
    }

    public function testGetAppPropertiesHasNoLangs(): void
    {
        // langs was removed from the backend response in v5: the frontend
        // owns the list of available locales (vue/src/i18n/index.js).
        $ctrl = $this->makeController('Bdus\\Controllers\\Config');
        $res  = $this->callController($ctrl, 'getAppProperties');

        $this->assertArrayNotHasKey('langs', $res);
    }

    public function testGetAppPropertiesRequiresSuperAdmin(): void
    {
        $this->setPrivilege(11);
        $ctrl = $this->makeController('Bdus\\Controllers\\Config');
        $res  = $this->callController($ctrl, 'getAppProperties');
        $this->setPrivilege(1);

        $this->assertSame('error', $res['status']);
        $this->assertSame('not_enough_privilege', $res['code']);
    }

    // ── getTableConfig ───────────────────────────────────────────────────

    public function testGetTableConfigReturnsSuccess(): void
    {
        $ctrl = $this->makeController('Bdus\\Controllers\\Config', ['tb' => self::TB]);
        $res  = $this->callController($ctrl, 'getTableConfig');

        $this->assertSame('success', $res['status']);
    }

    public function testGetTableConfigReturnsTableData(): void
    {
        $ctrl = $this->makeController('Bdus\\Controllers\\Config', ['tb' => self::TB]);
        $res  = $this->callController($ctrl, 'getTableConfig');

        $this->assertSame(self::TB,  $res['table']['name']);
        $this->assertSame('Items',   $res['table']['label']);
    }

    public function testGetTableConfigHasRequiredResponseKeys(): void
    {
        $ctrl = $this->makeController('Bdus\\Controllers\\Config', ['tb' => self::TB]);
        $res  = $this->callController($ctrl, 'getTableConfig');

        foreach (['table', 'field_labels', 'available_plugins', 'available_tables'] as $key) {
            $this->assertArrayHasKey($key, $res, "Response missing key: $key");
        }
    }

    public function testGetTableConfigFieldLabelsContainFixtureFields(): void
    {
        $ctrl = $this->makeController('Bdus\\Controllers\\Config', ['tb' => self::TB]);
        $res  = $this->callController($ctrl, 'getTableConfig');

        $this->assertArrayHasKey('name',   $res['field_labels']);
        $this->assertArrayHasKey('status', $res['field_labels']);
    }

    public function testGetTableConfigAvailablePluginsContainsTagsTable(): void
    {
        $ctrl = $this->makeController('Bdus\\Controllers\\Config', ['tb' => self::TB]);
        $res  = $this->callController($ctrl, 'getTableConfig');

        // tags is a plugin table — must appear in available_plugins
        $this->assertArrayHasKey(self::TB_PLUG, $res['available_plugins']);
    }

    public function testGetTableConfigWithoutTbReturnsDefaults(): void
    {
        $ctrl = $this->makeController('Bdus\\Controllers\\Config');
        $res  = $this->callController($ctrl, 'getTableConfig');

        // "add new" mode: table data should have default placeholder values
        $this->assertArrayHasKey('preview', $res['table']);
        $this->assertArrayHasKey('link',    $res['table']);
        $this->assertArrayHasKey('plugin',  $res['table']);
    }

    public function testGetTableConfigRequiresSuperAdmin(): void
    {
        $this->setPrivilege(11);
        $ctrl = $this->makeController('Bdus\\Controllers\\Config', ['tb' => self::TB]);
        $res  = $this->callController($ctrl, 'getTableConfig');
        $this->setPrivilege(1);

        $this->assertSame('error', $res['status']);
    }

    // ── getFldStructure ──────────────────────────────────────────────────

    public function testGetFldStructureReturnsSuccess(): void
    {
        $ctrl = $this->makeController('Bdus\\Controllers\\Config');
        $res  = $this->callController($ctrl, 'getFldStructure');

        $this->assertSame('success', $res['status']);
        $this->assertIsArray($res['structure']);
        $this->assertNotEmpty($res['structure']);
    }

    public function testGetFldStructureContainsAllSchemaProperties(): void
    {
        $ctrl = $this->makeController('Bdus\\Controllers\\Config');
        $res  = $this->callController($ctrl, 'getFldStructure');
        $keys = array_keys($res['structure']);

        foreach (['name', 'label', 'type', 'db_type', 'check', 'readonly', 'help'] as $prop) {
            $this->assertContains($prop, $keys, "fld_structure missing property: $prop");
        }
    }

    public function testGetFldStructureTypePropertyHasCorrectValues(): void
    {
        $ctrl = $this->makeController('Bdus\\Controllers\\Config');
        $res  = $this->callController($ctrl, 'getFldStructure');

        $typeValues = $res['structure']['type']['values'];
        foreach (['text', 'date', 'long_text', 'select', 'multi_select', 'boolean'] as $t) {
            $this->assertContains($t, $typeValues);
        }
    }

    public function testGetFldStructureIdFromTbContainsConfiguredTables(): void
    {
        $ctrl = $this->makeController('Bdus\\Controllers\\Config');
        $res  = $this->callController($ctrl, 'getFldStructure');

        // id_from_tb values should include at least the fixture tables
        $values = $res['structure']['id_from_tb']['values'];
        $this->assertContains(self::TB, $values);
    }

    public function testGetFldStructureRequiresSuperAdmin(): void
    {
        $this->setPrivilege(11);
        $ctrl = $this->makeController('Bdus\\Controllers\\Config');
        $res  = $this->callController($ctrl, 'getFldStructure');
        $this->setPrivilege(1);

        $this->assertSame('error', $res['status']);
    }

    // ── getFldConfig ─────────────────────────────────────────────────────

    public function testGetFldConfigReturnsSuccess(): void
    {
        $ctrl = $this->makeController('Bdus\\Controllers\\Config', ['tb' => self::TB, 'fld' => self::FLD]);
        $res  = $this->callController($ctrl, 'getFldConfig');

        $this->assertSame('success', $res['status']);
    }

    public function testGetFldConfigReturnsFieldData(): void
    {
        $ctrl = $this->makeController('Bdus\\Controllers\\Config', ['tb' => self::TB, 'fld' => self::FLD]);
        $res  = $this->callController($ctrl, 'getFldConfig');

        $this->assertSame(self::FLD, $res['field']['name']);
        $this->assertSame('Name',    $res['field']['label']);
        $this->assertSame('text',    $res['field']['type']);
    }

    public function testGetFldConfigReturnsStructure(): void
    {
        $ctrl = $this->makeController('Bdus\\Controllers\\Config', ['tb' => self::TB, 'fld' => self::FLD]);
        $res  = $this->callController($ctrl, 'getFldConfig');

        $this->assertIsArray($res['structure']);
        $this->assertArrayHasKey('name', $res['structure']);
        $this->assertArrayHasKey('type', $res['structure']);
    }

    public function testGetFldConfigWithNoFldReturnsEmptyField(): void
    {
        $ctrl = $this->makeController('Bdus\\Controllers\\Config', ['tb' => self::TB]);
        $res  = $this->callController($ctrl, 'getFldConfig');

        $this->assertSame('success', $res['status']);
        $this->assertSame([], $res['field']);
    }

    public function testGetFldConfigWithNoTbNoFldReturnsEmpty(): void
    {
        $ctrl = $this->makeController('Bdus\\Controllers\\Config');
        $res  = $this->callController($ctrl, 'getFldConfig');

        $this->assertSame('success', $res['status']);
        $this->assertSame([], $res['field']);
    }

    public function testGetFldConfigRequiresSuperAdmin(): void
    {
        $this->setPrivilege(11);
        $ctrl = $this->makeController('Bdus\\Controllers\\Config', ['tb' => self::TB, 'fld' => self::FLD]);
        $res  = $this->callController($ctrl, 'getFldConfig');
        $this->setPrivilege(1);

        $this->assertSame('error', $res['status']);
    }

    // ── activateFuzzyDate / deactivateFuzzyDate ───────────────────────────

    public function testActivateFuzzyDateReturnsSuccess(): void
    {
        $ctrl = $this->makeController('Bdus\\Controllers\\Config', ['tb' => self::TB]);
        $res  = $this->callController($ctrl, 'activateFuzzyDate');

        $this->assertSame('success', $res['status']);
        $this->assertSame('fuzzy_date_activated', $res['code']);

        // cleanup
        $this->makeController('Bdus\\Controllers\\Config', ['tb' => self::TB]);
        $this->callController(
            $this->makeController('Bdus\\Controllers\\Config', ['tb' => self::TB]),
            'deactivateFuzzyDate'
        );
    }

    public function testActivateFuzzyDateCreatesColumnsAndFieldDefs(): void
    {
        $this->callController(
            $this->makeController('Bdus\\Controllers\\Config', ['tb' => self::TB]),
            'activateFuzzyDate'
        );

        // DB columns must exist
        $inspect = new \DB\Inspect(static::$db);
        $cols    = array_column($inspect->tableColumns(self::TB), 'fld');
        foreach (['chrono_from', 'chrono_to', 'chrono_label', 'chrono_certainty', 'chrono_period'] as $col) {
            $this->assertContains($col, $cols, "Expected DB column: $col");
        }

        // Config field defs must exist with hide=true
        $fields = static::$cfg->get('tables.' . self::TB . '.fields') ?: [];
        foreach (['chrono_from', 'chrono_to', 'chrono_label', 'chrono_certainty', 'chrono_period'] as $fld) {
            $this->assertArrayHasKey($fld, $fields, "Expected field def: $fld");
            $this->assertTrue((bool)($fields[$fld]['hide'] ?? false), "$fld should have hide=true");
        }

        // cleanup
        $this->callController(
            $this->makeController('Bdus\\Controllers\\Config', ['tb' => self::TB]),
            'deactivateFuzzyDate'
        );
    }

    public function testActivateFuzzyDateIsIdempotent(): void
    {
        $ctrl = $this->makeController('Bdus\\Controllers\\Config', ['tb' => self::TB]);
        $this->callController($ctrl, 'activateFuzzyDate');
        $ctrl2 = $this->makeController('Bdus\\Controllers\\Config', ['tb' => self::TB]);
        $res   = $this->callController($ctrl2, 'activateFuzzyDate');

        $this->assertSame('success', $res['status']);

        // Field defs must not be duplicated — exactly 5 chrono_* fields
        $fields      = static::$cfg->get('tables.' . self::TB . '.fields') ?: [];
        $chronoCount = count(array_filter(array_keys($fields), fn($n) => str_starts_with($n, 'chrono_')));
        $this->assertSame(5, $chronoCount, 'Double activation must not duplicate field defs');

        // cleanup
        $this->callController(
            $this->makeController('Bdus\\Controllers\\Config', ['tb' => self::TB]),
            'deactivateFuzzyDate'
        );
    }

    public function testDeactivateFuzzyDateClearsFieldDefsPreservesColumns(): void
    {
        // Activate first
        $this->callController(
            $this->makeController('Bdus\\Controllers\\Config', ['tb' => self::TB]),
            'activateFuzzyDate'
        );

        // Then deactivate
        $ctrl = $this->makeController('Bdus\\Controllers\\Config', ['tb' => self::TB]);
        $res  = $this->callController($ctrl, 'deactivateFuzzyDate');

        $this->assertSame('success', $res['status']);
        $this->assertSame('fuzzy_date_deactivated', $res['code']);

        // Config field defs must be removed
        $fields = static::$cfg->get('tables.' . self::TB . '.fields') ?: [];
        foreach (['chrono_from', 'chrono_to', 'chrono_label', 'chrono_certainty', 'chrono_period'] as $fld) {
            $this->assertArrayNotHasKey($fld, $fields, "Field def $fld should have been removed from config");
        }

        // DB columns must be preserved (data protection)
        $inspect = new \DB\Inspect(static::$db);
        $cols    = array_column($inspect->tableColumns(self::TB), 'fld');
        foreach (['chrono_from', 'chrono_to'] as $col) {
            $this->assertContains($col, $cols, "DB column $col must be preserved on deactivation");
        }
    }

    public function testActivateFuzzyDateRequiresSuperAdmin(): void
    {
        $this->setPrivilege(11);
        $ctrl = $this->makeController('Bdus\\Controllers\\Config', ['tb' => self::TB]);
        $res  = $this->callController($ctrl, 'activateFuzzyDate');
        $this->setPrivilege(1);

        $this->assertSame('error', $res['status']);
        $this->assertSame('not_enough_privilege', $res['code']);
    }

    // ── getGeoFaceConfig ──────────────────────────────────────────────────

    public function testGetGeoFaceConfigReturnsSuccess(): void
    {
        $ctrl = $this->makeController('Bdus\\Controllers\\Config');
        $res  = $this->callController($ctrl, 'getGeoFaceConfig');

        $this->assertSame('success', $res['status']);
    }

    public function testGetGeoFaceConfigReturnsLayers(): void
    {
        $ctrl = $this->makeController('Bdus\\Controllers\\Config');
        $res  = $this->callController($ctrl, 'getGeoFaceConfig');

        $this->assertIsArray($res['layers']);
        $this->assertCount(1, $res['layers']);
        $this->assertSame('OSM',   $res['layers'][0]['label']);
        $this->assertSame('tiles', $res['layers'][0]['type']);
    }

    public function testGetGeoFaceConfigReturnsLocalFiles(): void
    {
        $ctrl = $this->makeController('Bdus\\Controllers\\Config');
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

        $ctrl = $this->makeController('Bdus\\Controllers\\Config');
        $res  = $this->callController($ctrl, 'getGeoFaceConfig');

        rename(PROJ_DIR . 'geodata/index.json.bak', PROJ_DIR . 'geodata/index.json');

        $this->assertSame('success', $res['status']);
        $this->assertSame([], $res['layers']);
    }

    public function testGetGeoFaceConfigRequiresSuperAdmin(): void
    {
        $this->setPrivilege(11);
        $ctrl = $this->makeController('Bdus\\Controllers\\Config');
        $res  = $this->callController($ctrl, 'getGeoFaceConfig');
        $this->setPrivilege(1);

        $this->assertSame('error', $res['status']);
    }

    // ── getValidationReport ───────────────────────────────────────────────

    public function testGetValidationReportReturnsSuccess(): void
    {
        $ctrl = $this->makeController('Bdus\\Controllers\\Config');
        $res  = $this->callController($ctrl, 'getValidationReport');

        $this->assertSame('success', $res['status']);
        $this->assertIsArray($res['report']);
        $this->assertNotEmpty($res['report']);
    }

    public function testGetValidationReportItemsHaveRequiredKeys(): void
    {
        $ctrl = $this->makeController('Bdus\\Controllers\\Config');
        $res  = $this->callController($ctrl, 'getValidationReport');

        foreach ($res['report'] as $item) {
            $this->assertArrayHasKey('status', $item);
            $this->assertArrayHasKey('text',   $item);
        }
    }

    public function testGetValidationReportStatusValuesAreValid(): void
    {
        $ctrl    = $this->makeController('Bdus\\Controllers\\Config');
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
        $ctrl     = $this->makeController('Bdus\\Controllers\\Config');
        $res      = $this->callController($ctrl, 'getValidationReport');
        $statuses = array_column($res['report'], 'status');

        $this->assertContains('head', $statuses,
            'Report must contain at least one section header (head)');
    }

    public function testGetValidationReportFixItemsHaveCorrectShape(): void
    {
        $ctrl = $this->makeController('Bdus\\Controllers\\Config');
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
        $this->setPrivilege(11);
        $ctrl = $this->makeController('Bdus\\Controllers\\Config');
        $res  = $this->callController($ctrl, 'getValidationReport');
        $this->setPrivilege(1);

        $this->assertSame('error', $res['status']);
        $this->assertSame('not_enough_privilege', $res['code']);
    }

    // ── activateOsteology / deactivateOsteology ───────────────────────────

    public function testActivateOsteologyReturnsSuccess(): void
    {
        $res = $this->callController(
            $this->makeController('Bdus\\Controllers\\Config', ['tb' => self::TB]),
            'activateOsteology'
        );

        $this->assertSame('success', $res['status']);
        $this->assertSame('osteology_activated', $res['code']);

        // cleanup
        $this->callController(
            $this->makeController('Bdus\\Controllers\\Config', ['tb' => self::TB]),
            'deactivateOsteology'
        );
    }

    public function testActivateOsteologyCreatesColumnAndFieldDef(): void
    {
        $this->callController(
            $this->makeController('Bdus\\Controllers\\Config', ['tb' => self::TB]),
            'activateOsteology'
        );

        // DB column must exist
        $inspect = new \DB\Inspect(static::$db);
        $cols    = array_column($inspect->tableColumns(self::TB), 'fld');
        $this->assertContains('osteo_data', $cols, 'Expected DB column: osteo_data');

        // Config field def must exist with hide=true
        $fields = static::$cfg->get('tables.' . self::TB . '.fields') ?: [];
        $this->assertArrayHasKey('osteo_data', $fields, 'Expected field def: osteo_data');
        $this->assertTrue((bool)($fields['osteo_data']['hide'] ?? false), 'osteo_data should have hide=true');

        // Schema flag must be set
        $this->assertTrue((bool)static::$cfg->get('tables.' . self::TB . '.osteology'), 'tables.{tb}.osteology must be true');

        // cleanup
        $this->callController(
            $this->makeController('Bdus\\Controllers\\Config', ['tb' => self::TB]),
            'deactivateOsteology'
        );
    }

    public function testActivateOsteologyIsIdempotent(): void
    {
        $this->callController(
            $this->makeController('Bdus\\Controllers\\Config', ['tb' => self::TB]),
            'activateOsteology'
        );
        $res = $this->callController(
            $this->makeController('Bdus\\Controllers\\Config', ['tb' => self::TB]),
            'activateOsteology'
        );

        $this->assertSame('success', $res['status']);

        // Field def must not be duplicated — exactly one osteo_data key
        $fields     = static::$cfg->get('tables.' . self::TB . '.fields') ?: [];
        $osteoCount = count(array_filter(array_keys($fields), fn($n) => $n === 'osteo_data'));
        $this->assertSame(1, $osteoCount, 'Double activation must not duplicate osteo_data field def');

        // cleanup
        $this->callController(
            $this->makeController('Bdus\\Controllers\\Config', ['tb' => self::TB]),
            'deactivateOsteology'
        );
    }

    public function testDeactivateOsteologyClearsFieldDefPreservesColumn(): void
    {
        $this->callController(
            $this->makeController('Bdus\\Controllers\\Config', ['tb' => self::TB]),
            'activateOsteology'
        );

        $res = $this->callController(
            $this->makeController('Bdus\\Controllers\\Config', ['tb' => self::TB]),
            'deactivateOsteology'
        );

        $this->assertSame('success', $res['status']);
        $this->assertSame('osteology_deactivated', $res['code']);

        // Config field def must be removed
        $fields = static::$cfg->get('tables.' . self::TB . '.fields') ?: [];
        $this->assertArrayNotHasKey('osteo_data', $fields, 'Field def osteo_data should be removed on deactivation');

        // Schema flag must be cleared
        $this->assertFalse((bool)static::$cfg->get('tables.' . self::TB . '.osteology'), 'tables.{tb}.osteology must be false after deactivation');

        // DB column must be preserved (data protection)
        $inspect = new \DB\Inspect(static::$db);
        $cols    = array_column($inspect->tableColumns(self::TB), 'fld');
        $this->assertContains('osteo_data', $cols, 'DB column osteo_data must be preserved on deactivation');
    }

    public function testDeactivateOsteologyIsIdempotent(): void
    {
        $res = $this->callController(
            $this->makeController('Bdus\\Controllers\\Config', ['tb' => self::TB]),
            'deactivateOsteology'
        );

        $this->assertSame('success', $res['status']);
        $this->assertSame('osteology_deactivated', $res['code']);
    }

    public function testActivateOsteologyWithMissingTableReturnsError(): void
    {
        $res = $this->callController(
            $this->makeController('Bdus\\Controllers\\Config', ['tb' => '__no_such_table__']),
            'activateOsteology'
        );

        $this->assertSame('error', $res['status']);
        $this->assertSame('missing_table', $res['code']);
    }

    public function testActivateOsteologyRequiresSuperAdmin(): void
    {
        $this->setPrivilege(11);
        $res = $this->callController(
            $this->makeController('Bdus\\Controllers\\Config', ['tb' => self::TB]),
            'activateOsteology'
        );
        $this->setPrivilege(1);

        $this->assertSame('error', $res['status']);
        $this->assertSame('not_enough_privilege', $res['code']);
    }
}
