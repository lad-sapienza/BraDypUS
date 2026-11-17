<?php

namespace Tests\Integration;

use Adbar\Dot;
use Config\Config;
use Config\LoadFromDB;
use Config\ToDB;
use Tests\Support\BdusTestCase;

/**
 * #53 phase 4 — table lifecycle: deactivate (soft), remove from configuration
 * (hard, keep data), delete (drop). DB-backed Config: nothing is written to the
 * fixture files.
 */
class TableLifecycleTest extends BdusTestCase
{
    private const CFG = 'Bdus\\Controllers\\Config';

    protected static function createSchema(): void
    {
        parent::createSchema();
        foreach (['lc_sites', 'lc_finds', 'lc_solo', 'lc_solo2'] as $t) {
            static::$db->exec("CREATE TABLE $t (id INTEGER PRIMARY KEY AUTOINCREMENT, creator INTEGER, name TEXT" .
                ($t === 'lc_finds' ? ', site_id INTEGER REFERENCES lc_sites(id)' : '') . ')');
        }
        static::$db->exec("INSERT INTO lc_solo (name) VALUES ('keep me')");
    }

    public static function setUpBeforeClass(): void
    {
        parent::setUpBeforeClass();
        (new \DB\System\Manage(static::$db))->createTable('bdus_cfg_tables');
        (new \DB\System\Manage(static::$db))->createTable('bdus_cfg_fields');
        ToDB::upsertTable(static::$db, ['name' => 'existing', 'label' => 'Existing']);
        static::$cfg = new Config(new Dot(), __DIR__ . '/../fixtures/cfg/', static::$db);
        foreach (['lc_sites', 'lc_finds', 'lc_solo', 'lc_solo2'] as $t) {
            $r = \Config\Activate::activate(static::$db, static::$cfg, $t);
            if ($r['status'] !== 'success') {
                throw new \RuntimeException("cannot activate $t: " . json_encode($r));
            }
        }
    }

    /** A fresh Config reads what is really stored. */
    private function reload(): Config
    {
        return new Config(new Dot(), __DIR__ . '/../fixtures/cfg/', static::$db);
    }

    private function call(string $method, array $get = [], array $post = []): array
    {
        // Like a real request: every call starts from what is stored.
        static::$cfg = $this->reload();
        return $this->callController($this->makeController(self::CFG, $get, $post), $method);
    }

    public function testDeactivatedTableDisappearsFromTheConfigurationButIsKept(): void
    {
        $res = $this->call('setTableActive', ['tb' => 'lc_solo'], ['active' => false]);
        $this->assertSame('table_deactivated', $res['code']);

        $cfg = $this->reload();
        $this->assertNotContains('lc_solo', $cfg->get('tables.*.name'));
        $this->assertArrayHasKey('lc_solo', LoadFromDB::inactiveTables(static::$db));
        // configuration is still in the database…
        $this->assertSame(1, (int) static::$db->query("SELECT COUNT(*) AS n FROM bdus_cfg_tables WHERE name='lc_solo'", [], 'read')[0]['n']);
        $this->assertGreaterThan(0, (int) static::$db->query("SELECT COUNT(*) AS n FROM bdus_cfg_fields WHERE table_name='lc_solo'", [], 'read')[0]['n']);
        // …and so is the data
        $this->assertSame(1, (int) static::$db->query('SELECT COUNT(*) AS n FROM lc_solo', [], 'read')[0]['n']);
    }

    public function testDeactivatedTableIsListedAsInactiveAndNotAsNew(): void
    {
        $this->call('setTableActive', ['tb' => 'lc_solo'], ['active' => false]);
        $objects = array_column($this->call('listDbObjects')['objects'], null, 'name');
        $this->assertTrue($objects['lc_solo']['inactive']);
        $this->assertTrue($objects['lc_solo']['activatable']);
        $this->assertFalse($objects['lc_solo2'] ?? false ? true : false, 'configured tables are not listed');
    }

    public function testReactivationRestoresTheSavedConfigurationUntouched(): void
    {
        static::$cfg = $this->reload();
        $before = static::$cfg->get('tables.lc_solo2');
        $this->call('save_tb_data', [], ['name' => 'lc_solo2', 'label' => 'Custom label', 'order' => 'id', 'id_field' => 'id', 'preview' => ['id'], 'is_plugin' => 0]);
        $this->call('setTableActive', ['tb' => 'lc_solo2'], ['active' => false]);

        $res = $this->call('activateDbObject', ['name' => 'lc_solo2']);
        $this->assertSame('table_reactivated', $res['code']);

        $cfg = $this->reload();
        $this->assertSame('Custom label', $cfg->get('tables.lc_solo2.label'), 'the saved label survives a deactivate/activate cycle');
        $this->assertSame(array_keys($before['fields']), array_keys($cfg->get('tables.lc_solo2.fields')));
        $this->assertArrayNotHasKey('lc_solo2', LoadFromDB::inactiveTables(static::$db));
    }

