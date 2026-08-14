<?php

namespace Tests\Integration;

use Tests\Support\BdusTestCase;

/**
 * Covers #19: Config::saveRelation() must reject any relation touching a
 * plugin table outside its own id_link-to-parent row. Without this guard, a
 * superadmin can create an ordinary FK from/to a plugin table via the
 * "Relations" panel; the table on the other end then shows a "Linked
 * records" entry the frontend can never open, because plugin tables are
 * excluded from GET /api/tables.
 *
 * Reuses the shared fixture's 'tags' table, which is already is_plugin=1 in
 * both the physical schema and tests/fixtures/cfg/tables.json (the JSON
 * config static::$cfg is built from — saveRelation()'s is_plugin check reads
 * that, not the bdus_cfg_tables DB rows other tests in this suite write).
 * 'items'/'categories' stand in for an ordinary main table and an unrelated
 * lookup table respectively.
 */
class ConfigSaveRelationPluginGuardTest extends BdusTestCase
{
    public static function tearDownAfterClass(): void
    {
        static::$db->query('DELETE FROM bdus_cfg_relations', [], 'boolean');
        static::$db->query('DELETE FROM bdus_cfg_indexes',   [], 'boolean');
        parent::tearDownAfterClass();
    }

    protected function setUp(): void
    {
        static::$db->query('DELETE FROM bdus_cfg_relations', [], 'boolean');
        static::$db->query('DELETE FROM bdus_cfg_indexes',   [], 'boolean');
    }

    public function testRejectsExtraRelationFromPluginTable(): void
    {
        // 'tags' is is_plugin=1 — an ordinary FK to 'categories' (a table
        // that isn't its parent) must be rejected.
        $ctrl = $this->makeController('Bdus\\Controllers\\Config', [], [
            'from_tb'  => 'tags',
            'from_col' => 'cat_ref',
            'to_tb'    => 'categories',
            'to_col'   => 'id',
        ]);
        $res = $this->callController($ctrl, 'saveRelation');

        $this->assertSame('error',                      $res['status']);
        $this->assertSame('plugin_relation_not_allowed', $res['code']);

        $row = static::$db->query(
            'SELECT id FROM bdus_cfg_relations WHERE from_tb=? AND from_col=?',
            ['tags', 'cat_ref'], 'read'
        );
        $this->assertEmpty($row, 'rejected relation must not be persisted');
    }

    public function testRejectsRelationTargetingPluginTable(): void
    {
        // Same failure mode from the other direction: an ordinary table must
        // not be allowed to hold a FK pointing at a plugin table's column.
        $ctrl = $this->makeController('Bdus\\Controllers\\Config', [], [
            'from_tb'  => 'categories',
            'from_col' => 'tag_ref',
            'to_tb'    => 'tags',
            'to_col'   => 'id',
        ]);
        $res = $this->callController($ctrl, 'saveRelation');

        $this->assertSame('error',                      $res['status']);
        $this->assertSame('plugin_relation_not_allowed', $res['code']);
    }

    public function testAllowsPluginIdLinkRelation(): void
    {
        // The one relation a plugin table IS allowed to define: from_col
        // 'id_link' to its parent — the guard checks the column name only,
        // it doesn't cross-check against a pre-configured parent table.
        $ctrl = $this->makeController('Bdus\\Controllers\\Config', [], [
            'from_tb'  => 'tags',
            'from_col' => 'id_link',
            'to_tb'    => 'items',
            'to_col'   => 'id',
        ]);
        $res = $this->callController($ctrl, 'saveRelation');

        $this->assertContains($res['status'], ['success', 'warning']);

        $row = static::$db->query(
            'SELECT to_tb FROM bdus_cfg_relations WHERE from_tb=? AND from_col=?',
            ['tags', 'id_link'], 'read'
        );
        $this->assertSame('items', $row[0]['to_tb'] ?? null);
    }

    public function testAllowsOrdinaryRelationBetweenNonPluginTables(): void
    {
        // Baseline: the guard must not affect relations that don't involve
        // any plugin table at all.
        $ctrl = $this->makeController('Bdus\\Controllers\\Config', [], [
            'from_tb'  => 'items',
            'from_col' => 'cat_ref',
            'to_tb'    => 'categories',
            'to_col'   => 'id',
        ]);
        $res = $this->callController($ctrl, 'saveRelation');

        $this->assertContains($res['status'], ['success', 'warning']);
    }

    public function testUpdatingExistingIdLinkRelationIsStillAllowed(): void
    {
        // Create the id_link row, then update it (e.g. changing on_delete) —
        // the guard re-runs on update too and must not block it.
        $created = $this->callController(
            $this->makeController('Bdus\\Controllers\\Config', [], [
                'from_tb'  => 'tags',
                'from_col' => 'id_link',
                'to_tb'    => 'items',
                'to_col'   => 'id',
            ]),
            'saveRelation'
        );
        $id = $created['id'];

        $ctrl2 = $this->makeController('Bdus\\Controllers\\Config', ['id' => (string)$id], [
            'from_tb'   => 'tags',
            'from_col'  => 'id_link',
            'to_tb'     => 'items',
            'to_col'    => 'id',
            'on_delete' => 'CASCADE',
        ]);
        $res = $this->callController($ctrl2, 'saveRelation');

        $this->assertContains($res['status'], ['success', 'warning']);
    }
}
