<?php

namespace Tests\Integration;

use DB\System\Manage;
use Config\ToDB;
use Tests\Support\BdusTestCase;

/**
 * Integration tests for config_ctrl index endpoints:
 *   getIndexes, saveIndex, deleteIndex
 */
class ConfigIndexesCtrlTest extends BdusTestCase
{
    public static function setUpBeforeClass(): void
    {
        parent::setUpBeforeClass();

        $manage = new Manage(static::$db);
        $manage->createTable('bdus_cfg_tables');
        ToDB::upsertTable(static::$db, ['name' => 'items', 'label' => 'Items']);
    }

    public static function tearDownAfterClass(): void
    {
        static::$db->query('DELETE FROM bdus_cfg_indexes', [], 'boolean');
        parent::tearDownAfterClass();
    }

    protected function setUp(): void
    {
        static::$db->query('DELETE FROM bdus_cfg_indexes', [], 'boolean');
        // Drop any test indexes from the items table to keep things clean.
        foreach (['idx_test_name', 'idx_test_multi', 'idx_test_uniq'] as $idxName) {
            static::$db->exec("DROP INDEX IF EXISTS \"{$idxName}\"");
        }
    }

    // ── getIndexes ────────────────────────────────────────────────────────────

    public function testGetIndexesRequiresTb(): void
    {
        $ctrl = $this->makeController('Bdus\\Controllers\\Config');
        $res  = $this->callController($ctrl, 'getIndexes');

        $this->assertSame('error',             $res['status']);
        $this->assertSame('parameter_missing', $res['code']);
    }

    public function testGetIndexesReturnsEmptyWhenNone(): void
    {
        $ctrl = $this->makeController('Bdus\\Controllers\\Config', ['tb' => 'items']);
        $res  = $this->callController($ctrl, 'getIndexes');

        $this->assertSame('success', $res['status']);
        $this->assertSame([],        $res['data']);
    }

    public function testGetIndexesRequiresSuperAdmin(): void
    {
        $this->setPrivilege(11);
        $ctrl = $this->makeController('Bdus\\Controllers\\Config', ['tb' => 'items']);
        $res  = $this->callController($ctrl, 'getIndexes');
        $this->setPrivilege(1);

        $this->assertSame('error', $res['status']);
        $this->assertSame('not_enough_privilege', $res['code']);
    }

    // ── saveIndex ─────────────────────────────────────────────────────────────

    public function testSaveIndexCreatesIndexInDbAndConfig(): void
    {
        $ctrl = $this->makeController('Bdus\\Controllers\\Config', ['tb' => 'items'], [
            'name'    => 'idx_test_name',
            'columns' => ['name'],
            'is_unique'  => false,
        ]);
        $res = $this->callController($ctrl, 'saveIndex');

        $this->assertSame('success',     $res['status']);
        $this->assertSame('index_saved', $res['code']);
        $this->assertIsInt($res['id']);

        // Verify it appears in getIndexes
        $ctrl2 = $this->makeController('Bdus\\Controllers\\Config', ['tb' => 'items']);
        $list  = $this->callController($ctrl2, 'getIndexes');
        $found = array_filter($list['data'], fn($r) => $r['name'] === 'idx_test_name');
        $this->assertCount(1, $found);
        $row = array_values($found)[0];
        $this->assertSame(['name'], $row['columns']);
        $this->assertFalse($row['is_unique']);
    }

    public function testSaveIndexCreatesCompositeIndex(): void
    {
        $ctrl = $this->makeController('Bdus\\Controllers\\Config', ['tb' => 'items'], [
            'name'    => 'idx_test_multi',
            'columns' => ['name', 'status'],
        ]);
        $res = $this->callController($ctrl, 'saveIndex');
        $this->assertSame('success', $res['status']);
    }

    public function testSaveIndexCreatesUniqueIndex(): void
    {
        $ctrl = $this->makeController('Bdus\\Controllers\\Config', ['tb' => 'items'], [
            'name'    => 'idx_test_uniq',
            'columns' => ['email_addr'],
            'is_unique' => '1',
        ]);
        $res = $this->callController($ctrl, 'saveIndex');
        $this->assertSame('success', $res['status']);

        $ctrl2 = $this->makeController('Bdus\\Controllers\\Config', ['tb' => 'items']);
        $list  = $this->callController($ctrl2, 'getIndexes');
        $found = array_filter($list['data'], fn($r) => $r['name'] === 'idx_test_uniq');
        $row   = array_values($found)[0];
        $this->assertTrue($row['is_unique']);
    }