    public function testRelationsToADeactivatedTableAreIgnoredAndComeBack(): void
    {
        $rels = static::$db->query("SELECT COUNT(*) AS n FROM bdus_cfg_relations WHERE from_tb='lc_finds'", [], 'read')[0]['n'];
        $this->assertSame(1, (int) $rels);

        $this->call('setTableActive', ['tb' => 'lc_sites'], ['active' => false]);
        $cfg = $this->reload();                       // must not throw
        $this->assertEmpty($cfg->get('tables.lc_finds.link') ?: [], 'no link to a table that is off');
        $this->assertSame(1, (int) static::$db->query("SELECT COUNT(*) AS n FROM bdus_cfg_relations WHERE from_tb='lc_finds'", [], 'read')[0]['n'], 'relation row is kept');

        $this->call('activateDbObject', ['name' => 'lc_sites']);
        $this->assertNotEmpty($this->reload()->get('tables.lc_finds.link'));
    }

    public function testPluginTableCanBeSwitchedOffAndOnWhileItsParentIsOn(): void
    {
        ToDB::upsertTable(static::$db, ['name' => 'lc_plg', 'label' => 'Plugin', 'is_plugin' => 1]);
        static::$db->exec('CREATE TABLE lc_plg (id INTEGER PRIMARY KEY AUTOINCREMENT, id_link INTEGER, note TEXT)');
        static::$db->query('INSERT INTO bdus_cfg_relations (from_tb, from_col, to_tb, to_col, on_delete, on_update) VALUES (?,?,?,?,?,?)',
            ['lc_plg', 'id_link', 'lc_solo2', 'id', 'RESTRICT', 'CASCADE'], 'boolean');
        static::$cfg = $this->reload();
        $this->assertSame(['lc_plg'], $this->reload()->get('tables.lc_solo2.plugin'));

        // a table whose plugins are on cannot go off …
        $this->assertSame('table_not_deactivable', $this->call('setTableActive', ['tb' => 'lc_solo2'], ['active' => false])['code']);
        // … the plugin can, and then it is no longer one of its parent's plugins
        $this->assertSame('table_deactivated', $this->call('setTableActive', ['tb' => 'lc_plg'], ['active' => false])['code']);
        $this->assertEmpty($this->reload()->get('tables.lc_solo2.plugin'));
        $this->assertContains('lc_plg', array_column($this->call('listDbObjects')['objects'], 'name'));

        // with no active plugin left the parent can go off, but then the plugin cannot come back
        $this->assertSame('table_deactivated', $this->call('setTableActive', ['tb' => 'lc_solo2'], ['active' => false])['code']);
        $this->assertSame('parent_table_inactive', $this->call('setTableActive', ['tb' => 'lc_plg'], ['active' => true])['code']);
        // Database objects reactivates through activateDbObject: same rule
        $this->assertSame('parent_table_inactive', $this->call('activateDbObject', ['name' => 'lc_plg'])['code']);

        // parent back on, then the plugin
        $this->assertSame('table_reactivated', $this->call('setTableActive', ['tb' => 'lc_solo2'], ['active' => true])['code']);
        $this->assertSame('table_reactivated', $this->call('setTableActive', ['tb' => 'lc_plg'], ['active' => true])['code']);
        $this->assertSame(['lc_plg'], $this->reload()->get('tables.lc_solo2.plugin'));
    }

    public function testActiveTableCannotBeDropped(): void
    {
        // lc_solo2 (with its plugin lc_plg) is part of the configuration: not even the API drops it
        $res = $this->call('delete_tb', ['tb' => 'lc_solo2']);
        $this->assertSame('table_active', $res['code']);
        $this->assertSame('table_active', $this->call('delete_tb', ['tb' => 'lc_plg'])['code']);
        $this->assertTrue((new \DB\Inspect(static::$db))->tableExists('lc_solo2'));
        $this->assertTrue((new \DB\Inspect(static::$db))->tableExists('lc_plg'));
        $this->assertSame('lc_solo2', $this->reload()->get('tables.lc_solo2.name'), 'configuration untouched');
    }

    public function testTableWithPluginsCannotBeDroppedPluginsGoFirst(): void
    {
        // both off, the plugin still configured on the parent
        $this->call('setTableActive', ['tb' => 'lc_plg'], ['active' => false]);
        $this->call('setTableActive', ['tb' => 'lc_solo2'], ['active' => false]);
        $res = $this->call('delete_tb', ['tb' => 'lc_solo2']);
        $this->assertSame('table_has_plugins', $res['code']);
        $this->assertSame('lc_plg', $res['detail']);
        $this->assertTrue((new \DB\Inspect(static::$db))->tableExists('lc_solo2'));

        $this->assertSame('ok_cfg_tb_delete', $this->call('delete_tb', ['tb' => 'lc_plg'])['code']);
        $this->assertFalse((new \DB\Inspect(static::$db))->tableExists('lc_plg'));
    }

