<?php

namespace Tests\Integration;

use Tests\Support\BdusTestCase;

/**
 * Integration tests for free_sql_ctrl v5 endpoints:
 *   verifyPassword(), runSql()
 *
 * verifyPassword() requires a users table with a hashed password — we seed
 * one so the happy-path can be tested.
 *
 * runSql() is tested against the in-memory SQLite DB that every test class
 * already has (items etc.).
 */
class FreeSqlCtrlTest extends BdusTestCase
{
    // ── Schema extension ──────────────────────────────────────────────────────

    protected static function createSchema(): void
    {
        parent::createSchema();

        static::$db->execInTransaction('
            CREATE TABLE bdus_users (
                id        INTEGER PRIMARY KEY AUTOINCREMENT,
                name      TEXT    NOT NULL,
                email     TEXT    NOT NULL,
                privilege INTEGER NOT NULL DEFAULT 99,
                password  TEXT
            )
        ');
    }

    // ── Seed extension ────────────────────────────────────────────────────────

    protected static function seedData(): void
    {
        parent::seedData();

        // Super-admin user (id=1) with a known bcrypt hash of "secret123"
        $hash = password_hash('secret123', PASSWORD_DEFAULT);
        static::$db->execInTransaction(
            "INSERT INTO bdus_users (id, name, email, privilege, password)
             VALUES (1, 'Test Admin', 'test@example.com', 1, '{$hash}')"
        );
    }

    // ── verifyPassword ────────────────────────────────────────────────────────

    public function testVerifyPasswordCorrectPasswordSucceeds(): void
    {
        $ctrl = $this->makeController('Bdus\\Controllers\\FreeSql', [], ['password' => 'secret123']);
        $res  = $this->callController($ctrl, 'verifyPassword');

        $this->assertSame('success', $res['status']);
        $this->assertSame('free_sql_password_ok', $res['code']);
    }

    public function testVerifyPasswordWrongPasswordReturnsError(): void
    {
        $ctrl = $this->makeController('Bdus\\Controllers\\FreeSql', [], ['password' => 'wrongpassword']);
        $res  = $this->callController($ctrl, 'verifyPassword');

        $this->assertSame('error', $res['status']);
        $this->assertSame('free_sql_wrong_password', $res['code']);
    }

    public function testVerifyPasswordMissingPasswordReturnsError(): void
    {
        $ctrl = $this->makeController('Bdus\\Controllers\\FreeSql', [], []);
        $res  = $this->callController($ctrl, 'verifyPassword');

        $this->assertSame('error', $res['status']);
        $this->assertSame('parameter_missing', $res['code']);
    }

    public function testVerifyPasswordRequiresSuperAdmin(): void
    {
        $this->setPrivilege(2); // not super_admin (only privilege=1 qualifies)

        $ctrl = $this->makeController('Bdus\\Controllers\\FreeSql', [], ['password' => 'secret123']);
        $res  = $this->callController($ctrl, 'verifyPassword');
        $this->assertSame('not_enough_privilege', $res['code']);

        $this->setPrivilege(1);
    }

    // ── runSql — SELECT ───────────────────────────────────────────────────────

    public function testRunSqlSelectReturnsRows(): void
    {
        $ctrl = $this->makeController('Bdus\\Controllers\\FreeSql', [], [
            'sql' => 'SELECT id, name FROM items ORDER BY id',
        ]);
        $res = $this->callController($ctrl, 'runSql');

        $this->assertSame('success', $res['status']);
        $this->assertIsArray($res['rows']);
        $this->assertIsArray($res['columns']);
        $this->assertContains('id',   $res['columns']);
        $this->assertContains('name', $res['columns']);
        $this->assertSame(count($res['rows']), $res['total']);
        $this->assertGreaterThan(0, $res['total']);
    }

    public function testRunSqlSelectEmptyResultSet(): void
    {
        $ctrl = $this->makeController('Bdus\\Controllers\\FreeSql', [], [
            'sql' => "SELECT * FROM items WHERE name = '__no_such_name__'",
        ]);
        $res = $this->callController($ctrl, 'runSql');

        $this->assertSame('success', $res['status']);
        $this->assertSame([], $res['rows']);
        $this->assertSame(0, $res['total']);
    }

    public function testRunSqlCountIsRecognisedAsSelect(): void
    {
        $ctrl = $this->makeController('Bdus\\Controllers\\FreeSql', [], [
            'sql' => 'SELECT COUNT(*) AS cnt FROM items',
        ]);
        $res = $this->callController($ctrl, 'runSql');

        $this->assertSame('success', $res['status']);
        $this->assertArrayHasKey('rows', $res);
        $this->assertSame(1, count($res['rows']));
    }

    // ── runSql — DML ──────────────────────────────────────────────────────────

    public function testRunSqlInsertReturnsAffectedCount(): void
    {
        $ctrl = $this->makeController('Bdus\\Controllers\\FreeSql', [], [
            'sql' => "INSERT INTO items (name, description) VALUES ('FreeSql Insert', 'via free sql')",
        ]);
        $res = $this->callController($ctrl, 'runSql');

        $this->assertSame('success', $res['status']);
        $this->assertSame('ok_free_sql_run', $res['code']);
        $this->assertSame(1, $res['affected']);

        // Verify it landed in the DB
        $rows = static::$db->query(
            "SELECT id FROM items WHERE name = 'FreeSql Insert'", [], 'read'
        );
        $this->assertNotEmpty($rows);
    }

    public function testRunSqlUpdateReturnsAffectedCount(): void
    {
        $ctrl = $this->makeController('Bdus\\Controllers\\FreeSql', [], [
            'sql' => "UPDATE items SET status = 'sql_updated' WHERE name = 'Alpha item'",
        ]);
        $res = $this->callController($ctrl, 'runSql');

        $this->assertSame('success', $res['status']);
        $this->assertSame(1, $res['affected']);
    }

    public function testRunSqlInvalidSqlReturnsError(): void
    {
        $ctrl = $this->makeController('Bdus\\Controllers\\FreeSql', [], [
            'sql' => 'SELECT * FROM nonexistent_table_xyz',
        ]);
        $res = $this->callController($ctrl, 'runSql');

        $this->assertSame('error', $res['status']);
        $this->assertSame('error_free_sql_run', $res['code']);
        $this->assertNotEmpty($res['detail']);
    }

    public function testRunSqlMissingSqlReturnsError(): void
    {
        $ctrl = $this->makeController('Bdus\\Controllers\\FreeSql', [], []);
        $res  = $this->callController($ctrl, 'runSql');

        $this->assertSame('error', $res['status']);
        $this->assertSame('parameter_missing', $res['code']);
    }

    public function testRunSqlRequiresSuperAdmin(): void
    {
        $this->setPrivilege(2); // not super_admin (only privilege=1 qualifies)

        $ctrl = $this->makeController('Bdus\\Controllers\\FreeSql', [], ['sql' => 'SELECT 1']);
        $res  = $this->callController($ctrl, 'runSql');
        $this->assertSame('not_enough_privilege', $res['code']);

        $this->setPrivilege(1);
    }

    // ── isReadStatement detection ─────────────────────────────────────────────

    public function testRunSqlWithLeadingComment(): void
    {
        $ctrl = $this->makeController('Bdus\\Controllers\\FreeSql', [], [
            'sql' => "-- count records\nSELECT COUNT(*) AS n FROM items",
        ]);
        $res = $this->callController($ctrl, 'runSql');

        // Should be treated as SELECT (read), not DML
        $this->assertSame('success', $res['status']);
        $this->assertArrayHasKey('rows', $res);
    }
}
