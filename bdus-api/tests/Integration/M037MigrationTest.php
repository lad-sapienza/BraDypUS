<?php

namespace Tests\Integration;

use PHPUnit\Framework\TestCase;
use DB\DB;
use DB\System\Manage;
use DB\System\Migrations\M037_SplitMultiTenantPluginData;
use Monolog\Logger;
use Monolog\Handler\NullHandler;

/**
 * Tests for M037_SplitMultiTenantPluginData.
 *
 * Simulates an already-migrated app: a plugin table already correctly
 * attached to one parent via bdus_cfg_relations, but whose physical data
 * still contains rows belonging to other parents (a v4 multi-tenant table
 * that M021's old, buggy split never caught — real case found in the `paths`
 * app's m_biblio/m_shelfmarks). There is no plugin_of column at this point
 * in the chain (dropped by M036) — only bdus_cfg_relations + table_link.
 */
class M037MigrationTest extends TestCase
{
    private static DB     $db;
    private static Manage $manage;

    public static function setUpBeforeClass(): void
    {
        $log = new Logger('test');
        $log->pushHandler(new NullHandler());

        static::$db = new DB('test_m037', ['db_engine' => 'sqlite', 'db_path' => ':memory:']);
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
        foreach (['m_biblio_places', 'm_biblio_titles', 'm_biblio', 'manuscripts', 'places', 'titles'] as $tb) {
            static::$db->exec("DROP TABLE IF EXISTS \"{$tb}\"");
        }
    }

    // ── helpers ───────────────────────────────────────────────────────────────

    private function insertTable(string $name, int $isPlugin = 0): void
    {
        static::$db->query(
            'INSERT INTO bdus_cfg_tables (name, is_plugin, sort) VALUES (?, ?, 0)',
            [$name, $isPlugin],
            'boolean'
        );
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

    private function insertField(string $tb, string $name, string $type, string $dbType, int $sort): void
    {
        static::$db->query(
            'INSERT INTO bdus_cfg_fields (table_name, name, label, type, db_type, sort) VALUES (?,?,?,?,?,?)',
            [$tb, $name, ucfirst($name), $type, $dbType, $sort],
            'boolean'
        );
    }

    private function getRow(string $name): array
    {
        $rows = static::$db->query('SELECT * FROM bdus_cfg_tables WHERE name = ?', [$name], 'read');
        return $rows[0] ?? [];
    }

    private function getRelation(string $pluginTb): ?array
    {
        $rows = static::$db->query(
            "SELECT * FROM bdus_cfg_relations WHERE from_tb = ? AND from_col = 'id_link'",
            [$pluginTb],
            'read'
        );
        return $rows[0] ?? null;
    }

    private function migrate(): void
    {
        M037_SplitMultiTenantPluginData::run(static::$manage);
    }

    /**
     * m_biblio is already attached to "manuscripts" (the majority tenant),
     * but its physical data also has rows for "places" and "titles" — mirrors
     * the real paths app's m_biblio problem.
     */
    private function setUpSharedPluginScenario(): void
    {
        static::$db->execInTransaction('CREATE TABLE manuscripts (id INTEGER PRIMARY KEY AUTOINCREMENT)');
        static::$db->execInTransaction('CREATE TABLE places (id INTEGER PRIMARY KEY AUTOINCREMENT)');
        static::$db->execInTransaction('CREATE TABLE titles (id INTEGER PRIMARY KEY AUTOINCREMENT)');
        static::$db->execInTransaction('INSERT INTO manuscripts (id) VALUES (1), (2)');
        static::$db->execInTransaction('INSERT INTO places (id) VALUES (10)');
        static::$db->execInTransaction('INSERT INTO titles (id) VALUES (20), (21)');
        static::$db->execInTransaction(
            'CREATE TABLE m_biblio (
                id INTEGER PRIMARY KEY AUTOINCREMENT,
                table_link TEXT NOT NULL,
                id_link INTEGER NOT NULL,
                citation TEXT
            )'
        );

        $this->insertTable('manuscripts', 0);
        $this->insertTable('places', 0);
        $this->insertTable('titles', 0);
        $this->insertTable('m_biblio', 1);
        $this->insertRelation('m_biblio', 'manuscripts'); // already correctly attached

        $this->insertField('m_biblio', 'id', 'text', 'INTEGER', 0);
        $this->insertField('m_biblio', 'table_link', 'text', 'TEXT', 1);
        $this->insertField('m_biblio', 'id_link', 'text', 'INTEGER', 2);
        $this->insertField('m_biblio', 'citation', 'text', 'TEXT', 3);

        static::$db->execInTransaction("INSERT INTO m_biblio (table_link, id_link, citation) VALUES ('manuscripts', 1, 'ms citation 1')");
        static::$db->execInTransaction("INSERT INTO m_biblio (table_link, id_link, citation) VALUES ('manuscripts', 2, 'ms citation 2')");
        static::$db->execInTransaction("INSERT INTO m_biblio (table_link, id_link, citation) VALUES ('places', 10, 'place citation')");
        static::$db->execInTransaction("INSERT INTO m_biblio (table_link, id_link, citation) VALUES ('titles', 20, 'title citation 1')");
        static::$db->execInTransaction("INSERT INTO m_biblio (table_link, id_link, citation) VALUES ('titles', 21, 'title citation 2')");
    }

    // ── tests ─────────────────────────────────────────────────────────────────

