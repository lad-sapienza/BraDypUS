<?php

namespace Tests\Integration;

use Adbar\Dot;
use Bdus\DbmlExporter;
use Bdus\DbmlImporter;
use Bdus\DbmlParser;
use Config\Config;
use Config\ToDB;
use DB\Validate\DbCfgAlign;
use DB\Validate\Resp;
use Tests\Support\BdusTestCase;

/**
 * #53 phase 6 — SQL views in DBML (bdus:view=1: activated, never created) and the
 * drift checks of the validation report. DB-backed Config: fixtures are not touched.
 */
class DbmlViewTest extends BdusTestCase
{
    protected static function createSchema(): void
    {
        parent::createSchema();
        $db = static::$db;
        $db->exec('CREATE TABLE dv_base (id INTEGER PRIMARY KEY AUTOINCREMENT, creator INTEGER, name TEXT)');
        $db->exec('CREATE VIEW dv_ro AS SELECT id, name FROM dv_base');
        $db->exec('CREATE VIEW dv_noid AS SELECT name FROM dv_base');
        $db->exec('CREATE VIEW dv_rw AS SELECT id, creator, name FROM dv_base');
        $db->exec('CREATE TRIGGER dv_rw_i INSTEAD OF INSERT ON dv_rw BEGIN INSERT INTO dv_base (id,creator,name) VALUES (NEW.id,NEW.creator,NEW.name); END');
    }

    public static function setUpBeforeClass(): void
    {
        parent::setUpBeforeClass();
        (new \DB\System\Manage(static::$db))->createTable('bdus_cfg_tables');
        (new \DB\System\Manage(static::$db))->createTable('bdus_cfg_fields');
        ToDB::upsertTable(static::$db, ['name' => 'existing', 'label' => 'Existing']);
        static::$cfg = new Config(new Dot(), __DIR__ . '/../fixtures/cfg/', static::$db);
        $r = \Config\Activate::activate(static::$db, static::$cfg, 'dv_base');
        if ($r['status'] !== 'success') {
            throw new \RuntimeException(json_encode($r));
        }
    }

    private function parse(string $dbml): array
    {
        return (new DbmlParser())->parse($dbml);
    }

    private function errors(array $preview, string $table): array
    {
        foreach ($preview['tables'] as $t) {
            if ($t['name'] === $table) {
                return array_column($t['errors'], 'code');
            }
        }
        $this->fail("no preview entry for $table");
    }

    public function testPreviewOfAnExistingViewHasNoErrorsAndTakesFieldsFromTheDatabase(): void
    {
        $p = (new DbmlImporter())->preview($this->parse("Table dv_ro {\n  id integer [pk]\n  Note: 'bdus:view=1 bdus:label=Read only'\n}"), static::$cfg, static::$db);
        $this->assertSame([], $this->errors($p, 'dv_ro'));
        $entry = $p['tables'][0];
        $this->assertTrue($entry['is_view']);
        $this->assertSame(['id', 'name'], array_column($entry['fields'], 'name'), 'columns come from the view, not from the DBML');
    }

    public function testPreviewRefusesWhatCannotBeActivatedAsAView(): void
    {
        $cases = [
            'dv_missing' => 'view_not_found',
            'dv_base'    => 'already_configured',   // configured table named as a view
            'dv_noid'    => 'missing_id_column',
            'dv_rw'      => null,                    // fine
        ];
        foreach ($cases as $name => $code) {
            $p = (new DbmlImporter())->preview($this->parse("Table $name {\n  id integer [pk]\n  Note: 'bdus:view=1'\n}"), static::$cfg, static::$db);
            $errs = $this->errors($p, $name);
            $code === null ? $this->assertSame([], $errs, $name) : $this->assertContains($code === 'already_configured' ? 'table_already_exists' : $code, $errs, $name);
        }
    }

    public function testViewMarkedTableThatIsReallyATableIsRefused(): void
    {
        static::$db->exec('CREATE TABLE dv_plain (id INTEGER PRIMARY KEY, creator INTEGER)');
        $p = (new DbmlImporter())->preview($this->parse("Table dv_plain {\n  id integer [pk]\n  Note: 'bdus:view=1'\n}"), static::$cfg, static::$db);
        $this->assertContains('not_a_view', $this->errors($p, 'dv_plain'));
    }

    public function testViewCannotBeAPluginAndNeedsADatabase(): void
    {
        $dbml = "Table dv_ro {\n  id integer [pk]\n  Note: 'bdus:view=1 bdus:is_plugin=dv_base'\n}";
        $p = (new DbmlImporter())->preview($this->parse($dbml), static::$cfg, static::$db);
        $this->assertContains('view_cannot_be_plugin', $this->errors($p, 'dv_ro'));

        $p = (new DbmlImporter())->preview($this->parse("Table dv_ro {\n  id integer [pk]\n  Note: 'bdus:view=1'\n}"), static::$cfg);
        $this->assertContains('view_check_unavailable', $this->errors($p, 'dv_ro'));
    }

