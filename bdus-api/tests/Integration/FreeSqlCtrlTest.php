<?php

namespace Tests\Integration;

use PHPUnit\Framework\Attributes\DataProvider;
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

    // ── one statement per run (#60) ───────────────────────────────────────────

    public function testRunSqlRefusesMoreThanOneStatement(): void
    {
        $ctrl = $this->makeController('Bdus\\Controllers\\FreeSql', [], [
            'sql' => 'CREATE VIEW v60a AS SELECT 1 AS id; CREATE VIEW v60b AS SELECT 2 AS id',
        ]);
        $res = $this->callController($ctrl, 'runSql');

        $this->assertSame('error', $res['status']);
        $this->assertSame('error_free_sql_run', $res['code']);
        $views = static::$db->query("SELECT name FROM sqlite_master WHERE type='view' AND name LIKE 'v60%'", [], 'read');
        $this->assertSame([], $views, 'nothing must be executed');
    }

    public function testRunSqlAcceptsTrailingSemicolon(): void
    {
        $ctrl = $this->makeController('Bdus\\Controllers\\FreeSql', [], ['sql' => 'SELECT 1 AS n; ']);
        $res = $this->callController($ctrl, 'runSql');

        $this->assertSame('success', $res['status']);
    }

    public function testRunSqlAcceptsTriggerWithSeveralInnerStatements(): void
    {
        $ctrl = $this->makeController('Bdus\\Controllers\\FreeSql', [], [
            'sql' => "CREATE TRIGGER t60 AFTER INSERT ON items BEGIN\n"
                   . "  UPDATE items SET id = id WHERE id = NEW.id;\n"
                   . "  SELECT CASE WHEN 1 THEN 1 ELSE 2 END;\n"
                   . "END;",
        ]);
        $res = $this->callController($ctrl, 'runSql');

        $this->assertSame('success', $res['status']);
        static::$db->exec('DROP TRIGGER t60');
    }

    #[DataProvider('statementCounts')]
    public function testSqlStatementsCount(string $sql, int $expected): void
    {
        $this->assertSame($expected, \DB\SqlStatements::count($sql));
    }

    public static function statementCounts(): array
    {
        return [
            'one'                  => ['SELECT 1', 1],
            'trailing ;'           => ['SELECT 1;', 1],
            'two'                  => ['SELECT 1; SELECT 2', 2],
            'two + trailing'       => ["SELECT 1;\nSELECT 2;\n", 2],
            'empty statements'     => [';; SELECT 1;;', 1],
            'only comment after'   => ['SELECT 1; -- done', 1],
            'block comment ;'      => ['SELECT 1 /* a; b */', 1],
            'line comment ;'       => ["-- a; b\nSELECT 1", 1],
            'string ;'             => ["SELECT 'a;b'", 1],
            'escaped quote'        => ["SELECT 'it''s; ok'", 1],
            'quoted identifier ;'  => ['SELECT 1 AS "a;b"', 1],
            'dollar body'          => ['CREATE FUNCTION f() RETURNS int AS $$ BEGIN RETURN 1; END; $$ LANGUAGE plpgsql', 1],
            'tagged dollar body'   => ['CREATE FUNCTION f() RETURNS int AS $f$ SELECT 1; $f$ LANGUAGE sql; SELECT 2', 2],
            'sqlite trigger'       => ['CREATE TRIGGER t AFTER INSERT ON a BEGIN INSERT INTO b VALUES (1); INSERT INTO b VALUES (2); END', 1],
            'trigger + next'       => ['CREATE TRIGGER t AFTER INSERT ON a BEGIN SELECT 1; END; SELECT 2', 2],
            'mysql trigger if'     => ['CREATE TRIGGER t BEFORE INSERT ON a FOR EACH ROW BEGIN IF NEW.x IS NULL THEN SET NEW.x = 1; END IF; SET NEW.y = 2; END', 1],
            'trigger with CASE'    => ['CREATE TRIGGER t AFTER INSERT ON a BEGIN SELECT CASE WHEN 1 THEN 2 END; SELECT 3; END; SELECT 4', 2],
            'BEGIN outside trigger' => ['BEGIN; SELECT 1; COMMIT', 3],
        ];
    }
}
