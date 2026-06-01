<?php

namespace Tests\Integration;

use DB\System\Manage;
use Config\ToDB;
use Tests\Support\BdusTestCase;

/**
 * Integration tests for config_ctrl relations endpoints:
 *   getRelations, saveRelation, deleteRelation
 *
 * Tests the new schema: from_tb, from_col, to_tb, to_col, on_delete, on_update.
 * No alphabetical normalization — from_tb is always the FK holder.
 * Self-referential relations (from_tb == to_tb) are allowed with fixed policy.
 */
class ConfigRelationsCtrlTest extends BdusTestCase
{
    public static function setUpBeforeClass(): void
    {
        parent::setUpBeforeClass();

        $manage = new Manage(static::$db);
        $manage->createTable('bdus_cfg_tables');
        $manage->createTable('bdus_cfg_fields');

        ToDB::upsertTable(static::$db, ['name' => 'items', 'label' => 'Items']);
        ToDB::upsertTable(static::$db, ['name' => 'tags',  'label' => 'Tags']);
    }

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

    // ── getRelations ──────────────────────────────────────────────────────────

    public function testGetRelationsReturnsSuccessWhenEmpty(): void
    {
        $ctrl = $this->makeController('Bdus\\Controllers\\Config');
        $res  = $this->callController($ctrl, 'getRelations');

        $this->assertSame('success',   $res['status']);
        $this->assertSame('relations', $res['code']);
        $this->assertSame([],          $res['data']);
    }

    public function testGetRelationsRequiresSuperAdmin(): void
    {
        $this->setPrivilege(11);
        $ctrl = $this->makeController('Bdus\\Controllers\\Config');
        $res  = $this->callController($ctrl, 'getRelations');
        $this->setPrivilege(1);

        $this->assertSame('error', $res['status']);
        $this->assertSame('not_enough_privilege', $res['code']);
    }

    public function testGetRelationsReturnsNewSchemaFields(): void
    {
        static::$db->query(
            'INSERT INTO bdus_cfg_relations (from_tb, from_col, to_tb, to_col, on_delete, on_update) VALUES (?,?,?,?,?,?)',
            ['items', 'tag_id', 'tags', 'id', 'RESTRICT', 'CASCADE'],
            'boolean'
        );

        $ctrl = $this->makeController('Bdus\\Controllers\\Config');
        $res  = $this->callController($ctrl, 'getRelations');

        $this->assertCount(1, $res['data']);
        $row = $res['data'][0];

        $this->assertArrayHasKey('id',         $row);
        $this->assertArrayHasKey('from_tb',    $row);
        $this->assertArrayHasKey('from_col',   $row);
        $this->assertArrayHasKey('to_tb',      $row);
        $this->assertArrayHasKey('to_col',     $row);
        $this->assertArrayHasKey('on_delete',  $row);
        $this->assertArrayHasKey('on_update',  $row);
        $this->assertArrayHasKey('from_label', $row);
        $this->assertArrayHasKey('to_label',   $row);

        $this->assertSame('items',    $row['from_tb']);
        $this->assertSame('tag_id',   $row['from_col']);
        $this->assertSame('tags',     $row['to_tb']);
        $this->assertSame('id',       $row['to_col']);
        $this->assertSame('RESTRICT', $row['on_delete']);
        $this->assertSame('CASCADE',  $row['on_update']);
        $this->assertSame('Items',    $row['from_label']);
        $this->assertSame('Tags',     $row['to_label']);
    }

    // ── saveRelation — create ─────────────────────────────────────────────────

    public function testSaveRelationCreatesNewRow(): void
    {
        $ctrl = $this->makeController('Bdus\\Controllers\\Config', [], [
            'from_tb'  => 'items',
            'from_col' => 'tag_id',
            'to_tb'    => 'tags',
            'to_col'   => 'id',
        ]);
        $res = $this->callController($ctrl, 'saveRelation');

        // May be success or warning (orphans) — both mean the row was saved.
        $this->assertContains($res['status'], ['success', 'warning']);
        $this->assertContains($res['code'], ['relation_saved', 'relation_saved_orphans_found']);
        $this->assertIsInt($res['id']);
        $this->assertGreaterThan(0, $res['id']);
    }

    public function testSaveRelationStoresCorrectDirection(): void
    {
        $ctrl = $this->makeController('Bdus\\Controllers\\Config', [], [
            'from_tb'  => 'tags',
            'from_col' => 'item_ref',
            'to_tb'    => 'items',
            'to_col'   => 'id',
        ]);
        $this->callController($ctrl, 'saveRelation');

        $row = static::$db->query(
            'SELECT from_tb, from_col, to_tb FROM bdus_cfg_relations',
            [], 'read'
        );
        $this->assertCount(1, $row);
        // Direction must be preserved exactly as given — no alphabetical swap.
        $this->assertSame('tags',     $row[0]['from_tb']);
        $this->assertSame('item_ref', $row[0]['from_col']);
        $this->assertSame('items',    $row[0]['to_tb']);
    }

