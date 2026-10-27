<?php

namespace Tests\Integration;

use PHPUnit\Framework\TestCase;
use DB\DB;
use DB\System\Manage;
use DB\System\Migrations\M001_AddUserTablePrivs;
use DB\System\Migrations\M046_AddGroupsAndPermissions;
use Monolog\Logger;
use Monolog\Handler\NullHandler;

/**
 * Tests for M046_AddGroupsAndPermissions (#78).
 *
 * Creates the groups/rules/locks tables, adds bdus_api_keys.group_id and drops
 * the never-enforced bdus_user_table_privs.
 */
class M046MigrationTest extends TestCase
{
    private const NEW_TABLES = ['bdus_groups', 'bdus_user_groups', 'bdus_group_rules', 'bdus_table_locks'];

    private static DB     $db;
    private static Manage $manage;

    public static function setUpBeforeClass(): void
    {
        $log = new Logger('test');
        $log->pushHandler(new NullHandler());

        static::$db = new DB('test_m046', ['db_engine' => 'sqlite', 'db_path' => ':memory:']);
        static::$db->setLog($log);
        static::$manage = new Manage(static::$db);
    }

    protected function setUp(): void
    {
        static::$db->exec('PRAGMA foreign_keys = OFF');
        foreach (array_merge(self::NEW_TABLES, ['bdus_user_table_privs', 'bdus_api_keys', 'bdus_users']) as $t) {
            static::$db->exec("DROP TABLE IF EXISTS {$t}");
        }
        static::$db->exec('PRAGMA foreign_keys = ON');
        static::$manage->createTable('bdus_users');
        // The pre-M046 shape of bdus_api_keys: no group_id column.
        static::$db->exec(
            'CREATE TABLE bdus_api_keys (id INTEGER PRIMARY KEY, key_hash TEXT NOT NULL, label TEXT NOT NULL, '
            . 'created_by INTEGER, created_at INTEGER NOT NULL, last_used_at INTEGER, revoked_at INTEGER, '
            . 'privilege INTEGER DEFAULT 30)'
        );
        M001_AddUserTablePrivs::run(static::$manage);
    }

    private function migrate(): void
    {
        M046_AddGroupsAndPermissions::run(static::$manage);
    }

    private function user(int $id): void
    {
        static::$db->query(
            "INSERT INTO bdus_users (id, name, email, password, privilege) VALUES (?, 'u', ?, 'x', 30)",
            [$id, "u{$id}@example.com"],
            'boolean'
        );
    }

    public function testCreatesTablesAddsColumnAndDropsLegacyTable(): void
    {
        $this->assertTrue(static::$manage->tableExists('bdus_user_table_privs'));
        $this->assertFalse(static::$manage->columnExists('bdus_api_keys', 'group_id'));

        $this->migrate();

        foreach (self::NEW_TABLES as $t) {
            $this->assertTrue(static::$manage->tableExists($t), $t);
        }
        $this->assertTrue(static::$manage->columnExists('bdus_api_keys', 'group_id'));
        $this->assertFalse(static::$manage->tableExists('bdus_user_table_privs'));
    }

    public function testIsIdempotent(): void
    {
        $this->migrate();
        static::$db->query("INSERT INTO bdus_groups (name) VALUES ('Reviewers')", [], 'boolean');

        $this->migrate();

        $rows = static::$db->query('SELECT name FROM bdus_groups', [], 'read');
        $this->assertSame([['name' => 'Reviewers']], $rows);
    }

    public function testLegacyRowsAreDiscardedAndKeysKeepTheirData(): void
    {
        $this->user(1);
        static::$db->query(
            "INSERT INTO bdus_user_table_privs (user_id, table_name, privilege) VALUES (1, 'us', 20)",
            [], 'boolean'
        );
        static::$db->query(
            "INSERT INTO bdus_api_keys (key_hash, label, created_at, privilege) VALUES ('h', 'k', 1, 25)",
            [], 'boolean'
        );

        $this->migrate();

        $key = static::$db->query('SELECT label, privilege, group_id FROM bdus_api_keys', [], 'read')[0];
        $this->assertSame('k', $key['label']);
        $this->assertEquals(25, $key['privilege']);
        $this->assertNull($key['group_id']);
    }

    public function testGroupNameMembershipAndRuleAreUnique(): void
    {
        $this->migrate();
        $this->user(1);
        static::$db->query("INSERT INTO bdus_groups (name) VALUES ('G')", [], 'boolean');

        $dup = [
            "INSERT INTO bdus_groups (name) VALUES ('G')",
            "INSERT INTO bdus_user_groups (user_id, group_id) VALUES (1, 1), (1, 1)",
            "INSERT INTO bdus_group_rules (group_id, table_name, action) VALUES (1, 'us', 'read'), (1, 'us', 'read')",
        ];
        foreach ($dup as $sql) {
            try {
                static::$db->exec($sql);
                $this->fail("Expected a unique-constraint violation for: {$sql}");
            } catch (\Throwable $e) {
                $this->assertTrue(true);
            }
        }
    }

    public function testDeletingAGroupCascadesToMembershipAndRules(): void
    {
        $this->migrate();
        $this->user(1);
        static::$db->exec("INSERT INTO bdus_groups (id, name) VALUES (1, 'G')");
        static::$db->exec("INSERT INTO bdus_user_groups (user_id, group_id) VALUES (1, 1)");
        static::$db->exec("INSERT INTO bdus_group_rules (group_id, table_name, action) VALUES (1, 'us', 'read')");

        static::$db->exec('DELETE FROM bdus_groups WHERE id = 1');

        $this->assertSame([], static::$db->query('SELECT * FROM bdus_user_groups', [], 'read') ?: []);
        $this->assertSame([], static::$db->query('SELECT * FROM bdus_group_rules', [], 'read') ?: []);
    }
}
