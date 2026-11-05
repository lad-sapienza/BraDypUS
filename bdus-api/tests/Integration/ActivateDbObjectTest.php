<?php

namespace Tests\Integration;

use Adbar\Dot;
use Config\Activate;
use Config\Config;
use Config\ToDB;
use Tests\Support\BdusTestCase;

/**
 * #53 phase 2 — activating tables/views that exist in the database but not in
 * the configuration. Uses a DB-backed Config (not the fixture files), so
 * nothing is written to tests/fixtures/cfg/.
 */
class ActivateDbObjectTest extends BdusTestCase
{
    private const CTRL = 'Bdus\\Controllers\\Config';

    protected static function createSchema(): void
    {
        parent::createSchema();
        $db = static::$db;
        $db->exec('PRAGMA foreign_keys = ON');
        $db->exec('CREATE TABLE ao_site (id INTEGER PRIMARY KEY, creator INTEGER, name TEXT)');
        $db->exec('CREATE TABLE ao_find (
            id INTEGER PRIMARY KEY, creator INTEGER, site_id INTEGER, notes TEXT, found_on DATE, n INTEGER,
            FOREIGN KEY (site_id) REFERENCES ao_site(id) ON DELETE CASCADE)');
        $db->exec('CREATE TABLE ao_other (id INTEGER PRIMARY KEY, other_id INTEGER, FOREIGN KEY (other_id) REFERENCES ao_unmanaged(id))');
        $db->exec('CREATE TABLE ao_unmanaged (id INTEGER PRIMARY KEY)');
        $db->exec('CREATE TABLE ao_noid (code TEXT, label TEXT)');
        $db->exec('CREATE VIEW ao_v_ro AS SELECT id, name FROM ao_site');
        $db->exec('CREATE VIEW ao_v_noid AS SELECT name FROM ao_site');
        $db->exec('CREATE VIEW ao_v_rw_nocreator AS SELECT id, name FROM ao_site');
        $db->exec('CREATE TRIGGER ao_v_rw_nocreator_i INSTEAD OF INSERT ON ao_v_rw_nocreator
                   BEGIN INSERT INTO ao_site (id, name) VALUES (NEW.id, NEW.name); END');
        $db->exec('CREATE VIEW ao_v_rw AS SELECT id, creator, name FROM ao_site');
        $db->exec('CREATE TRIGGER ao_v_rw_i INSTEAD OF INSERT ON ao_v_rw
                   BEGIN INSERT INTO ao_site (id, creator, name) VALUES (NEW.id, NEW.creator, NEW.name); END');
    }

    public static function setUpBeforeClass(): void
    {
        parent::setUpBeforeClass();
        // DB-backed config: never touches the fixture files.
        (new \DB\System\Manage(static::$db))->createTable('bdus_cfg_tables');
        (new \DB\System\Manage(static::$db))->createTable('bdus_cfg_fields');
        ToDB::upsertTable(static::$db, ['name' => 'existing', 'label' => 'Existing']);
        static::$cfg = new Config(new Dot(), __DIR__ . '/../fixtures/cfg/', static::$db);
    }

    private function objects(): array
    {
        $res = $this->callController($this->makeController(self::CTRL), 'listDbObjects');
        $this->assertSame('success', $res['status']);
        return array_column($res['objects'], null, 'name');
    }

    public function testListSkipsSystemAndConfiguredObjects(): void
    {
        $o = $this->objects();
        foreach (array_keys($o) as $n) {
            $this->assertStringStartsNotWith('bdus_', $n);
        }
        $this->assertArrayNotHasKey('existing', $o);
        $this->assertSame('table', $o['ao_site']['kind']);
        $this->assertSame('view', $o['ao_v_ro']['kind']);
    }

    public function testListExplainsWhyAnObjectCannotBeActivated(): void
    {
        $o = $this->objects();
        $this->assertTrue($o['ao_site']['activatable']);
        $this->assertTrue($o['ao_v_ro']['activatable'], 'read-only view needs only id');
        $this->assertSame('missing_id_column', $o['ao_noid']['reason']);
        $this->assertSame('missing_id_column', $o['ao_v_noid']['reason']);
        $this->assertSame('missing_creator_column', $o['ao_v_rw_nocreator']['reason']);
        $this->assertTrue($o['ao_v_rw']['activatable']);
        $this->assertSame('trigger', $o['ao_v_rw']['view_writable']);
    }

    public function testPreviewDoesNotWriteAnything(): void
    {
        $res = $this->callController($this->makeController(self::CTRL, ['name' => 'ao_find']), 'getDbObject');
        $this->assertSame('success', $res['status']);
        $this->assertTrue($res['activatable']);
        $this->assertSame(['id', 'creator', 'site_id', 'notes', 'found_on', 'n'], array_column($res['fields'], 'name'));
        $this->assertSame('ao_site', $res['relations'][0]['to_tb']);
        $this->assertSame('CASCADE', $res['relations'][0]['on_delete']);
        $this->assertEmpty(static::$cfg->get('tables.ao_find.name'));
    }

    public function testPreviewOfUnknownObjectIsNotFound(): void
    {
        $res = $this->callController($this->makeController(self::CTRL, ['name' => 'nope']), 'getDbObject');
        $this->assertSame('not_found', $res['code']);
        $res = $this->callController($this->makeController(self::CTRL, ['name' => 'bdus_users']), 'getDbObject');
        $this->assertSame('not_found', $res['code'], 'system tables are never exposed');
    }

    public function testActivateTableCreatesConfigAndFieldTypes(): void
    {
        $res = $this->callController($this->makeController(self::CTRL, ['name' => 'ao_site']), 'activateDbObject');
        $this->assertSame('success', $res['status'], json_encode($res));
        $this->assertSame('table', $res['kind']);

        $this->assertSame('ao_site', static::$cfg->get('tables.ao_site.name'));
        $this->assertSame('id', static::$cfg->get('tables.ao_site.id_field'));
        $this->assertSame(['id', 'name'], static::$cfg->get('tables.ao_site.preview'));
        $this->assertEmpty(static::$cfg->get('tables.ao_site.is_view'));
        $this->assertTrue((bool) static::$cfg->get('tables.ao_site.fields.id.readonly'));
        $this->assertSame('text', static::$cfg->get('tables.ao_site.fields.name.type'));
        $list = $this->callController($this->makeController(self::CTRL), 'getTableList')['tables'];
        $this->assertContains('ao_site', array_column($list, 'name'));
        $this->assertArrayNotHasKey('ao_site', $this->objects(), 'no longer offered once configured');
    }

    public function testActivateTableImportsForeignKeysOnlyToConfiguredTargets(): void
    {
        // ao_site is configured by the previous test; ao_find -> ao_site is recordable
        $this->callController($this->makeController(self::CTRL, ['name' => 'ao_site']), 'activateDbObject');
        $res = $this->callController($this->makeController(self::CTRL, ['name' => 'ao_find']), 'activateDbObject');
        $this->assertSame('success', $res['status'], json_encode($res));
        $this->assertSame(1, $res['relations_created']);
        $this->assertSame('date', static::$cfg->get('tables.ao_find.fields.found_on.type'));

        $rel = static::$db->query('SELECT * FROM bdus_cfg_relations WHERE from_tb = ?', ['ao_find'], 'read');
        $this->assertCount(1, $rel);
        $this->assertSame('site_id', $rel[0]['from_col']);
        $this->assertSame('CASCADE', $rel[0]['on_delete']);

        $res = $this->callController($this->makeController(self::CTRL, ['name' => 'ao_other']), 'activateDbObject');
        $this->assertSame(0, $res['relations_created']);
        $this->assertSame('target_not_configured', $res['relations_skipped'][0]['reason']);
    }

    public function testActivateRefusesWhenRulesAreNotMet(): void
    {
        foreach (['ao_noid' => 'missing_id_column', 'ao_v_noid' => 'missing_id_column',
                  'ao_v_rw_nocreator' => 'missing_creator_column', 'existing' => null] as $name => $code) {
            $res = $this->callController($this->makeController(self::CTRL, ['name' => $name]), 'activateDbObject');
            $this->assertSame('error', $res['status'], $name);
            $this->assertSame($code ?? 'not_found', $res['code'], $name);
        }
        $this->assertEmpty(static::$cfg->get('tables.ao_noid.name'));
    }

    public function testActivateViewFlagsItAndNeverTouchesTheView(): void
    {
        $res = $this->callController($this->makeController(self::CTRL, ['name' => 'ao_v_ro']), 'activateDbObject');
        $this->assertSame('success', $res['status'], json_encode($res));
        $this->assertSame('view', $res['kind']);
        $this->assertTrue((bool) static::$cfg->get('tables.ao_v_ro.is_view'));
        $this->assertSame('none', static::$cfg->get('tables.ao_v_ro.view_writable'));
        $this->assertTrue((new \DB\Inspect(static::$db))->isView('ao_v_ro'));

        // Second activation of the same object is refused.
        $res = $this->callController($this->makeController(self::CTRL, ['name' => 'ao_v_ro']), 'activateDbObject');
        $this->assertSame('already_configured', $res['code']);
    }

    public function testViewFlagSurvivesTableFormSaveAndIsExposed(): void
    {
        $this->callController($this->makeController(self::CTRL, ['name' => 'ao_v_rw']), 'activateDbObject');

        // The table form does not post is_view/view_writable — and a hostile client might post lies.
        $ctrl = $this->makeController(self::CTRL, ['tb' => 'ao_v_rw'], [
            'name' => 'ao_v_rw', 'label' => 'Renamed label', 'order' => 'id', 'id_field' => 'id',
            'preview' => ['id', 'name'], 'is_plugin' => 0, 'is_view' => false, 'view_writable' => 'none',
        ]);
        $this->assertSame('success', $this->callController($ctrl, 'save_tb_data')['status']);

        $this->assertTrue((bool) static::$cfg->get('tables.ao_v_rw.is_view'));
        $this->assertSame('trigger', static::$cfg->get('tables.ao_v_rw.view_writable'));

        $res = $this->callController($this->makeController(self::CTRL, ['tb' => 'ao_v_rw']), 'getTableConfig');
        $this->assertTrue($res['table']['is_view']);
        $this->assertStringContainsStringIgnoringCase('ao_site', $res['table']['view_sql']);

        $list = $this->callController($this->makeController(self::CTRL), 'getTableList')['tables'];
        $byName = array_column($list, null, 'name');
        $this->assertTrue($byName['ao_v_rw']['is_view']);
        $this->assertFalse($byName['ao_site']['is_view']);
    }

    public function testViewCannotBeAlteredAfterActivation(): void
    {
        $ctrl = $this->makeController(self::CTRL, ['tb' => 'ao_v_rw'], ['name' => 'x', 'type' => 'text', 'db_type' => 'TEXT']);
        $this->assertSame('view_not_alterable', $this->callController($ctrl, 'add_new_fld')['code']);
    }

    public function testRequiresSuperAdmin(): void
    {
        $this->setPrivilege(30);
        try {
            foreach (['listDbObjects', 'getDbObject', 'activateDbObject'] as $m) {
                $res = $this->callController($this->makeController(self::CTRL, ['name' => 'ao_noid']), $m);
                $this->assertSame('not_enough_privilege', $res['code'], $m);
            }
        } finally {
            $this->setPrivilege(1);
        }
    }
}