    public function testPeelsOffExtraTenantsIntoTwins(): void
    {
        $this->setUpSharedPluginScenario();

        $this->migrate();

        // Original table survives, untouched name/relation, only its own tenant's rows.
        $this->assertTrue(static::$manage->tableExists('m_biblio'));
        $this->assertSame('manuscripts', $this->getRelation('m_biblio')['to_tb']);
        $msRows = static::$db->query('SELECT id_link, citation FROM m_biblio ORDER BY id', [], 'read');
        $this->assertCount(2, $msRows);
        $this->assertSame(1, (int) $msRows[0]['id_link']);
        $this->assertSame(2, (int) $msRows[1]['id_link']);

        // Extra tenants peeled off into their own twins.
        $placesTwin = $this->getRow('m_biblio_places');
        $this->assertSame(1, (int) $placesTwin['is_plugin']);
        $this->assertSame('places', $this->getRelation('m_biblio_places')['to_tb']);
        $placesRows = static::$db->query('SELECT id_link, citation FROM m_biblio_places', [], 'read');
        $this->assertCount(1, $placesRows);
        $this->assertSame(10, (int) $placesRows[0]['id_link']);
        $this->assertSame('place citation', $placesRows[0]['citation']);

        $titlesTwin = $this->getRow('m_biblio_titles');
        $this->assertSame(1, (int) $titlesTwin['is_plugin']);
        $this->assertSame('titles', $this->getRelation('m_biblio_titles')['to_tb']);
        $titlesRows = static::$db->query('SELECT id_link FROM m_biblio_titles ORDER BY id', [], 'read');
        $this->assertCount(2, $titlesRows);
        $this->assertSame(20, (int) $titlesRows[0]['id_link']);
        $this->assertSame(21, (int) $titlesRows[1]['id_link']);

        // Field config copied to each twin.
        $twinFields = array_column(
            static::$db->query('SELECT name FROM bdus_cfg_fields WHERE table_name=?', ['m_biblio_places'], 'read'),
            'name'
        );
        $this->assertEqualsCanonicalizing(['id', 'table_link', 'id_link', 'citation'], $twinFields);
    }

    public function testToleratesOrphanedIdLink(): void
    {
        $this->setUpSharedPluginScenario();
        // A "places" row referencing an id that doesn't exist (99, vs. seeded 10).
        static::$db->execInTransaction("INSERT INTO m_biblio (table_link, id_link, citation) VALUES ('places', 99, 'orfano')");

        $this->migrate(); // must not throw

        $placesRows = static::$db->query('SELECT id_link FROM m_biblio_places ORDER BY id', [], 'read');
        $this->assertCount(2, $placesRows); // orphan row preserved, not dropped
    }

    public function testIsIdempotent(): void
    {
        $this->setUpSharedPluginScenario();

        $this->migrate();
        $this->migrate(); // second run must not error, duplicate twins, or re-split

        $tableRows = static::$db->query(
            "SELECT name FROM bdus_cfg_tables WHERE name LIKE 'm_biblio%'",
            [],
            'read'
        );
        $this->assertCount(3, $tableRows); // m_biblio + _places + _titles, no duplicates

        $relRows = static::$db->query(
            "SELECT * FROM bdus_cfg_relations WHERE from_tb LIKE 'm_biblio%'",
            [],
            'read'
        );
        $this->assertCount(3, $relRows);

        $placesRows = static::$db->query('SELECT id FROM m_biblio_places', [], 'read');
        $this->assertCount(1, $placesRows); // not re-copied
    }

    public function testNoOpWhenAlreadySingleTenant(): void
    {
        static::$db->execInTransaction('CREATE TABLE manuscripts (id INTEGER PRIMARY KEY AUTOINCREMENT)');
        static::$db->execInTransaction('INSERT INTO manuscripts (id) VALUES (1)');
        static::$db->execInTransaction(
            'CREATE TABLE m_biblio (
                id INTEGER PRIMARY KEY AUTOINCREMENT,
                table_link TEXT NOT NULL,
                id_link INTEGER NOT NULL
            )'
        );
        $this->insertTable('manuscripts', 0);
        $this->insertTable('m_biblio', 1);
        $this->insertRelation('m_biblio', 'manuscripts');
        static::$db->execInTransaction("INSERT INTO m_biblio (table_link, id_link) VALUES ('manuscripts', 1)");

        $this->migrate(); // must not throw or create anything

        $tableRows = static::$db->query("SELECT name FROM bdus_cfg_tables WHERE name LIKE 'm_biblio%'", [], 'read');
        $this->assertCount(1, $tableRows); // no twins created
    }

    public function testNoOpWhenNoParentConfigured(): void
    {
        static::$db->execInTransaction('CREATE TABLE places (id INTEGER PRIMARY KEY AUTOINCREMENT)');
        static::$db->execInTransaction(
            'CREATE TABLE m_biblio (
                id INTEGER PRIMARY KEY AUTOINCREMENT,
                table_link TEXT NOT NULL,
                id_link INTEGER NOT NULL
            )'
        );
        $this->insertTable('places', 0);
        $this->insertTable('m_biblio', 1);
        // No relation inserted — nothing configured yet.
        static::$db->execInTransaction("INSERT INTO m_biblio (table_link, id_link) VALUES ('places', 10)");

        $this->migrate(); // must not throw or guess

        $this->assertNull($this->getRelation('m_biblio'));
        $tableRows = static::$db->query("SELECT name FROM bdus_cfg_tables WHERE name LIKE 'm_biblio%'", [], 'read');
        $this->assertCount(1, $tableRows); // untouched
    }
}