    public function testSaveRelationRejectsMissingParameters(): void
    {
        $ctrl = $this->makeController('Bdus\\Controllers\\Config', [], [
            'from_tb'  => 'items',
            'from_col' => 'tag_id',
            // to_tb and to_col missing
        ]);
        $res = $this->callController($ctrl, 'saveRelation');

        $this->assertSame('error',             $res['status']);
        $this->assertSame('parameter_missing', $res['code']);
    }

    public function testSaveRelationRejectsDuplicateFromTbFromCol(): void
    {
        $ctrl = $this->makeController('Bdus\\Controllers\\Config', [], [
            'from_tb' => 'items', 'from_col' => 'tag_id',
            'to_tb'   => 'tags',  'to_col'   => 'id',
        ]);
        $this->callController($ctrl, 'saveRelation');

        $ctrl2 = $this->makeController('Bdus\\Controllers\\Config', [], [
            'from_tb' => 'items', 'from_col' => 'tag_id',
            'to_tb'   => 'tags',  'to_col'   => 'ref',
        ]);
        $res = $this->callController($ctrl2, 'saveRelation');

        $this->assertSame('error',                   $res['status']);
        $this->assertSame('relation_already_exists', $res['code']);
    }

    public function testSaveRelationAllowsOppositeDirectionBetweenSameTables(): void
    {
        // items.tag_id → tags.id
        $ctrl = $this->makeController('Bdus\\Controllers\\Config', [], [
            'from_tb' => 'items', 'from_col' => 'tag_id',
            'to_tb'   => 'tags',  'to_col'   => 'id',
        ]);
        $this->callController($ctrl, 'saveRelation');

        // tags.item_ref → items.id  (opposite direction — different FK column, must succeed)
        $ctrl2 = $this->makeController('Bdus\\Controllers\\Config', [], [
            'from_tb' => 'tags',  'from_col' => 'item_ref',
            'to_tb'   => 'items', 'to_col'   => 'id',
        ]);
        $res = $this->callController($ctrl2, 'saveRelation');

        $this->assertContains($res['status'], ['success', 'warning']);
    }

    public function testSaveRelationAllowsSelfReferential(): void
    {
        $ctrl = $this->makeController('Bdus\\Controllers\\Config', [], [
            'from_tb'  => 'items',
            'from_col' => 'parent_id',
            'to_tb'    => 'items',
            'to_col'   => 'id',
        ]);
        $res = $this->callController($ctrl, 'saveRelation');
        $this->assertContains($res['status'], ['success', 'warning']);
    }

    public function testSaveRelationForcesSelfReferentialPolicy(): void
    {
        $ctrl = $this->makeController('Bdus\\Controllers\\Config', [], [
            'from_tb'   => 'items',
            'from_col'  => 'parent_id',
            'to_tb'     => 'items',
            'to_col'    => 'id',
            'on_delete' => 'CASCADE',   // must be overridden
            'on_update' => 'RESTRICT',  // must be overridden
        ]);
        $this->callController($ctrl, 'saveRelation');

        $row = static::$db->query(
            'SELECT on_delete, on_update FROM bdus_cfg_relations WHERE from_col=?',
            ['parent_id'], 'read'
        );
        $this->assertSame('RESTRICT', $row[0]['on_delete']);
        $this->assertSame('CASCADE',  $row[0]['on_update']);
    }

    public function testSaveRelationRejectsInvalidPolicy(): void
    {
        $ctrl = $this->makeController('Bdus\\Controllers\\Config', [], [
            'from_tb'   => 'items',
            'from_col'  => 'tag_id',
            'to_tb'     => 'tags',
            'to_col'    => 'id',
            'on_delete' => 'EXPLODE',
        ]);
        $res = $this->callController($ctrl, 'saveRelation');

        $this->assertSame('error',              $res['status']);
        $this->assertSame('invalid_on_delete',  $res['code']);
    }

    public function testSaveRelationRequiresSuperAdmin(): void
    {
        $this->setPrivilege(11);
        $ctrl = $this->makeController('Bdus\\Controllers\\Config', [], [
            'from_tb' => 'items', 'from_col' => 'tag_id',
            'to_tb'   => 'tags',  'to_col'   => 'id',
        ]);
        $res = $this->callController($ctrl, 'saveRelation');
        $this->setPrivilege(1);

        $this->assertSame('error', $res['status']);
        $this->assertSame('not_enough_privilege', $res['code']);
    }

