<?php

namespace Tests\Unit;

use PHPUnit\Framework\TestCase;
use DB\DB;
use DB\Alter;
use Monolog\Logger;
use Monolog\Handler\NullHandler;

/**
 * Unit tests for the new FK and index operations added to DB\Alter.
 *
 * All tests use an in-memory SQLite database so they run without external
 * infrastructure.  MySQL / PostgreSQL behaviour is covered by the multi-engine
 * integration test suite; the cross-engine logic is thin enough that SQLite
 * coverage is sufficient for the shared contract.
 */
class AlterFkIndexTest extends TestCase
{
    private static DB    $db;
    private static Alter $alter;

    public static function setUpBeforeClass(): void
    {
        $log = new Logger('test');
        $log->pushHandler(new NullHandler());

        static::$db = new DB('alter_fk_test', ['db_engine' => 'sqlite', 'db_path' => ':memory:']);
        static::$db->setLog($log);
        static::$alter = new Alter(static::$db);
    }

    protected function setUp(): void
    {
        // Fresh parent + child tables for each test.
        static::$db->exec('DROP TABLE IF EXISTS "child"');
        static::$db->exec('DROP TABLE IF EXISTS "parent"');
        static::$db->exec(
            'CREATE TABLE "parent" (id INTEGER PRIMARY KEY AUTOINCREMENT, name TEXT)'
        );
        static::$db->exec(
            'CREATE TABLE "child" (id INTEGER PRIMARY KEY AUTOINCREMENT, parent_id INTEGER, label TEXT)'
        );
    }

    // ── hasForeignKey ─────────────────────────────────────────────────────────

    public function testHasForeignKeyReturnsFalseWhenAbsent(): void
    {
        $this->assertFalse(static::$alter->hasForeignKey('child', 'parent_id'));
    }

    // ── addForeignKey ─────────────────────────────────────────────────────────

    public function testAddForeignKeyCreatesConstraint(): void
    {
        $ok = static::$alter->addForeignKey('child', 'parent_id', 'parent', 'id');
        $this->assertTrue($ok);
        $this->assertTrue(static::$alter->hasForeignKey('child', 'parent_id'));
    }

    public function testAddForeignKeyIsIdempotent(): void
    {
        static::$alter->addForeignKey('child', 'parent_id', 'parent', 'id');
        // Second call must not throw and must return true.
        $ok = static::$alter->addForeignKey('child', 'parent_id', 'parent', 'id');
        $this->assertTrue($ok);
    }

    public function testAddForeignKeyPreservesExistingData(): void
    {
        static::$db->query("INSERT INTO parent (id, name) VALUES (1, 'P1')", [], 'boolean');
        static::$db->query("INSERT INTO child  (parent_id, label) VALUES (1, 'C1')", [], 'boolean');

        static::$alter->addForeignKey('child', 'parent_id', 'parent', 'id');

        $rows = static::$db->query('SELECT * FROM child', [], 'read') ?: [];
        $this->assertCount(1, $rows);
        $this->assertSame('C1', $rows[0]['label']);
    }

    // ── dropForeignKey ────────────────────────────────────────────────────────

    public function testDropForeignKeyRemovesConstraint(): void
    {
        static::$alter->addForeignKey('child', 'parent_id', 'parent', 'id');
        $this->assertTrue(static::$alter->hasForeignKey('child', 'parent_id'));

        $ok = static::$alter->dropForeignKey('child', 'parent_id');
        $this->assertTrue($ok);
        $this->assertFalse(static::$alter->hasForeignKey('child', 'parent_id'));
    }

    public function testDropForeignKeyIsIdempotent(): void
    {
        // No FK exists — must return true without throwing.
        $ok = static::$alter->dropForeignKey('child', 'parent_id');
        $this->assertTrue($ok);
    }

    public function testDropForeignKeyPreservesData(): void
    {
        static::$db->query("INSERT INTO parent (id, name) VALUES (1, 'P1')", [], 'boolean');
        static::$db->query("INSERT INTO child  (parent_id, label) VALUES (1, 'C1')", [], 'boolean');

        static::$alter->addForeignKey('child', 'parent_id', 'parent', 'id');
        static::$alter->dropForeignKey('child', 'parent_id');

        $rows = static::$db->query('SELECT * FROM child', [], 'read') ?: [];
        $this->assertCount(1, $rows);
    }

    // ── checkOrphans ──────────────────────────────────────────────────────────

    public function testCheckOrphansReturnsZeroWhenClean(): void
    {
        static::$db->query("INSERT INTO parent (id, name) VALUES (1, 'P1')", [], 'boolean');
        static::$db->query("INSERT INTO child  (parent_id) VALUES (1)", [], 'boolean');

        $cnt = static::$alter->checkOrphans('child', 'parent_id', 'parent', 'id');
        $this->assertSame(0, $cnt);
    }

    public function testCheckOrphansCountsOrphanRows(): void
    {
        // child rows with parent_id values that don't exist in parent
        static::$db->query("INSERT INTO parent (id, name) VALUES (1, 'P1')", [], 'boolean');
        static::$db->query("INSERT INTO child (parent_id) VALUES (1), (99), (100)", [], 'boolean');

        $cnt = static::$alter->checkOrphans('child', 'parent_id', 'parent', 'id');
        $this->assertSame(2, $cnt);
    }

