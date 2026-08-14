<?php

namespace Tests\Integration;

use PHPUnit\Framework\TestCase;
use DB\DB;
use DB\System\Manage;
use Config\Config;
use Config\ToDB;
use Adbar\Dot;
use Monolog\Logger;
use Monolog\Handler\NullHandler;

/**
 * Covers #19: a plugin table must never surface in another table's `link`
 * list — that would render as a "Linked records" entry the frontend can
 * never open, since plugin tables are excluded from GET /api/tables.
 *
 * Config::saveRelation() (see ConfigRelationsCtrlTest) rejects *new* relations
 * like this, but this test targets the second, defensive layer in
 * Config\LoadFromDB::tables(): a row written before that guard existed, or
 * inserted directly into bdus_cfg_relations by some other path, must still
 * be filtered out when the config is loaded — not just when it is saved.
 */
class LoadFromDbPluginLinksTest extends TestCase
{
    private static DB     $db;
    private static Config $cfg;

    public static function setUpBeforeClass(): void
    {
        $log = new Logger('test');
        $log->pushHandler(new NullHandler());

        static::$db = new DB('test', ['db_engine' => 'sqlite', 'db_path' => ':memory:']);
        static::$db->setLog($log);

        $manage = new Manage(static::$db);
        $manage->createTable('bdus_cfg_tables');
        $manage->createTable('bdus_cfg_fields');
        $manage->createTable('bdus_cfg_relations');

        // Mirrors the real-world #19 scenario: a plugin table ('finds_in_contexts')
        // whose real parent is 'contexts', but which also carries an ordinary FK
        // to an unrelated main table ('sites') — as if written directly to the DB,
        // bypassing Config::saveRelation()'s guard.
        ToDB::upsertTable(static::$db, ['name' => 'sites',    'label' => 'Sites']);
        ToDB::upsertTable(static::$db, ['name' => 'contexts', 'label' => 'Contexts']);
        ToDB::upsertTable(static::$db, [
            'name'      => 'finds_in_contexts',
            'label'     => 'Finds in contexts',
            'is_plugin' => 1,
        ]);

        static::$db->query(
            'INSERT INTO bdus_cfg_relations (from_tb, from_col, to_tb, to_col, on_delete, on_update)
             VALUES (?,?,?,?,?,?)',
            ['finds_in_contexts', 'id_link', 'contexts', 'id', 'RESTRICT', 'CASCADE'],
            'boolean'
        );
        static::$db->query(
            'INSERT INTO bdus_cfg_relations (from_tb, from_col, to_tb, to_col, on_delete, on_update)
             VALUES (?,?,?,?,?,?)',
            ['finds_in_contexts', 'sites_id', 'sites', 'id', 'RESTRICT', 'CASCADE'],
            'boolean'
        );

        $dot = new Dot();
        static::$cfg = new Config($dot, __DIR__ . '/../fixtures/cfg/', static::$db);
    }

    public function testPluginParentRelationIsNotListedAsLinkOnParent(): void
    {
        // The legitimate id_link relation must be routed to the inline plugin
        // section, not to 'contexts'.link — unaffected by this fix, asserted
        // here as a baseline.
        $link = static::$cfg->get('tables.contexts.link') ?: [];
        $this->assertNotContains('finds_in_contexts', array_column($link, 'other_tb'));
    }

    public function testExtraPluginRelationIsNotListedAsLinkOnUnrelatedTable(): void
    {
        // This is the actual #19 bug: 'sites' must not show the plugin table
        // as a linked record, even though a relation row exists for it.
        $link = static::$cfg->get('tables.sites.link') ?: [];
        $this->assertNotContains(
            'finds_in_contexts',
            array_column($link, 'other_tb'),
            'a plugin table must never appear in another table\'s link list'
        );
    }

    public function testExtraPluginRelationIsNotListedOnThePluginTableItself(): void
    {
        $link = static::$cfg->get('tables.finds_in_contexts.link') ?: [];
        $this->assertNotContains('sites', array_column($link, 'other_tb'));
    }

    public function testSitesTablePluginListIsUnaffected(): void
    {
        // The filtered-out relation must not leak into 'sites' as a false
        // plugin attachment either — it's just dropped, not misrouted.
        $plugins = static::$cfg->get('tables.sites.plugin') ?: [];
        $this->assertNotContains('finds_in_contexts', $plugins);
    }
}