    public function testKeepDataAndViewsAreNotAffectedByTheActiveGuard(): void
    {
        // taking an active table out of the configuration (data stays) is always allowed
        $this->call('add_new_tb', [], ['name' => 'lc_keep', 'label' => 'Keep', 'is_plugin' => '0']);
        $this->assertSame('ok_cfg_tb_delete', $this->call('delete_tb', ['tb' => 'lc_keep', 'keep_data' => '1'])['code']);
        $this->assertTrue((new \DB\Inspect(static::$db))->tableExists('lc_keep'));
        // …and then the table, no longer configured, can be dropped
        $this->assertSame('ok_cfg_tb_delete', $this->call('delete_tb', ['tb' => 'lc_keep'])['code']);
        $this->assertFalse((new \DB\Inspect(static::$db))->tableExists('lc_keep'));
    }

    public function testDropForgetsTheIndexRecordsAndRefusesSystemTables(): void
    {
        $this->call('add_new_tb', [], ['name' => 'lc_idx', 'label' => 'Idx', 'is_plugin' => '0']);
        $this->call('saveIndex', ['tb' => 'lc_idx'], ['name' => 'idx_lc_idx_id', 'columns' => ['id']]);
        $this->assertSame(1, (int) static::$db->query("SELECT COUNT(*) AS n FROM bdus_cfg_indexes WHERE tb='lc_idx'", [], 'read')[0]['n']);

        $this->call('setTableActive', ['tb' => 'lc_idx'], ['active' => false]);
        $this->assertSame('ok_cfg_tb_delete', $this->call('delete_tb', ['tb' => 'lc_idx'])['code']);
        $this->assertSame(0, (int) static::$db->query("SELECT COUNT(*) AS n FROM bdus_cfg_indexes WHERE tb='lc_idx'", [], 'read')[0]['n']);

        $this->assertSame('not_found', $this->call('delete_tb', ['tb' => 'bdus_users'])['code']);
        $this->assertTrue((new \DB\Inspect(static::$db))->tableExists('bdus_users'));
    }

    public function testSwitchValidation(): void
    {
        $this->assertSame('parameter_missing', $this->call('setTableActive', ['tb' => 'lc_sites'], [])['code']);
        $this->assertSame('not_found', $this->call('setTableActive', ['tb' => 'nope'], ['active' => false])['code']);
        $this->assertSame('not_found', $this->call('setTableActive', ['tb' => 'bdus_users'], ['active' => false])['code']);
        $this->assertSame('not_found', $this->call('setTableActive', ['tb' => 'lc_sites'], ['active' => true])['code'], 'already on');
    }

    public function testNewTableCannotReuseTheNameOfADeactivatedOne(): void
    {
        $this->call('setTableActive', ['tb' => 'lc_solo'], ['active' => false]);
        $res = $this->call('add_new_tb', [], ['name' => 'lc_solo', 'label' => 'x', 'is_plugin' => '0']);
        $this->assertSame('tb_already_available', $res['code']);
        $this->call('activateDbObject', ['name' => 'lc_solo']);
    }

    public function testHardRemoveKeepsTableDataAndForeignKeys(): void
    {
        static::$cfg = $this->reload();
        $res = $this->call('delete_tb', ['tb' => 'lc_finds', 'keep_data' => '1']);
        $this->assertSame('ok_cfg_tb_delete', $res['code']);

        $this->assertTrue((new \DB\Inspect(static::$db))->tableExists('lc_finds'), 'the table stays');
        $this->assertNotEmpty((new \DB\Inspect(static::$db))->foreignKeys('lc_finds'), 'its foreign key stays');
        $this->assertNotContains('lc_finds', $this->reload()->get('tables.*.name'));

        // back among the database objects, and activating it re-imports the FK as a relation
        $this->assertArrayHasKey('lc_finds', array_column($this->call('listDbObjects')['objects'], null, 'name'));
        $act = $this->call('activateDbObject', ['name' => 'lc_finds']);
        $this->assertSame(1, $act['relations_created']);
    }

    public function testDeleteDropsTheTable(): void
    {
        // lc_solo2 has been off since the plugins test: only a table that is off is dropped
        $res = $this->call('delete_tb', ['tb' => 'lc_solo2']);
        $this->assertSame('ok_cfg_tb_delete', $res['code']);
        $this->assertFalse((new \DB\Inspect(static::$db))->tableExists('lc_solo2'));
    }

    public function testTableCreatedFromTheApiCanBeDeactivatedAndListed(): void
    {
        $res = $this->call('add_new_tb', [], ['name' => 'lc_api', 'label' => 'Api', 'is_plugin' => '0']);
        $this->assertSame('success', $res['status'], json_encode($res));
        $this->assertSame('table_deactivated', $this->call('setTableActive', ['tb' => 'lc_api'], ['active' => false])['code']);

        $list = $this->call('listDbObjects');
        $this->assertSame('success', $list['status'], json_encode($list));
        $objects = array_column($list['objects'], null, 'name');
        $this->assertArrayHasKey('lc_api', $objects, json_encode(array_keys($objects)));
        $this->assertTrue($objects['lc_api']['inactive']);
        $this->call('activateDbObject', ['name' => 'lc_api']);
    }
}