    public function testSaveIndexRejectsMissingParameters(): void
    {
        $ctrl = $this->makeController('Bdus\\Controllers\\Config', ['tb' => 'items'], [
            'name' => 'idx_test_name',
            // columns missing
        ]);
        $res = $this->callController($ctrl, 'saveIndex');

        $this->assertSame('error',             $res['status']);
        $this->assertSame('parameter_missing', $res['code']);
    }

    public function testSaveIndexRejectsDuplicateName(): void
    {
        $ctrl = $this->makeController('Bdus\\Controllers\\Config', ['tb' => 'items'], [
            'name' => 'idx_test_name', 'columns' => ['name'],
        ]);
        $this->callController($ctrl, 'saveIndex');

        $ctrl2 = $this->makeController('Bdus\\Controllers\\Config', ['tb' => 'items'], [
            'name' => 'idx_test_name', 'columns' => ['status'],
        ]);
        $res = $this->callController($ctrl2, 'saveIndex');

        $this->assertSame('error',                $res['status']);
        $this->assertSame('index_already_exists', $res['code']);
    }

    public function testSaveIndexRequiresSuperAdmin(): void
    {
        $this->setPrivilege(11);
        $ctrl = $this->makeController('Bdus\\Controllers\\Config', ['tb' => 'items'], [
            'name' => 'idx_test_name', 'columns' => ['name'],
        ]);
        $res = $this->callController($ctrl, 'saveIndex');
        $this->setPrivilege(1);

        $this->assertSame('error', $res['status']);
        $this->assertSame('not_enough_privilege', $res['code']);
    }

    // ── deleteIndex ───────────────────────────────────────────────────────────

    public function testDeleteIndexRemovesFromDbAndConfig(): void
    {
        // Create first
        $ctrl = $this->makeController('Bdus\\Controllers\\Config', ['tb' => 'items'], [
            'name' => 'idx_test_name', 'columns' => ['name'],
        ]);
        $created = $this->callController($ctrl, 'saveIndex');
        $id = $created['id'];

        // Delete
        $ctrl2 = $this->makeController('Bdus\\Controllers\\Config', ['tb' => 'items', 'id' => (string)$id]);
        $res   = $this->callController($ctrl2, 'deleteIndex');

        $this->assertSame('success',       $res['status']);
        $this->assertSame('index_deleted', $res['code']);

        // Verify gone from config
        $ctrl3 = $this->makeController('Bdus\\Controllers\\Config', ['tb' => 'items']);
        $list  = $this->callController($ctrl3, 'getIndexes');
        $found = array_filter($list['data'], fn($r) => $r['name'] === 'idx_test_name');
        $this->assertCount(0, $found);
    }

    public function testDeleteIndexWithMissingId(): void
    {
        $ctrl = $this->makeController('Bdus\\Controllers\\Config', ['tb' => 'items']);
        $res  = $this->callController($ctrl, 'deleteIndex');

        $this->assertSame('error',             $res['status']);
        $this->assertSame('parameter_missing', $res['code']);
    }

    public function testDeleteIndexWithNonExistentId(): void
    {
        $ctrl = $this->makeController('Bdus\\Controllers\\Config', ['tb' => 'items', 'id' => '99999']);
        $res  = $this->callController($ctrl, 'deleteIndex');

        $this->assertSame('error',     $res['status']);
        $this->assertSame('not_found', $res['code']);
    }

    public function testDeleteIndexRequiresSuperAdmin(): void
    {
        $ctrl = $this->makeController('Bdus\\Controllers\\Config', ['tb' => 'items'], [
            'name' => 'idx_test_name', 'columns' => ['name'],
        ]);
        $created = $this->callController($ctrl, 'saveIndex');
        $id = $created['id'];

        $this->setPrivilege(11);
        $ctrl2 = $this->makeController('Bdus\\Controllers\\Config', ['tb' => 'items', 'id' => (string)$id]);
        $res   = $this->callController($ctrl2, 'deleteIndex');
        $this->setPrivilege(1);

        $this->assertSame('error', $res['status']);
        $this->assertSame('not_enough_privilege', $res['code']);
    }
}
