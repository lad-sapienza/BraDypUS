<?php

namespace Tests\Integration;

use Tests\Support\BdusTestCase;

/**
 * #44 — schema changes on a SQL view must be refused (`view_not_alterable`),
 * and the Inspect layer must tell views from tables.
 */
class ConfigViewGuardTest extends BdusTestCase
{
    private const VIEW = 'items_v';

    protected static function createSchema(): void
    {
        parent::createSchema();
        static::$db->execInTransaction('CREATE VIEW ' . self::VIEW . ' AS SELECT id, name FROM items');
    }

    public function testInspectDistinguishesViewsFromTables(): void
    {
        $i = new \DB\Inspect(static::$db);
        $this->assertTrue($i->isView(self::VIEW));
        $this->assertFalse($i->isView('items'));
        $this->assertFalse($i->isView('does_not_exist'));
        $this->assertTrue($i->tableExists(self::VIEW));
        $this->assertContains('items', $i->getAllTables());
        $this->assertNotContains(self::VIEW, $i->getAllTables());
    }

    public function testAddFieldOnViewIsRefused(): void
    {
        $ctrl = $this->makeController(\Bdus\Controllers\Config::class, ['tb' => self::VIEW], ['name' => 'x', 'type' => 'text', 'db_type' => 'TEXT']);
        $res  = $this->callController($ctrl, 'add_new_fld');
        $this->assertSame('view_not_alterable', $res['code']);
        $this->assertCount(2, (new \DB\Inspect(static::$db))->tableColumns(self::VIEW));
    }

    public function testDeleteColumnOnViewIsRefused(): void
    {
        $ctrl = $this->makeController(\Bdus\Controllers\Config::class, ['tb' => self::VIEW, 'fld' => 'name'], []);
        $this->assertSame('view_not_alterable', $this->callController($ctrl, 'delete_column')['code']);
    }

    public function testRenameColumnOnViewIsRefused(): void
    {
        $ctrl = $this->makeController(\Bdus\Controllers\Config::class, ['tb' => self::VIEW, 'fld' => 'name'], ['new_name' => 'nome']);
        $this->assertSame('view_not_alterable', $this->callController($ctrl, 'rename_column')['code']);
    }

    public function testRenameViewIsRefused(): void
    {
        $ctrl = $this->makeController(\Bdus\Controllers\Config::class, ['tb' => self::VIEW], ['new_name' => 'items_w']);
        $this->assertSame('view_not_alterable', $this->callController($ctrl, 'rename_tb')['code']);
        $this->assertTrue((new \DB\Inspect(static::$db))->isView(self::VIEW));
    }

    public function testDeleteTbOnViewKeepsTheView(): void
    {
        $ctrl = $this->makeController(\Bdus\Controllers\Config::class, ['tb' => self::VIEW], []);
        $this->assertSame('ok_cfg_tb_delete', $this->callController($ctrl, 'delete_tb')['code']);
        $this->assertTrue((new \DB\Inspect(static::$db))->isView(self::VIEW), 'view must survive unregistering');
    }
}
