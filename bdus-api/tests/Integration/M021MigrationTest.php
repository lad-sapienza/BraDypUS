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
 *   - plugin tables have is_plugin = 1 but no bdus_cfg_relations attachment yet
 *
 * After M021 runs, a bdus_cfg_relations row (from_tb=plugin/from_col='id_link')
 * must exist pointing at the parent, and the `plugin` key must be removed from
 * the parent's extra. There is no plugin_of column (dropped by
 * M036_DropPluginOfColumn) — the attachment lives only in bdus_cfg_relations.
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
        static::$manage->createTable('bdus_cfg_fields');
        static::$manage->createTable('bdus_cfg_relations');
    }

    protected function setUp(): void
    {
        static::$db->query('DELETE FROM bdus_cfg_tables', [], 'boolean');
        static::$db->query('DELETE FROM bdus_cfg_fields', [], 'boolean');
        static::$db->query('DELETE FROM bdus_cfg_relations', [], 'boolean');
        // Drop any ad-hoc physical tables created by the multi-tenant split tests
        // — twins first, since they hold a live FK to their parent table.
        foreach (['misure_us', 'misure_reperti', 'misure', 'us', 'reperti'] as $tb) {
            static::$db->exec("DROP TABLE IF EXISTS \"{$tb}\"");
        }
    }

    // ── helpers ───────────────────────────────────────────────────────────────

    private function insertTable(string $name, int $isPlugin = 0, ?string $extra = null): void
    {
        static::$db->query(
            'INSERT INTO bdus_cfg_tables (name, is_plugin, sort, extra)
             VALUES (?, ?, 0, ?)',
            [$name, $isPlugin, $extra],
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

    /** Returns the single from_tb=$pluginTb/from_col='id_link' relation row, or null. */
    private function getRelation(string $pluginTb): ?array
    {
        $rows = static::$db->query(
            "SELECT * FROM bdus_cfg_relations WHERE from_tb = ? AND from_col = 'id_link'",
            [$pluginTb],
            'read'
        );
        return $rows[0] ?? null;
    }

    private function insertRelation(string $pluginTb, string $parentTb): void
    {
        static::$db->query(
            'INSERT INTO bdus_cfg_relations (from_tb, from_col, to_tb, to_col, on_delete, on_update)
             VALUES (?, ?, ?, ?, ?, ?)',
            [$pluginTb, 'id_link', $parentTb, 'id', 'RESTRICT', 'CASCADE'],
            'boolean'
        );
    }

    private function migrate(): void
    {
        M021_FixPluginOf::run(static::$manage);
    }

    /** Creates a minimal physical table — the single-parent path only relates
     *  a plugin to a parent that physically exists (same caution the
     *  multi-tenant split already applies). */
    private function createPhysicalTable(string $name): void
    {
        static::$db->exec("CREATE TABLE \"{$name}\" (id INTEGER PRIMARY KEY AUTOINCREMENT)");
    }

    // ── tests ─────────────────────────────────────────────────────────────────

    public function testSetsRelationOnPluginTable(): void
    {
        $this->createPhysicalTable('us');
        $this->insertTable('us', 0, '{"plugin":["attivita"]}');
        $this->insertTable('attivita', 0);

        $this->migrate();

        $child = $this->getRow('attivita');
        $this->assertSame(1, (int) $child['is_plugin']);

        $rel = $this->getRelation('attivita');
        $this->assertNotNull($rel);
        $this->assertSame('us', $rel['to_tb']);
        $this->assertSame('id', $rel['to_col']);
    }

    public function testSetsRelationForMultiplePlugins(): void
    {
        $this->createPhysicalTable('us');
        $this->insertTable('us', 0, '{"plugin":["attivita","materiali"]}');
        $this->insertTable('attivita',  0);
        $this->insertTable('materiali', 0);

        $this->migrate();

        $this->assertSame('us', $this->getRelation('attivita')['to_tb']);
        $this->assertSame('us', $this->getRelation('materiali')['to_tb']);
    }

    public function testRemovesPluginKeyFromExtra(): void
    {
        $this->insertTable('us', 0, '{"plugin":["attivita"],"rs":"id"}');
        $this->insertTable('attivita', 1);

        $this->migrate();

        $parent = $this->getRow('us');
        $extra  = json_decode($parent['extra'] ?? '{}', true);

        $this->assertArrayNotHasKey('plugin', $extra);
        $this->assertArrayHasKey('rs', $extra);
        $this->assertSame('id', $extra['rs']);
    }

    public function testSetsExtraToNullWhenPluginWasOnlyKey(): void
    {
        $this->insertTable('us', 0, '{"plugin":["attivita"]}');
        $this->insertTable('attivita', 1);

        $this->migrate();

        $parent = $this->getRow('us');
        $this->assertNull($parent['extra']);
    }

    public function testDoesNotOverwriteExistingRelation(): void
    {
        $this->insertTable('us',       0, '{"plugin":["attivita"]}');
        $this->insertTable('periodi',  0);
        $this->insertTable('attivita', 1); // already attached elsewhere
        $this->insertRelation('attivita', 'periodi'); // already set correctly

        $this->migrate();

        $rel = $this->getRelation('attivita');
        $this->assertSame('periodi', $rel['to_tb']);
    }

    public function testIdempotent(): void
    {
        $this->createPhysicalTable('us');
        $this->insertTable('us', 0, '{"plugin":["attivita"]}');
        $this->insertTable('attivita', 0);

        $this->migrate();
        $this->migrate(); // second run must not error or corrupt data

        $this->assertSame('us', $this->getRelation('attivita')['to_tb']);
        $rows = static::$db->query(
            "SELECT * FROM bdus_cfg_relations WHERE from_tb = 'attivita'",
            [], 'read'
        );
        $this->assertCount(1, $rows); // no duplicate relation row
    }

    public function testNoOpWhenExtraHasNoPlugin(): void
    {
        $this->insertTable('us', 0, '{"rs":"id"}');
        $this->insertTable('attivita', 1);

        $this->migrate();

        $this->assertNull($this->getRelation('attivita'));
    }

    public function testNoOpWhenAlreadyCorrect(): void
    {
        $this->insertTable('us', 0);
        $this->insertTable('attivita', 1);
        $this->insertRelation('attivita', 'us');

        $this->migrate();

        $this->assertSame('us', $this->getRelation('attivita')['to_tb']);
    }

    // ── multi-tenant split (same physical plugin table shared by >1 parent) ───

    private function insertField(string $tb, string $name, string $type, string $dbType, int $sort): void
    {
        static::$db->query(
            'INSERT INTO bdus_cfg_fields (table_name, name, label, type, db_type, sort) VALUES (?,?,?,?,?,?)',
            [$tb, $name, ucfirst($name), $type, $dbType, $sort],
            'boolean'
        );
    }

    /**
     * v4 scenario: "misure" is declared as a plugin of BOTH "us" and "reperti"
     * (shared physical table, rows discriminated by table_link) — exactly the
     * case M021's old single-parent-wins logic used to silently drop data for.
     */
    private function setUpSharedPluginScenario(): void
    {
        static::$db->execInTransaction('CREATE TABLE us (id INTEGER PRIMARY KEY AUTOINCREMENT)');
        static::$db->execInTransaction('CREATE TABLE reperti (id INTEGER PRIMARY KEY AUTOINCREMENT)');
        // Rows matching the id_link values used below — the twin's id_link FK
        // (added by createMinimalTable) requires them to actually exist.
        static::$db->execInTransaction('INSERT INTO us (id) VALUES (10), (11)');
        static::$db->execInTransaction('INSERT INTO reperti (id) VALUES (20)');
        static::$db->execInTransaction(
            'CREATE TABLE misure (
                id INTEGER PRIMARY KEY AUTOINCREMENT,
                table_link TEXT NOT NULL,
                id_link INTEGER NOT NULL,
                valore TEXT
            )'
        );

        $this->insertTable('us', 0, '{"plugin":["misure"]}');
        $this->insertTable('reperti', 0, '{"plugin":["misure"]}');
        $this->insertTable('misure', 1);

        $this->insertField('misure', 'id', 'text', 'INTEGER', 0);
        $this->insertField('misure', 'table_link', 'text', 'TEXT', 1);
        $this->insertField('misure', 'id_link', 'text', 'INTEGER', 2);
        $this->insertField('misure', 'valore', 'text', 'TEXT', 3);

        static::$db->execInTransaction("INSERT INTO misure (table_link, id_link, valore) VALUES ('us', 10, 'altezza 3cm')");
        static::$db->execInTransaction("INSERT INTO misure (table_link, id_link, valore) VALUES ('us', 11, 'larghezza 5cm')");
        static::$db->execInTransaction("INSERT INTO misure (table_link, id_link, valore) VALUES ('reperti', 20, 'peso 12g')");
    }

    public function testSplitsSharedPluginTableIntoOneTwinPerParent(): void
    {
        $this->setUpSharedPluginScenario();

        $this->migrate();

        // Original shared table — physically and in config — is gone.
        $this->assertEmpty($this->getRow('misure'));
        $this->assertFalse(static::$manage->tableExists('misure'));

        // Twins registered, each attached to exactly one parent via a relation.
        $usTwin = $this->getRow('misure_us');
        $this->assertSame(1, (int) $usTwin['is_plugin']);
        $this->assertSame('us', $this->getRelation('misure_us')['to_tb']);

        $repertiTwin = $this->getRow('misure_reperti');
        $this->assertSame(1, (int) $repertiTwin['is_plugin']);
        $this->assertSame('reperti', $this->getRelation('misure_reperti')['to_tb']);

        // Data correctly redistributed, original row ids preserved.
        $usRows = static::$db->query('SELECT id, id_link, valore FROM misure_us ORDER BY id', [], 'read');
        $this->assertCount(2, $usRows);
        $this->assertSame(10, (int) $usRows[0]['id_link']);
        $this->assertSame('altezza 3cm', $usRows[0]['valore']);
        $this->assertSame(11, (int) $usRows[1]['id_link']);

        $repertiRows = static::$db->query('SELECT id, id_link, valore FROM misure_reperti', [], 'read');
        $this->assertCount(1, $repertiRows);
        $this->assertSame(20, (int) $repertiRows[0]['id_link']);
        $this->assertSame('peso 12g', $repertiRows[0]['valore']);

        // Field config copied to each twin.
        $twinFields = array_column(
            static::$db->query('SELECT name FROM bdus_cfg_fields WHERE table_name=?', ['misure_us'], 'read'),
            'name'
        );
        $this->assertEqualsCanonicalizing(['id', 'table_link', 'id_link', 'valore'], $twinFields);

        // extra.plugin removed from both parents, same as the single-parent case.
        $this->assertNull($this->getRow('us')['extra']);
        $this->assertNull($this->getRow('reperti')['extra']);
    }

    /**
     * Real v4 data can contain a plugin row whose id_link no longer resolves
     * in the parent (e.g. the parent record was deleted without cascading its
     * plugin rows). The split must not let that abort the whole migration —
     * the row is still copied, just without a live FK on that twin table.
     */
    public function testSplitToleratesOrphanedIdLink(): void
    {
        $this->setUpSharedPluginScenario();
        // Row referencing a "us" id that doesn't exist (12, vs. the seeded 10/11).
        static::$db->execInTransaction("INSERT INTO misure (table_link, id_link, valore) VALUES ('us', 12, 'orfano')");

        $this->migrate();

        $usRows = static::$db->query('SELECT id_link FROM misure_us ORDER BY id', [], 'read');
        $this->assertCount(3, $usRows); // orphan row preserved, not dropped
        $this->assertSame(12, (int) $usRows[2]['id_link']);
    }

    public function testSplitIsIdempotent(): void
    {
        $this->setUpSharedPluginScenario();

        $this->migrate();
        $this->migrate(); // second run must not error, duplicate twins, or re-copy data

        $usRows = static::$db->query('SELECT id FROM misure_us', [], 'read');
        $this->assertCount(2, $usRows);

        $tableRows = static::$db->query(
            "SELECT name FROM bdus_cfg_tables WHERE name LIKE 'misure%'",
            [],
            'read'
        );
        $this->assertCount(2, $tableRows); // misure_us + misure_reperti, no duplicates

        $relRows = static::$db->query(
            "SELECT * FROM bdus_cfg_relations WHERE from_tb LIKE 'misure%'",
            [],
            'read'
        );
        $this->assertCount(2, $relRows); // no duplicate relation rows either
    }
}