    public function testRefsToAndFromAViewAreSkippedWithAWarning(): void
    {
        $dbml = "Table dv_ro {\n  id integer [pk]\n  name varchar [ref: > dv_base.id]\n  Note: 'bdus:view=1'\n}\n"
              . "Table dv_child {\n  id integer [pk]\n  v integer [ref: > dv_rw.id]\n}\nTable dv_rw {\n  id integer [pk]\n  Note: 'bdus:view=1'\n}";
        $p = (new DbmlImporter())->preview($this->parse($dbml), static::$cfg, static::$db);
        $byName = array_column($p['tables'], null, 'name');
        $this->assertSame('ref_view_not_allowed', $byName['dv_ro']['warnings'][0]['code']);
        $this->assertSame([], $byName['dv_ro']['relations']);
        $this->assertContains('ref_view_not_allowed', array_column($byName['dv_child']['warnings'], 'code'));
        $this->assertSame([], $byName['dv_child']['relations']);
    }

    public function testApplyActivatesTheViewAndKeepsExplicitSettingsOnly(): void
    {
        $dbml = "Table dv_ro {\n  id integer [pk]\n  Note: 'bdus:view=1 bdus:label=Sola lettura'\n}";
        $res = (new DbmlImporter())->apply($this->parse($dbml), static::$cfg, static::$db);
        $this->assertSame(['dv_ro'], $res['created'], json_encode($res));

        $cfg = new Config(new Dot(), __DIR__ . '/../fixtures/cfg/', static::$db);
        $this->assertTrue((bool) $cfg->get('tables.dv_ro.is_view'));
        $this->assertSame('none', $cfg->get('tables.dv_ro.view_writable'));
        $this->assertSame('Sola lettura', $cfg->get('tables.dv_ro.label'));
        $this->assertSame(['id', 'name'], $cfg->get('tables.dv_ro.preview'), 'preview comes from the activation, not the DBML default');
        $this->assertTrue((new \DB\Inspect(static::$db))->isView('dv_ro'), 'the view is left alone');
    }

    public function testExportCarriesTheViewFlagAndRoundTrips(): void
    {
        static::$cfg = new Config(new Dot(), __DIR__ . '/../fixtures/cfg/', static::$db);
        $dbml = (new DbmlExporter())->export(static::$cfg->get('tables'), [], 'test');
        $this->assertMatchesRegularExpression("/Table dv_ro \{.*bdus:view=1/s", $dbml);
        $this->assertDoesNotMatchRegularExpression("/Table dv_base \{[^}]*bdus:view=1/s", $dbml, 'a table is not flagged as a view');

        // Re-importing it where the view already is configured: refused, as for any table.
        $p = (new DbmlImporter())->preview($this->parse($dbml), static::$cfg, static::$db);
        $this->assertContains('table_already_exists', $this->errors($p, 'dv_ro'));
    }

    // ── drift checks ────────────────────────────────────────────────────────

    private function align(): array
    {
        static::$cfg = new Config(new Dot(), __DIR__ . '/../fixtures/cfg/', static::$db);
        $resp = new Resp();
        (new DbCfgAlign($resp, static::$db, static::$cfg))->cfgViewFlags();
        return $resp->get();
    }

    private function messages(array $report, string $status): string
    {
        return implode("\n", array_column(array_filter($report, fn($m) => $m['status'] === $status), 'text'));
    }

    public function testValidationAcceptsAHealthyView(): void
    {
        $r = $this->align();
        $this->assertSame('', $this->messages($r, 'danger'), $this->messages($r, 'danger'));
        $this->assertStringContainsString('dv_ro has an id column', $this->messages($r, 'success'));
    }

    public function testValidationFlagsAViewTurnedIntoATable(): void
    {
        static::$db->exec('DROP VIEW dv_ro');
        static::$db->exec('CREATE TABLE dv_ro (id INTEGER PRIMARY KEY, name TEXT)');
        $this->assertStringContainsString('dv_ro is configured as a SQL view but is a table', $this->messages($this->align(), 'danger'));
        static::$db->exec('DROP TABLE dv_ro');
        static::$db->exec('CREATE VIEW dv_ro AS SELECT id, name FROM dv_base');
    }

    public function testValidationFlagsATableTurnedIntoAViewAndALostIdColumn(): void
    {
        static::$db->exec('DROP VIEW dv_ro');
        static::$db->exec('CREATE VIEW dv_ro AS SELECT name FROM dv_base');
        $this->assertStringContainsString('dv_ro has no id column any more', $this->messages($this->align(), 'danger'));
        static::$db->exec('DROP VIEW dv_ro');
        static::$db->exec('CREATE VIEW dv_ro AS SELECT id, name FROM dv_base');
    }

    public function testValidationReportsWritabilityDrift(): void
    {
        static::$db->exec('CREATE TRIGGER dv_ro_i INSTEAD OF INSERT ON dv_ro BEGIN INSERT INTO dv_base (id,name) VALUES (NEW.id,NEW.name); END');
        $warn = $this->messages($this->align(), 'warning');
        $this->assertStringContainsString("dv_ro: writable as 'trigger' in the database, configured as 'none'", $warn);
        static::$db->exec('DROP TRIGGER dv_ro_i');
    }
}
