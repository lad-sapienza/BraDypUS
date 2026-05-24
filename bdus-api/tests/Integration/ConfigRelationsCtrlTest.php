<?php

namespace Tests\Integration;

use DB\System\Manage;
use Config\ToDB;
use Tests\Support\BdusTestCase;

/**
 * Integration tests for config_ctrl relations endpoints:
 *   getRelations, saveRelation, deleteRelation
 *
 * The test DB (from BdusTestCase) needs bdus_cfg_tables + bdus_cfg_relations.
 * We create them in setUpBeforeClass and seed two tables (items / tags) that
 * match the fixture-config names already used by ConfigCtrlTest.
 *
 * All tests call the controller methods directly — no HTTP stack involved.
 */
class ConfigRelationsCtrlTest extends BdusTestCase
{
    // ── Lifecycle ─────────────────────────────────────────────────────────

    public static function setUpBeforeClass(): void
    {
        parent::setUpBeforeClass();

        $manage = new Manage(static::$db);
        $manage->createTable('bdus_cfg_tables');
        $manage->createTable('bdus_cfg_fields');
        $manage->createTable('bdus_cfg_relations');

        // Seed two table rows so labels are resolved in getRelations responses.
        ToDB::upsertTable(static::$db, ['name' => 'items', 'label' => 'Items']);
        ToDB::upsertTable(static::$db, ['name' => 'tags',  'label' => 'Tags']);
    }

    public static function tearDownAfterClass(): void
    {
        // Clean up all relations inserted by tests so the shared in-memory DB
        // is tidy for any test class that might share the process.
        static::$db->query('DELETE FROM bdus_cfg_relations', [], 'boolean');
        parent::tearDownAfterClass();
    }

    // Helper: flush the relations table between tests that mutate it.
    protected function setUp(): void
    {
        static::$db->query('DELETE FROM bdus_cfg_relations', [], 'boolean');
    }

    // ── getRelations — empty ──────────────────────────────────────────────

    public function testGetRelationsReturnsSuccessWhenEmpty(): void
    {
        $ctrl = $this->makeController('config_ctrl');
        $res  = $this->callController($ctrl, 'getRelations');

        $this->assertSame('success', $res['status']);
        $this->assertSame('relations', $res['code']);
        $this->assertSame([], $res['data']);
    }

    public function testGetRelationsRequiresSuperAdmin(): void
    {
        $this->setPrivilege(11);
        $ctrl = $this->makeController('config_ctrl');
        $res  = $this->callController($ctrl, 'getRelations');
        $this->setPrivilege(1);

        $this->assertSame('error', $res['status']);
        $this->assertSame('not_enough_privilege', $res['code']);
    }

    // ── saveRelation — create ─────────────────────────────────────────────

    public function testSaveRelationCreatesNewRow(): void
    {
        $ctrl = $this->makeController('config_ctrl', [], [
            'from_tb' => 'items',
            'to_tb'   => 'tags',
            'fld'     => [['my' => 'id', 'other' => 'id_link']],
        ]);
        $res = $this->callController($ctrl, 'saveRelation');

        $this->assertSame('success', $res['status']);
        $this->assertSame('relation_saved', $res['code']);
        $this->assertIsInt($res['id']);
        $this->assertGreaterThan(0, $res['id']);
    }

    public function testSaveRelationAppearsInGetRelations(): void
    {
        // Create
        $ctrl = $this->makeController('config_ctrl', [], [
            'from_tb' => 'items',
            'to_tb'   => 'tags',
            'fld'     => [['my' => 'id', 'other' => 'id_link']],
        ]);
        $this->callController($ctrl, 'saveRelation');

        // List
        $ctrl2 = $this->makeController('config_ctrl');
        $res   = $this->callController($ctrl2, 'getRelations');

        $this->assertCount(1, $res['data']);
        $row = $res['data'][0];
        $this->assertArrayHasKey('id',         $row);
        $this->assertArrayHasKey('from_tb',    $row);
        $this->assertArrayHasKey('to_tb',      $row);
        $this->assertArrayHasKey('from_label', $row);
        $this->assertArrayHasKey('to_label',   $row);
        $this->assertArrayHasKey('fld',        $row);
        $this->assertSame('Items', $row['from_label']);
        $this->assertSame('Tags',  $row['to_label']);
    }

