<?php

namespace Tests\Integration;

use Adbar\Dot;
use Auth\Authorization;
use Config\Config;
use Config\ToDB;
use Mcp\Context;
use Mcp\Server;
use Tests\Support\BdusTestCase;

/**
 * SQL views registered as tables, through MCP: they read like any table, a read-only
 * view refuses every write with `view_read_only`, a writable one creates, changes and
 * deletes like a table (same set-up as ViewWriteTest, the REST side).
 */
class McpViewsTest extends BdusTestCase
{
    protected static function createSchema(): void
    {
        parent::createSchema();
        $db = static::$db;
        $db->exec('CREATE TABLE vw_base (id INTEGER PRIMARY KEY AUTOINCREMENT, creator INTEGER, name TEXT)');
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
        ToDB::upsertTable(static::$db, ['name' => 'existing', 'label' => 'Existing']);
        static::$cfg = new Config(new Dot(), __DIR__ . '/../fixtures/cfg/', static::$db);

        foreach (['vw_ro', 'vw_rw'] as $v) {
            $res = \Config\Activate::activate(static::$db, static::$cfg, $v);
            if ($res['status'] !== 'success') {
                throw new \RuntimeException("cannot activate {$v}: " . json_encode($res));
            }
        }
        Authorization::setReadOnlyTables(['vw_ro']);
    }

    public static function tearDownAfterClass(): void
    {
        Authorization::setReadOnlyTables([]);
        parent::tearDownAfterClass();
    }

    protected function setUp(): void
    {
        static::$db->exec('DELETE FROM vw_base');
        static::$db->exec("INSERT INTO vw_base (id, creator, name) VALUES (1, NULL, 'seeded')");
        $this->setPrivilege(1);
        \Auth\Channel::set('mcp');
    }

    protected function tearDown(): void
    {
        \Auth\Channel::set(null);
    }

    private function tool(string $name, array $args): array
    {
        Authorization::setAccessProvider(fn() => \Auth\Access::forCurrentUser(static::$db, static::$cfg));
        $server = new Server(new Context(
            static::$db,
            static::$cfg,
            \Auth\Access::forCurrentUser(static::$db, static::$cfg),
            ['enabled' => true, 'write' => true, 'delete' => true, 'guide' => ''],
            static::$log,
            'test-signing-key'
        ));
        [, $body] = $server->handle(json_encode([
            'jsonrpc' => '2.0', 'id' => 1, 'method' => 'tools/call',
            'params'  => ['name' => $name, 'arguments' => $args],
        ]));
        $this->assertArrayHasKey('result', $body, json_encode($body));
        return $body['result']['structuredContent'] + ['__isError' => $body['result']['isError']];
    }

    private function change(string $tb, array $records): array
    {
        $check = $this->tool('records_validate', ['table' => $tb, 'records' => $records]);
        if (empty($check['valid'])) {
            return [$check, null];
        }
        return [$check, $this->tool('records_write', ['table' => $tb, 'records' => $records, 'validation_token' => $check['validation_token']])];
    }

    public function testViewsReadLikeTablesAndSayWhetherTheyAreWritable(): void
    {
        foreach (['vw_ro' => false, 'vw_rw' => true] as $view => $writable) {
            $rows = $this->tool('records_read', ['table' => $view]);
            $this->assertFalse($rows['__isError'], json_encode($rows));
            $this->assertSame(1, $rows['total']);

            $schema = $this->tool('schema', ['table' => $view]);
            $this->assertTrue($schema['is_view']);
            $this->assertSame($writable, $schema['writable'], $view);
        }
        $this->assertSame(1, $this->tool('aggregate', ['table' => 'vw_ro'])['groups'][0]['count']);
    }

    public function testReadOnlyViewRefusesEveryWrite(): void
    {
        [$create] = $this->change('vw_ro', [['core' => ['name' => 'nope']]]);
        $this->assertFalse($create['valid']);
        $this->assertSame('view_read_only', $create['results'][0]['code']);

        [$update, $written] = $this->change('vw_ro', [['id' => 1, 'core' => ['name' => 'nope']]]);
        $this->assertNull($written);
        $this->assertSame('view_read_only', $update['results'][0]['code']);

        $del = $this->tool('records_delete', ['table' => 'vw_ro', 'ids' => [1]]);
        $this->assertTrue($del['__isError']);
        $this->assertSame('not_enough_privilege', $del['code']);

        $this->assertSame('seeded', static::$db->query('SELECT name FROM vw_base WHERE id = 1', [], 'read')[0]['name']);
    }

    public function testWritableViewCreatesChangesAndDeletes(): void
    {
        [, $created] = $this->change('vw_rw', [['core' => ['name' => 'through the view']]]);
        $this->assertFalse($created['__isError'], json_encode($created));
        $id = $created['results'][0]['id'];
        // 5 rows in vw_noise: a stale last-insert id would be wrong
        $this->assertSame(2, $id);
        $this->assertSame('through the view', static::$db->query('SELECT name FROM vw_base WHERE id = ?', [$id], 'read')[0]['name']);

        [, $changed] = $this->change('vw_rw', [['id' => $id, 'core' => ['name' => 'renamed']]]);
        $this->assertFalse($changed['__isError'], json_encode($changed));
        $this->assertSame('renamed', static::$db->query('SELECT name FROM vw_base WHERE id = ?', [$id], 'read')[0]['name']);

        $del = $this->tool('records_delete', ['table' => 'vw_rw', 'ids' => [$id]]);
        $this->assertFalse($del['__isError'], json_encode($del));
        $this->assertSame(0, (int) static::$db->query('SELECT COUNT(*) AS n FROM vw_base WHERE id = ?', [$id], 'read')[0]['n']);
    }
}