    public function testCheckOrphansIgnoresNullValues(): void
    {
        // NULL parent_id must not be counted as an orphan
        static::$db->query("INSERT INTO child (parent_id) VALUES (NULL)", [], 'boolean');

        $cnt = static::$alter->checkOrphans('child', 'parent_id', 'parent', 'id');
        $this->assertSame(0, $cnt);
    }

    // ── createIndex ───────────────────────────────────────────────────────────

    public function testCreateIndexBuildsIndex(): void
    {
        $ok = static::$alter->createIndex('child', 'idx_child_label', ['label'], false);
        $this->assertTrue($ok);

        $rows = static::$db->query(
            "SELECT name FROM sqlite_master WHERE type='index' AND name='idx_child_label'",
            [], 'read'
        ) ?: [];
        $this->assertNotEmpty($rows);
    }

    public function testCreateUniqueIndexBuildsIndex(): void
    {
        $ok = static::$alter->createIndex('child', 'idx_child_label_uniq', ['label'], true);
        $this->assertTrue($ok);

        $rows = static::$db->query(
            "SELECT name FROM sqlite_master WHERE type='index' AND name='idx_child_label_uniq'",
            [], 'read'
        ) ?: [];
        $this->assertNotEmpty($rows);
    }

    public function testCreateIndexIsIdempotent(): void
    {
        static::$alter->createIndex('child', 'idx_child_label', ['label'], false);
        $ok = static::$alter->createIndex('child', 'idx_child_label', ['label'], false);
        $this->assertTrue($ok);
    }

    public function testCreateCompositeIndex(): void
    {
        $ok = static::$alter->createIndex('child', 'idx_child_multi', ['parent_id', 'label'], false);
        $this->assertTrue($ok);
    }

    // ── dropIndex ─────────────────────────────────────────────────────────────

    public function testDropIndexRemovesIndex(): void
    {
        static::$alter->createIndex('child', 'idx_child_drop', ['label'], false);
        $ok = static::$alter->dropIndex('child', 'idx_child_drop');
        $this->assertTrue($ok);

        $rows = static::$db->query(
            "SELECT name FROM sqlite_master WHERE type='index' AND name='idx_child_drop'",
            [], 'read'
        ) ?: [];
        $this->assertEmpty($rows);
    }

    public function testDropIndexIsIdempotent(): void
    {
        $ok = static::$alter->dropIndex('child', 'idx_nonexistent');
        $this->assertTrue($ok);
    }

    // ── createMinimalTable with plugin FK ─────────────────────────────────────

    public function testCreateMinimalPluginTableWithFk(): void
    {
        static::$db->exec('DROP TABLE IF EXISTS "myplugin"');
        // 'parent' already exists from setUp
        $ok = static::$alter->createMinimalTable('myplugin', true, 'parent');
        $this->assertTrue($ok);

        $cols = static::$db->query("PRAGMA table_info(myplugin)", [], 'read') ?: [];
        $names = array_column($cols, 'name');
        $this->assertContains('id_link',    $names);
        $this->assertContains('table_link', $names);

        $fks = static::$db->query("PRAGMA foreign_key_list(myplugin)", [], 'read') ?: [];
        $this->assertNotEmpty($fks, 'Plugin table must have a FK on id_link');
        $this->assertSame('id_link',  $fks[0]['from']);
        $this->assertSame('parent',   $fks[0]['table']);
        $this->assertSame('RESTRICT', strtoupper($fks[0]['on_delete']));
    }

    public function testCreateMinimalPluginTableWithoutFkWhenNoParent(): void
    {
        static::$db->exec('DROP TABLE IF EXISTS "myplugin2"');
        $ok = static::$alter->createMinimalTable('myplugin2', true, '');
        $this->assertTrue($ok);

        $fks = static::$db->query("PRAGMA foreign_key_list(myplugin2)", [], 'read') ?: [];
        $this->assertEmpty($fks, 'Plugin table without pluginOf must have no FK');
    }

    // ── createMinimalTable (non-plugin / main table) ──────────────────────────

    public function testCreateMinimalMainTableCreatorIsNullable(): void
    {
        static::$db->exec('DROP TABLE IF EXISTS "maindata"');
        $ok = static::$alter->createMinimalTable('maindata', false);
        $this->assertTrue($ok);

        $cols = static::$db->query("PRAGMA table_info(maindata)", [], 'read') ?: [];
        $creatorCol = null;
        foreach ($cols as $col) {
            if ($col['name'] === 'creator') {
                $creatorCol = $col;
                break;
            }
        }
        $this->assertNotNull($creatorCol, 'creator column must exist');
        $this->assertSame(0, (int)$creatorCol['notnull'], 'creator must be nullable (notnull = 0)');
    }

    public function testCreateMinimalMainTableCreatorHasFkToBdusUsers(): void
    {
        static::$db->exec('DROP TABLE IF EXISTS "maindata2"');
        $ok = static::$alter->createMinimalTable('maindata2', false);
        $this->assertTrue($ok);

        $fks = static::$db->query("PRAGMA foreign_key_list(maindata2)", [], 'read') ?: [];
        $this->assertNotEmpty($fks, 'Non-plugin table must have a FK on creator');

        $creatorFk = null;
        foreach ($fks as $fk) {
            if ($fk['from'] === 'creator') {
                $creatorFk = $fk;
                break;
            }
        }
        $this->assertNotNull($creatorFk, 'FK on creator column must be present');
        $this->assertSame('bdus_users', $creatorFk['table']);
        $this->assertSame('id',         $creatorFk['to']);
        $this->assertSame('SET NULL',   strtoupper($creatorFk['on_delete']));
    }
}
