<?php

namespace Tests\Integration;

use Adbar\Dot;
use Config\Config;
use Config\ToDB;
use DB\Validate\DbCfgAlign;
use DB\Validate\Resp;
use DB\Validate\SystemTables;
use Tests\Support\BdusTestCase;

/**
 * Validation report: columns BraDypUS itself adds (system tables, fuzzy date) are not
 * reported as the user's problem, and `id` / `creator` are required where they must be.
 */
class ValidateCoreFieldsTest extends BdusTestCase
{
    protected static function createSchema(): void
    {
        parent::createSchema();
        $db = static::$db;
        $db->exec('CREATE TABLE vc_ok (id INTEGER PRIMARY KEY AUTOINCREMENT, creator INTEGER, name TEXT, chrono_label TEXT)');
        $db->exec('CREATE TABLE vc_nocreator (id INTEGER PRIMARY KEY AUTOINCREMENT, name TEXT)');
        $db->exec('CREATE TABLE vc_plg (id INTEGER PRIMARY KEY AUTOINCREMENT, id_link INTEGER, name TEXT)');
        $db->exec('CREATE TABLE vc_cfgless (id INTEGER PRIMARY KEY AUTOINCREMENT, creator INTEGER, name TEXT)');
    }

    public static function setUpBeforeClass(): void
    {
        parent::setUpBeforeClass();
        $db = static::$db;
        (new \DB\System\Manage($db))->createTable('bdus_cfg_tables');
        (new \DB\System\Manage($db))->createTable('bdus_cfg_fields');

        $idF = ['name' => 'id',      'label' => 'Id',      'type' => 'text', 'db_type' => 'INTEGER', 'readonly' => '1'];
        $crF = ['name' => 'creator', 'label' => 'Creator', 'type' => 'text', 'db_type' => 'INTEGER', 'readonly' => '1'];
        $nmF = ['name' => 'name',    'label' => 'Name',    'type' => 'text', 'db_type' => 'TEXT'];

        ToDB::upsertTable($db, ['name' => 'vc_ok', 'label' => 'Ok', 'fuzzy_date' => '1']);
        foreach ([$idF, $crF, $nmF, ['name' => 'chrono_label', 'label' => 'x', 'type' => 'text']] as $f) {
            ToDB::upsertField($db, 'vc_ok', $f);
        }
        ToDB::upsertTable($db, ['name' => 'vc_nocreator', 'label' => 'No creator']);
        foreach ([$idF, $nmF] as $f) {
            ToDB::upsertField($db, 'vc_nocreator', $f);
        }
        ToDB::upsertTable($db, ['name' => 'vc_plg', 'label' => 'Plugin', 'is_plugin' => '1']);
        foreach ([$idF, ['name' => 'id_link', 'label' => 'L', 'type' => 'text', 'db_type' => 'INTEGER'], $nmF] as $f) {
            ToDB::upsertField($db, 'vc_plg', $f);
        }
        ToDB::upsertTable($db, ['name' => 'vc_cfgless', 'label' => 'Cfg-less']);
        ToDB::upsertField($db, 'vc_cfgless', $nmF);
        // seeded system plugin, config without db_type (as in apps created before it was set)
        ToDB::upsertTable($db, ['name' => 'bdus_geodata', 'label' => 'Geo', 'is_plugin' => '1']);
        ToDB::upsertField($db, 'bdus_geodata', ['name' => 'geometry', 'label' => 'WKT', 'type' => 'text']);

        static::$cfg = new Config(new Dot(), __DIR__ . '/../fixtures/cfg/', static::$db);
    }

    private function report(string $method): array
    {
        $resp = new Resp();
        (new DbCfgAlign($resp, static::$db, static::$cfg))->$method();
        return $resp->get();
    }

    private function text(array $report, string $status): string
    {
        return implode("\n", array_column(array_filter($report, fn($m) => $m['status'] === $status), 'text'));
    }

    public function testSystemAndFuzzyDateColumnsAreNotReportedForMissingDbType(): void
    {
        $danger = $this->text($this->report('cfgHasDb_type'), 'danger');
        $this->assertStringNotContainsString('bdus_geodata', $danger);
        $this->assertStringNotContainsString('chrono_label', $danger);
    }

    public function testAMissingDbTypeOnAUserFieldIsStillReported(): void
    {
        ToDB::upsertField(static::$db, 'vc_ok', ['name' => 'extra', 'label' => 'E', 'type' => 'text']);
        static::$cfg = new Config(new Dot(), __DIR__ . '/../fixtures/cfg/', static::$db);
        $this->assertStringContainsString('Missing db_type for column vc_ok.extra', $this->text($this->report('cfgHasDb_type'), 'danger'));
    }

    public function testCoreFieldsCheck(): void
    {
        $r = $this->report('cfgCoreFields');
        $danger  = $this->text($r, 'danger');
        $success = $this->text($r, 'success');

        $this->assertStringContainsString('vc_ok has the required field creator', $success);
        $this->assertStringContainsString('vc_nocreator has no creator column in the database', $danger);
        $this->assertStringContainsString('vc_cfgless has no id field in the configuration', $danger);
        $this->assertStringContainsString('vc_cfgless has no creator field in the configuration', $danger);
        // a plugin table needs `id` but never a `creator`
        $this->assertStringContainsString('vc_plg has the required field id', $success);
        $this->assertStringNotContainsString('vc_plg has no creator', $danger);
    }

    public function testSystemTablesModelCoversEveryCfgAppColumnAddedByMigrations(): void
    {
        $db = static::$db;
        foreach (['image_presets', 'char_maps', 'mcp_enabled', 'mcp_write', 'mcp_delete', 'mcp_guide'] as $col) {
            $this->assertContains($col, array_column((new \DB\System\Manage($db))->getStructure('bdus_cfg_app'), 'name'), $col);
        }
    }
}
