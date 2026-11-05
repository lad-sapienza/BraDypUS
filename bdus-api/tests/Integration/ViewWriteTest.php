<?php

namespace Tests\Integration;

use Adbar\Dot;
use Auth\Authorization;
use Config\Config;
use Config\ToDB;
use Tests\Support\BdusTestCase;

/**
 * #53 phase 3 — writing through SQL views: read-only views refuse every write
 * with `view_read_only`, writable views work and return the right new id, and
 * the structural operations that make no sense on a view are refused.
 */
class ViewWriteTest extends BdusTestCase
{
    private const REC  = 'Bdus\\Controllers\\Record';
    private const CFG  = 'Bdus\\Controllers\\Config';

    protected static function createSchema(): void
    {
        parent::createSchema();
        $db = static::$db;
        $db->exec('CREATE TABLE vw_base (id INTEGER PRIMARY KEY AUTOINCREMENT, creator INTEGER, name TEXT)');
        // another table consuming rowids, so a stale lastInsertId would be wrong
        $db->exec('CREATE TABLE vw_noise (id INTEGER PRIMARY KEY AUTOINCREMENT, x TEXT)');
        $db->exec("INSERT INTO vw_noise (x) VALUES ('a'),('b'),('c'),('d'),('e')");
        $db->exec('CREATE VIEW vw_ro AS SELECT id, creator, name FROM vw_base');
        $db->exec('CREATE VIEW vw_rw AS SELECT id, creator, name FROM vw_base');
        $db->exec('CREATE TRIGGER vw_rw_i INSTEAD OF INSERT ON vw_rw
                   BEGIN INSERT INTO vw_base (id, creator, name) VALUES (NEW.id, NEW.creator, NEW.name); END');
        $db->exec('CREATE TRIGGER vw_rw_u INSTEAD OF UPDATE ON vw_rw
                   BEGIN UPDATE vw_base SET name = NEW.name WHERE id = OLD.id; END');
        $db->exec('CREATE TRIGGER vw_rw_d INSTEAD OF DELETE ON vw_rw
                   BEGIN DELETE FROM vw_base WHERE id = OLD.id; END');
    }

    public static function setUpBeforeClass(): void
    {
        parent::setUpBeforeClass();
        (new \DB\System\Manage(static::$db))->createTable('bdus_cfg_tables');
        (new \DB\System\Manage(static::$db))->createTable('bdus_cfg_fields');
        // Config is DB-backed only once bdus_cfg_tables has a row; without it
        // it would fall back to the fixture files and write into them.
        ToDB::upsertTable(static::$db, ['name' => 'existing', 'label' => 'Existing']);
        static::$cfg = new Config(new Dot(), __DIR__ . '/../fixtures/cfg/', static::$db);

        foreach (['vw_ro', 'vw_rw'] as $v) {
            $res = \Config\Activate::activate(static::$db, static::$cfg, $v);
            if ($res['status'] !== 'success') {
                throw new \RuntimeException("cannot activate $v: " . json_encode($res));
            }
        }
        Authorization::setReadOnlyTables(['vw_ro']);
    }

    public static function tearDownAfterClass(): void
    {
        Authorization::setReadOnlyTables([]);
        parent::tearDownAfterClass();
    }

    private function save(string $tb, array $core, ?int $id = null): array
    {
        $ctrl = $this->makeController(self::REC, [], ['tb' => $tb, 'id' => $id, 'core' => $core, 'plugins' => []]);
        return $this->callController($ctrl, 'saveRecord');
    }

    public function testActivationRecordedTheWriteLevel(): void
    {
        $this->assertSame('none',    static::$cfg->get('tables.vw_ro.view_writable'));
        $this->assertSame('trigger', static::$cfg->get('tables.vw_rw.view_writable'));
    }

    public function testReadOnlyViewRefusesCreate(): void
    {
        $res = $this->save('vw_ro', ['name' => 'nope']);
        $this->assertSame('view_read_only', $res['code']);
        $this->assertSame(0, (int) static::$db->query('SELECT COUNT(*) AS n FROM vw_base', [], 'read')[0]['n']);
    }

    public function testWritableViewCreatesAndReturnsTheRightId(): void
    {
        $res = $this->save('vw_rw', ['name' => 'first']);
        $this->assertSame('success', $res['status'], json_encode($res));
        // 5 rows in vw_noise: a stale last_insert_rowid() would be 5, not 1.
        $this->assertSame(1, (int) $res['id']);
        $res = $this->save('vw_rw', ['name' => 'second']);
        $this->assertSame(2, (int) $res['id']);

        $row = static::$db->query('SELECT name FROM vw_base WHERE id = 2', [], 'read');
        $this->assertSame('second', $row[0]['name']);
    }

    public function testWritableViewUpdates(): void
    {
        $res = $this->save('vw_rw', ['name' => 'renamed'], 1);
        $this->assertSame('success', $res['status'], json_encode($res));
        $this->assertSame('renamed', static::$db->query('SELECT name FROM vw_base WHERE id = 1', [], 'read')[0]['name']);
    }

    public function testAbilitiesAreOffForReadOnlyViewOnly(): void
    {
        $this->assertFalse(Authorization::can('add_new', null, 'vw_ro'));
        $this->assertFalse(Authorization::can('edit', null, 'vw_ro'));
        $this->assertFalse(Authorization::can('delete', null, 'vw_ro'));
        $this->assertTrue(Authorization::can('read', null, 'vw_ro'), 'reading stays allowed');
        $this->assertTrue(Authorization::can('edit', null, 'vw_rw'));
        $this->assertSame(
            ['create' => false, 'read' => true, 'update' => false, 'delete' => false],
            Authorization::tableAbilities('vw_ro')
        );
    }

    public function testStructuralOperationsAreRefusedOnViews(): void
    {
        $calls = [
            ['saveIndex',        ['tb' => 'vw_rw'], ['name' => 'i1', 'columns' => ['name']]],
            ['saveRelation',     [],                ['from_tb' => 'vw_rw', 'from_col' => 'creator', 'to_tb' => 'items', 'to_col' => 'id']],
            ['saveRelation',     [],                ['from_tb' => 'items', 'from_col' => 'cat_ref', 'to_tb' => 'vw_rw', 'to_col' => 'id']],
            ['activateFuzzyDate', ['tb' => 'vw_rw'], []],
            ['activateOsteology', ['tb' => 'vw_rw'], []],
            ['activatePleiades',  ['tb' => 'vw_rw'], []],
            ['activateRadiocarbon', ['tb' => 'vw_rw'], []],
        ];
        foreach ($calls as [$method, $get, $post]) {
            $res = $this->callController($this->makeController(self::CFG, $get, $post), $method);
            $this->assertSame('view_not_alterable', $res['code'] ?? null, $method);
        }
    }

    public function testWritableViewDeletes(): void
    {
        $res = $this->save('vw_rw', ['name' => 'to be erased']);
        $id  = (int) $res['id'];
        $this->assertGreaterThan(0, $id);

        $ctrl = $this->makeController(self::REC, ['tb' => 'vw_rw', 'id' => $id], []);
        $del  = $this->callController($ctrl, 'erase');
        $this->assertSame('all_record_deleted', $del['code'], json_encode($del));
        $this->assertSame([], static::$db->query('SELECT id FROM vw_base WHERE id = ?', [$id], 'read'));
    }

    public function testReadOnlyViewCannotBeErased(): void
    {
        static::$db->query("INSERT INTO vw_base (name) VALUES ('keep')", [], 'boolean');
        $id   = (int) static::$db->query('SELECT MAX(id) AS m FROM vw_base', [], 'read')[0]['m'];
        $ctrl = $this->makeController(self::REC, ['tb' => 'vw_ro', 'id' => $id], []);
        $del  = $this->callController($ctrl, 'erase');
        $this->assertNotSame('all_record_deleted', $del['code']);
        $this->assertNotSame([], static::$db->query('SELECT id FROM vw_base WHERE id = ?', [$id], 'read'), 'the row is still there');
    }
}
