<?php

namespace Tests\Integration;

use PHPUnit\Framework\TestCase;
use DB\DB;
use DB\Alter\Sqlite as SqliteAlter;
use Monolog\Logger;
use Monolog\Handler\NullHandler;

/**
 * Integration tests for DB\Alter\Sqlite.
 *
 * Two scenarios are exercised:
 *   - Modern path  (SQLite >= 3.25.0): ALTER TABLE … RENAME COLUMN
 *   - Legacy path  (SQLite <  3.25.0): full table-rebuild via Reflection override
 */
class SqliteAlterTest extends TestCase
{
    private static DB          $db;
    private static SqliteAlter $alter;

    public static function setUpBeforeClass(): void
    {
        $log = new Logger('test');
        $log->pushHandler(new NullHandler());

        static::$db    = new DB('test_sqlite_alter', ['db_engine' => 'sqlite', 'db_path' => ':memory:']);
        static::$db->setLog($log);
        static::$alter = new SqliteAlter(static::$db);
    }

    // ── Modern path (SQLite >= 3.25.0) ────────────────────────────────────────

    public function testRenameFldModernPath(): void
    {
        static::$db->query(
            'CREATE TABLE test_modern (id INTEGER PRIMARY KEY AUTOINCREMENT, old_name TEXT, keeper INTEGER)',
            [], 'boolean'
        );
        static::$db->query(
            "INSERT INTO test_modern (old_name, keeper) VALUES ('hello', 99)",
            [], 'boolean'
        );

        $result = static::$alter->renameFld('test_modern', 'old_name', 'new_name');

        $this->assertTrue($result);

        $pragma = static::$db->query('PRAGMA table_info(test_modern)') ?: [];
        $names  = array_column($pragma, 'name');
        $this->assertContains('new_name', $names, 'Renamed column must exist');
        $this->assertNotContains('old_name', $names, 'Old column name must be gone');

        $rows = static::$db->query("SELECT new_name, keeper FROM test_modern WHERE id = 1") ?: [];
        $this->assertSame('hello', $rows[0]['new_name'], 'Row data must be preserved after rename');
        $this->assertSame(99,      (int) $rows[0]['keeper']);
    }

    // ── Legacy path (SQLite < 3.25.0): table-rebuild ──────────────────────────

    public function testRenameFldLegacyPath(): void
    {
        static::$db->query('DROP TABLE IF EXISTS test_legacy', [], 'boolean');
        static::$db->query(
            'CREATE TABLE test_legacy (id INTEGER PRIMARY KEY AUTOINCREMENT, old_col TEXT NOT NULL, keeper INTEGER)',
            [], 'boolean'
        );
        static::$db->query(
            "INSERT INTO test_legacy (old_col, keeper) VALUES ('value', 42)",
            [], 'boolean'
        );

        // Force the legacy branch by overriding sqlite_version via Reflection.
        $ref  = new \ReflectionClass(static::$alter);
        $prop = $ref->getProperty('sqlite_version');
        $prop->setAccessible(true);
        $originalVersion = $prop->getValue(static::$alter);
        $prop->setValue(static::$alter, '3.24.0'); // below the 3.25.0 threshold

        try {
            $result = static::$alter->renameFld('test_legacy', 'old_col', 'new_col');
        } finally {
            $prop->setValue(static::$alter, $originalVersion); // always restore
        }

        $this->assertTrue($result);

        $pragma = static::$db->query('PRAGMA table_info(test_legacy)') ?: [];
        $names  = array_column($pragma, 'name');
        $this->assertContains('new_col', $names, 'Renamed column must exist after rebuild');
        $this->assertNotContains('old_col', $names, 'Old column name must be gone after rebuild');

        $rows = static::$db->query("SELECT new_col, keeper FROM test_legacy WHERE id = 1") ?: [];
        $this->assertSame('value', $rows[0]['new_col'], 'Row data must survive the table rebuild');
        $this->assertSame(42,      (int) $rows[0]['keeper']);
    }

    public function testRenameFldLegacyPreservesMultipleRows(): void
    {
        static::$db->query('DROP TABLE IF EXISTS test_multirow', [], 'boolean');
        static::$db->query(
            'CREATE TABLE test_multirow (id INTEGER PRIMARY KEY AUTOINCREMENT, label TEXT, score INTEGER)',
            [], 'boolean'
        );
        foreach ([['alpha', 1], ['beta', 2], ['gamma', 3]] as [$lbl, $sc]) {
            static::$db->query(
                "INSERT INTO test_multirow (label, score) VALUES (?, ?)",
                [$lbl, $sc], 'boolean'
            );
        }

        $ref  = new \ReflectionClass(static::$alter);
        $prop = $ref->getProperty('sqlite_version');
        $prop->setAccessible(true);
        $originalVersion = $prop->getValue(static::$alter);
        $prop->setValue(static::$alter, '3.24.0');

        try {
            static::$alter->renameFld('test_multirow', 'label', 'title');
        } finally {
            $prop->setValue(static::$alter, $originalVersion);
        }

        $rows = static::$db->query('SELECT id, title, score FROM test_multirow ORDER BY id') ?: [];
        $this->assertCount(3, $rows);
        $this->assertSame('alpha', $rows[0]['title']);
        $this->assertSame('beta',  $rows[1]['title']);
        $this->assertSame('gamma', $rows[2]['title']);
        $this->assertSame(2, (int) $rows[1]['score']);
    }
}
