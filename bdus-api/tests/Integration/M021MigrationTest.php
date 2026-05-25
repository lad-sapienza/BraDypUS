<?php

namespace Tests\Integration;

use PHPUnit\Framework\TestCase;
use DB\DB;
use DB\System\Manage;
use DB\System\Migrations\M021_FixPluginOf;
use Monolog\Logger;
use Monolog\Handler\NullHandler;

/**
 * Tests for M021_FixPluginOf.
 *
 * Simulates a database that was migrated from v4 via M011:
 *   - parent table has extra JSON containing a `plugin` array
 *   - plugin tables have is_plugin = 1 but plugin_of = NULL
 *
 * After M021 runs, plugin_of must be set on all plugin table rows,
 * and the `plugin` key must be removed from extra.
 */
class M021MigrationTest extends TestCase
{
    private static DB     $db;
    private static Manage $manage;

    public static function setUpBeforeClass(): void
    {
        $log = new Logger('test');
        $log->pushHandler(new NullHandler());

        static::$db = new DB('test_m021', ['db_engine' => 'sqlite', 'db_path' => ':memory:']);
        static::$db->setLog($log);
        static::$manage = new Manage(static::$db);
        static::$manage->createTable('bdus_cfg_tables');
    }

    protected function setUp(): void
    {
        static::$db->query('DELETE FROM bdus_cfg_tables', [], 'boolean');
    }

    // ── helpers ───────────────────────────────────────────────────────────────

    private function insertTable(string $name, int $isPlugin = 0, ?string $pluginOf = null, ?string $extra = null): void
    {
        static::$db->query(
            'INSERT INTO bdus_cfg_tables (name, is_plugin, plugin_of, sort, extra)
             VALUES (?, ?, ?, 0, ?)',
            [$name, $isPlugin, $pluginOf, $extra],
            'boolean'
        );
    }

    private function getRow(string $name): array
    {
        $rows = static::$db->query(
            'SELECT * FROM bdus_cfg_tables WHERE name = ?',
            [$name],
            'read'
        );
        return $rows[0] ?? [];
    }

    private function migrate(): void
    {
        M021_FixPluginOf::run(static::$manage);
    }

    // ── tests ─────────────────────────────────────────────────────────────────

    public function testSetsPluginOfOnPluginTable(): void
    {
        $this->insertTable('us', 0, null, '{"plugin":["attivita"]}');
        $this->insertTable('attivita', 1, null, null);

        $this->migrate();

        $child = $this->getRow('attivita');
        $this->assertSame('us', $child['plugin_of']);
        $this->assertSame(1, (int)$child['is_plugin']);
    }

    public function testSetsPluginOfForMultiplePlugins(): void
    {
        $this->insertTable('us', 0, null, '{"plugin":["attivita","materiali"]}');
        $this->insertTable('attivita',  1, null, null);
        $this->insertTable('materiali', 1, null, null);

        $this->migrate();

        $this->assertSame('us', $this->getRow('attivita')['plugin_of']);
        $this->assertSame('us', $this->getRow('materiali')['plugin_of']);
    }

    public function testRemovesPluginKeyFromExtra(): void
    {
        $this->insertTable('us', 0, null, '{"plugin":["attivita"],"rs":"id"}');
        $this->insertTable('attivita', 1, null, null);

        $this->migrate();

        $parent = $this->getRow('us');
        $extra  = json_decode($parent['extra'] ?? '{}', true);

        $this->assertArrayNotHasKey('plugin', $extra);
        $this->assertArrayHasKey('rs', $extra);
        $this->assertSame('id', $extra['rs']);
    }

    public function testSetsExtraToNullWhenPluginWasOnlyKey(): void
    {
        $this->insertTable('us', 0, null, '{"plugin":["attivita"]}');
        $this->insertTable('attivita', 1, null, null);

        $this->migrate();

        $parent = $this->getRow('us');
        $this->assertNull($parent['extra']);
    }

    public function testDoesNotOverwriteExistingPluginOf(): void
    {
        $this->insertTable('us',       0, null,     '{"plugin":["attivita"]}');
        $this->insertTable('periodi',  0, null,     null);
        $this->insertTable('attivita', 1, 'periodi', null); // already set correctly

        $this->migrate();

        $this->assertSame('periodi', $this->getRow('attivita')['plugin_of']);
    }

    public function testIdempotent(): void
    {
        $this->insertTable('us', 0, null, '{"plugin":["attivita"]}');
        $this->insertTable('attivita', 1, null, null);

        $this->migrate();
        $this->migrate(); // second run must not error or corrupt data

        $this->assertSame('us', $this->getRow('attivita')['plugin_of']);
    }

    public function testNoOpWhenExtraHasNoPlugin(): void
    {
        $this->insertTable('us', 0, null, '{"rs":"id"}');
        $this->insertTable('attivita', 1, null, null);

        $this->migrate();

        $this->assertNull($this->getRow('attivita')['plugin_of']);
    }

    public function testNoOpWhenTablesAlreadyCorrect(): void
    {
        $this->insertTable('us', 0, null, null);
        $this->insertTable('attivita', 1, 'us', null);

        $this->migrate();

        $this->assertSame('us', $this->getRow('attivita')['plugin_of']);
    }
}