    public function testSaveRelationNormalisesCanonicalOrder(): void
    {
        // "tags" > "items" alphabetically → backend must swap so items is from_tb
        $ctrl = $this->makeController('config_ctrl', [], [
            'from_tb' => 'tags',
            'to_tb'   => 'items',
            'fld'     => [['my' => 'id_link', 'other' => 'id']],
        ]);
        $this->callController($ctrl, 'saveRelation');

        $row = static::$db->query(
            'SELECT from_tb, to_tb, fld FROM bdus_cfg_relations',
            [],
            'read'
        );
        $this->assertCount(1, $row);
        // Canonical: alphabetically-first table must be from_tb
        $this->assertSame('items', $row[0]['from_tb']);
        $this->assertSame('tags',  $row[0]['to_tb']);

        // fld must have been inverted: my/other swapped
        $fld = json_decode($row[0]['fld'], true);
        $this->assertSame('id',      $fld[0]['my']);
        $this->assertSame('id_link', $fld[0]['other']);
    }

    public function testSaveRelationRejectsMissingTables(): void
    {
        $ctrl = $this->makeController('config_ctrl', [], [
            'from_tb' => 'items',
            'to_tb'   => '',
        ]);
        $res = $this->callController($ctrl, 'saveRelation');

        $this->assertSame('error',             $res['status']);
        $this->assertSame('parameter_missing', $res['code']);
    }

    public function testSaveRelationRejectsSelfLoop(): void
    {
        $ctrl = $this->makeController('config_ctrl', [], [
            'from_tb' => 'items',
            'to_tb'   => 'items',
        ]);
        $res = $this->callController($ctrl, 'saveRelation');

        $this->assertSame('error',                $res['status']);
        $this->assertSame('relation_self_loop',   $res['code']);
    }

    public function testSaveRelationRejectsDuplicatePair(): void
    {
        // First create
        $ctrl = $this->makeController('config_ctrl', [], [
            'from_tb' => 'items',
            'to_tb'   => 'tags',
            'fld'     => [],
        ]);
        $this->callController($ctrl, 'saveRelation');

        // Second create with the same pair → error
        $ctrl2 = $this->makeController('config_ctrl', [], [
            'from_tb' => 'items',
            'to_tb'   => 'tags',
            'fld'     => [],
        ]);
        $res = $this->callController($ctrl2, 'saveRelation');

        $this->assertSame('error',                    $res['status']);
        $this->assertSame('relation_already_exists',  $res['code']);
    }

    public function testSaveRelationRejectsDuplicateReverseOrder(): void
    {
        // Create items→tags
        $ctrl = $this->makeController('config_ctrl', [], [
            'from_tb' => 'items',
            'to_tb'   => 'tags',
            'fld'     => [],
        ]);
        $this->callController($ctrl, 'saveRelation');

        // Try to create tags→items (canonical form is the same pair)
        $ctrl2 = $this->makeController('config_ctrl', [], [
            'from_tb' => 'tags',
            'to_tb'   => 'items',
            'fld'     => [],
        ]);
        $res = $this->callController($ctrl2, 'saveRelation');

        $this->assertSame('error',                   $res['status']);
        $this->assertSame('relation_already_exists', $res['code']);
    }

    public function testSaveRelationRequiresSuperAdmin(): void
    {
        $this->setPrivilege(11);
        $ctrl = $this->makeController('config_ctrl', [], [
            'from_tb' => 'items',
            'to_tb'   => 'tags',
        ]);
        $res = $this->callController($ctrl, 'saveRelation');
        $this->setPrivilege(1);

        $this->assertSame('error', $res['status']);
        $this->assertSame('not_enough_privilege', $res['code']);
    }

