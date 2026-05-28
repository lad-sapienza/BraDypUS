<?php

namespace Tests\Integration;

use PHPUnit\Framework\TestCase;
use DB\DB;
use DB\System\Manage;
use DB\System\Migrations\M022_AddOAuthToUsers;
use Monolog\Logger;
use Monolog\Handler\NullHandler;

/**
 * Tests for M022_AddOAuthToUsers.
 *
 * Verifies that the migration:
 *   - adds oauth_provider and oauth_sub columns to bdus_users
 *   - creates a partial unique index on (oauth_provider, oauth_sub)
 *   - is fully idempotent (safe to run twice)
 *   - is a no-op when bdus_users does not yet exist
 */
class M022MigrationTest extends TestCase
{
    private static DB     $db;
    private static Manage $manage;

    public static function setUpBeforeClass(): void
    {
        $log = new Logger('test');
        $log->pushHandler(new NullHandler());

        static::$db = new DB('test_m022', ['db_engine' => 'sqlite', 'db_path' => ':memory:']);
        static::$db->setLog($log);
        static::$manage = new Manage(static::$db);
    }

    protected function setUp(): void
    {
        // Drop everything so each test gets a clean slate
        static::$db->query("DROP TABLE IF EXISTS bdus_users",       [], 'boolean');
        static::$db->query("DROP INDEX IF EXISTS users_oauth_sub_idx", [], 'boolean');
    }

    // ── Helpers ───────────────────────────────────────────────────────────────

    private function createUsersTable(): void
    {
        static::$db->query("
            CREATE TABLE bdus_users (
                id        INTEGER PRIMARY KEY AUTOINCREMENT,
                name      TEXT NOT NULL,
                email     TEXT NOT NULL,
                password  TEXT NOT NULL,
                privilege INTEGER NOT NULL
            )",
            [], 'boolean'
        );
    }

    private function columnNames(): array
    {
        $cols = static::$db->query('PRAGMA table_info(bdus_users)', [], 'read') ?: [];
        return array_column($cols, 'name');
    }

    private function indexExists(string $name): bool
    {
        $rows = static::$db->query(
            "SELECT name FROM sqlite_master WHERE type='index' AND name=?",
            [$name],
            'read'
        ) ?: [];
        return !empty($rows);
    }

    // ── Tests ─────────────────────────────────────────────────────────────────

    public function testAddsOAuthProviderColumn(): void
    {
        $this->createUsersTable();
        M022_AddOAuthToUsers::run(static::$manage);
        $this->assertContains('oauth_provider', $this->columnNames());
    }

    public function testAddsOAuthSubColumn(): void
    {
        $this->createUsersTable();
        M022_AddOAuthToUsers::run(static::$manage);
        $this->assertContains('oauth_sub', $this->columnNames());
    }

    public function testCreatesPartialUniqueIndex(): void
    {
        $this->createUsersTable();
        M022_AddOAuthToUsers::run(static::$manage);
        $this->assertTrue($this->indexExists('users_oauth_sub_idx'));
    }

    public function testIdempotentColumns(): void
    {
        $this->createUsersTable();
        M022_AddOAuthToUsers::run(static::$manage);
        M022_AddOAuthToUsers::run(static::$manage); // second run must not throw

        $cols = $this->columnNames();
        $this->assertCount(
            1,
            array_filter($cols, fn($c) => $c === 'oauth_provider'),
            'oauth_provider column must not be duplicated'
        );
        $this->assertCount(
            1,
            array_filter($cols, fn($c) => $c === 'oauth_sub'),
            'oauth_sub column must not be duplicated'
        );
    }

    public function testIdempotentIndex(): void
    {
        $this->createUsersTable();
        M022_AddOAuthToUsers::run(static::$manage);
        M022_AddOAuthToUsers::run(static::$manage); // should not throw on duplicate index
        $this->assertTrue($this->indexExists('users_oauth_sub_idx'));
    }

    public function testNoOpWhenTableMissing(): void
    {
        // bdus_users does not exist — migration should silently skip
        M022_AddOAuthToUsers::run(static::$manage);
        // No exception and bdus_users still does not exist
        $tables = static::$db->query(
            "SELECT name FROM sqlite_master WHERE type='table' AND name='bdus_users'",
            [],
            'read'
        ) ?: [];
        $this->assertEmpty($tables);
    }

    public function testExistingRowsUnaffected(): void
    {
        $this->createUsersTable();
        static::$db->query(
            "INSERT INTO bdus_users (name, email, password, privilege)
             VALUES ('Alice', 'alice@example.com', 'hash', 30)",
            [], 'boolean'
        );

        M022_AddOAuthToUsers::run(static::$manage);

        $rows = static::$db->query(
            "SELECT * FROM bdus_users WHERE email = 'alice@example.com'",
            [], 'read'
        );
        $this->assertCount(1, $rows);
        $this->assertNull($rows[0]['oauth_provider']);
        $this->assertNull($rows[0]['oauth_sub']);
    }

    public function testUniqueIndexEnforcedOnNonNullSub(): void
    {
        $this->createUsersTable();
        M022_AddOAuthToUsers::run(static::$manage);

        static::$db->query(
            "INSERT INTO bdus_users (name, email, password, privilege, oauth_provider, oauth_sub)
             VALUES ('Alice', 'alice@example.com', 'hash', 30, 'google', 'sub123')",
            [], 'boolean'
        );

        $this->expectException(\Exception::class);
        static::$db->query(
            "INSERT INTO bdus_users (name, email, password, privilege, oauth_provider, oauth_sub)
             VALUES ('Bob', 'bob@example.com', 'hash', 30, 'google', 'sub123')",
            [], 'boolean'
        );
    }

    public function testNullSubAllowsMultipleRows(): void
    {
        $this->createUsersTable();
        M022_AddOAuthToUsers::run(static::$manage);

        // Both rows have oauth_sub = NULL — partial index must not block this
        static::$db->query(
            "INSERT INTO bdus_users (name, email, password, privilege)
             VALUES ('Alice', 'alice@example.com', 'hash', 30)",
            [], 'boolean'
        );
        static::$db->query(
            "INSERT INTO bdus_users (name, email, password, privilege)
             VALUES ('Bob', 'bob@example.com', 'hash', 30)",
            [], 'boolean'
        );

        $rows = static::$db->query('SELECT COUNT(*) AS tot FROM bdus_users', [], 'read');
        $this->assertSame(2, (int) $rows[0]['tot']);
    }
}