    // ── saveRelation — update ─────────────────────────────────────────────────

    public function testSaveRelationUpdateChangesTargetOnly(): void
    {
        // Create
        $ctrl = $this->makeController('Bdus\\Controllers\\Config', [], [
            'from_tb' => 'items', 'from_col' => 'tag_id',
            'to_tb'   => 'tags',  'to_col'   => 'id',
            'on_delete' => 'RESTRICT',
        ]);
        $created = $this->callController($ctrl, 'saveRelation');
        $id = $created['id'];

        // Update: change on_delete
        $ctrl2 = $this->makeController('Bdus\\Controllers\\Config', ['id' => (string)$id], [
            'from_tb' => 'items', 'from_col' => 'tag_id',
            'to_tb'   => 'tags',  'to_col'   => 'id',
            'on_delete' => 'CASCADE',
        ]);
        $updated = $this->callController($ctrl2, 'saveRelation');

        $this->assertContains($updated['status'], ['success', 'warning']);
        $this->assertSame($id, $updated['id']);

        $row = static::$db->query(
            'SELECT on_delete, from_tb, from_col FROM bdus_cfg_relations WHERE id=?',
            [$id], 'read'
        );
        $this->assertSame('CASCADE', $row[0]['on_delete']);
        // from_tb / from_col must not change
        $this->assertSame('items',  $row[0]['from_tb']);
        $this->assertSame('tag_id', $row[0]['from_col']);
    }

    public function testSaveRelationUpdateReturnsNotFoundForBadId(): void
    {
        $ctrl = $this->makeController('Bdus\\Controllers\\Config', ['id' => '99999'], [
            'from_tb' => 'items', 'from_col' => 'tag_id',
            'to_tb'   => 'tags',  'to_col'   => 'id',
        ]);
        $res = $this->callController($ctrl, 'saveRelation');

        $this->assertSame('error',     $res['status']);
        $this->assertSame('not_found', $res['code']);
    }

    // ── deleteRelation ────────────────────────────────────────────────────────

    public function testDeleteRelationRemovesRow(): void
    {
        $ctrl = $this->makeController('Bdus\\Controllers\\Config', [], [
            'from_tb' => 'items', 'from_col' => 'tag_id',
            'to_tb'   => 'tags',  'to_col'   => 'id',
        ]);
        $created = $this->callController($ctrl, 'saveRelation');
        $id = $created['id'];

        $ctrl2 = $this->makeController('Bdus\\Controllers\\Config', ['id' => (string)$id]);
        $res   = $this->callController($ctrl2, 'deleteRelation');

        $this->assertSame('success',          $res['status']);
        $this->assertSame('relation_deleted', $res['code']);

        $row = static::$db->query(
            'SELECT id FROM bdus_cfg_relations WHERE id=?', [$id], 'read'
        );
        $this->assertEmpty($row);
    }

    public function testDeleteRelationWithMissingId(): void
    {
        $ctrl = $this->makeController('Bdus\\Controllers\\Config');
        $res  = $this->callController($ctrl, 'deleteRelation');

        $this->assertSame('error',             $res['status']);
        $this->assertSame('parameter_missing', $res['code']);
    }

    public function testDeleteRelationWithNonExistentId(): void
    {
        $ctrl = $this->makeController('Bdus\\Controllers\\Config', ['id' => '99999']);
        $res  = $this->callController($ctrl, 'deleteRelation');

        $this->assertSame('error',     $res['status']);
        $this->assertSame('not_found', $res['code']);
    }

    public function testDeleteRelationRequiresSuperAdmin(): void
    {
        $ctrl = $this->makeController('Bdus\\Controllers\\Config', [], [
            'from_tb' => 'items', 'from_col' => 'tag_id',
            'to_tb'   => 'tags',  'to_col'   => 'id',
        ]);
        $created = $this->callController($ctrl, 'saveRelation');
        $id = $created['id'];

        $this->setPrivilege(11);
        $ctrl2 = $this->makeController('Bdus\\Controllers\\Config', ['id' => (string)$id]);
        $res   = $this->callController($ctrl2, 'deleteRelation');
        $this->setPrivilege(1);

        $this->assertSame('error', $res['status']);
        $this->assertSame('not_enough_privilege', $res['code']);

        $row = static::$db->query('SELECT id FROM bdus_cfg_relations WHERE id=?', [$id], 'read');
        $this->assertNotEmpty($row);
    }
}