    // ── saveRelation — update ─────────────────────────────────────────────

    public function testSaveRelationUpdateChangesFieldMapping(): void
    {
        // Create
        $ctrl = $this->makeController('config_ctrl', [], [
            'from_tb' => 'items',
            'to_tb'   => 'tags',
            'fld'     => [['my' => 'id', 'other' => 'id_link']],
        ]);
        $created = $this->callController($ctrl, 'saveRelation');
        $id = $created['id'];

        // Update: change fld
        $ctrl2 = $this->makeController('config_ctrl', ['id' => (string)$id], [
            'from_tb' => 'items',
            'to_tb'   => 'tags',
            'fld'     => [['my' => 'name', 'other' => 'label']],
        ]);
        $updated = $this->callController($ctrl2, 'saveRelation');

        $this->assertSame('success',        $updated['status']);
        $this->assertSame('relation_saved', $updated['code']);
        $this->assertSame($id,              $updated['id']);

        // Verify in DB
        $row = static::$db->query(
            'SELECT fld FROM bdus_cfg_relations WHERE id=?', [$id], 'read'
        );
        $fld = json_decode($row[0]['fld'], true);
        $this->assertSame('name',  $fld[0]['my']);
        $this->assertSame('label', $fld[0]['other']);
    }

    // ── deleteRelation ────────────────────────────────────────────────────

    public function testDeleteRelationRemovesRow(): void
    {
        // Create
        $ctrl = $this->makeController('config_ctrl', [], [
            'from_tb' => 'items',
            'to_tb'   => 'tags',
            'fld'     => [],
        ]);
        $created = $this->callController($ctrl, 'saveRelation');
        $id = $created['id'];

        // Delete
        $ctrl2 = $this->makeController('config_ctrl', ['id' => (string)$id]);
        $res   = $this->callController($ctrl2, 'deleteRelation');

        $this->assertSame('success',          $res['status']);
        $this->assertSame('relation_deleted', $res['code']);

        // Verify gone
        $row = static::$db->query(
            'SELECT id FROM bdus_cfg_relations WHERE id=?', [$id], 'read'
        );
        $this->assertEmpty($row);
    }

    public function testDeleteRelationWithMissingId(): void
    {
        $ctrl = $this->makeController('config_ctrl');   // no 'id' in GET
        $res  = $this->callController($ctrl, 'deleteRelation');

        $this->assertSame('error',             $res['status']);
        $this->assertSame('parameter_missing', $res['code']);
    }

    public function testDeleteRelationWithNonExistentId(): void
    {
        $ctrl = $this->makeController('config_ctrl', ['id' => '99999']);
        $res  = $this->callController($ctrl, 'deleteRelation');

        $this->assertSame('error',     $res['status']);
        $this->assertSame('not_found', $res['code']);
    }

    public function testDeleteRelationRequiresSuperAdmin(): void
    {
        // Create first
        $ctrl = $this->makeController('config_ctrl', [], [
            'from_tb' => 'items',
            'to_tb'   => 'tags',
            'fld'     => [],
        ]);
        $created = $this->callController($ctrl, 'saveRelation');
        $id = $created['id'];

        $this->setPrivilege(11);
        $ctrl2 = $this->makeController('config_ctrl', ['id' => (string)$id]);
        $res   = $this->callController($ctrl2, 'deleteRelation');
        $this->setPrivilege(1);

        $this->assertSame('error', $res['status']);
        $this->assertSame('not_enough_privilege', $res['code']);

        // Row must still be there
        $row = static::$db->query(
            'SELECT id FROM bdus_cfg_relations WHERE id=?', [$id], 'read'
        );
        $this->assertNotEmpty($row);
    }
}
