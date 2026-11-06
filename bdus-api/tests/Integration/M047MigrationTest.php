<?php

namespace Tests\Integration;

use PHPUnit\Framework\TestCase;
use DB\DB;
use DB\System\Manage;
use DB\System\Migrations\M047_AddAttributionToFiles;
use Monolog\Logger;
use Monolog\Handler\NullHandler;

/**
 * Tests for M047_AddAttributionToFiles: nullable bdus_files.attribution plus
 * the field config row for existing apps. Purely additive and idempotent.
 */
class M047MigrationTest extends TestCase
{
    private static DB     $db;
    private static Manage $manage;

    public static function setUpBeforeClass(): void
    {
        $log = new Logger('test');
        $log->pushHandler(new NullHandler());

        static::$db = new DB('test_m047', ['db_engine' => 'sqlite', 'db_path' => ':memory:']);
        static::$db->setLog($log);
        static::$manage = new Manage(static::$db);
    }

    protected function setUp(): void
    {
        foreach (['bdus_files', 'bdus_cfg_fields'] as $t) {
            static::$db->exec("DROP TABLE IF EXISTS {$t}");
        }
        // The pre-M047 shape of bdus_files: no attribution column.
        static::$db->exec(
            'CREATE TABLE bdus_files (id INTEGER PRIMARY KEY, creator TEXT NOT NULL, ext TEXT NOT NULL, '
            . 'keywords TEXT, description TEXT, printable INTEGER, filename TEXT NOT NULL)'
        );
        static::$manage->createTable('bdus_cfg_fields');
        static::$db->query(
            "INSERT INTO bdus_files (id, creator, ext, filename, keywords) VALUES (1, 'x', 'jpg', 'a', 'kw')",
            [],
            'boolean'
        );
    }

    private function cfgRows(): array
    {
        return static::$db->query(
            "SELECT label FROM bdus_cfg_fields WHERE table_name = 'bdus_files' AND name = 'attribution'",
            [],
            'read'
        ) ?: [];
    }

    public function testAddsColumnAndFieldConfigKeepingExistingRows(): void
    {
        $this->assertFalse(static::$manage->columnExists('bdus_files', 'attribution'));

        M047_AddAttributionToFiles::run(static::$manage);

        $this->assertTrue(static::$manage->columnExists('bdus_files', 'attribution'));
        $this->assertCount(1, $this->cfgRows());

        $row = static::$db->query("SELECT keywords, attribution FROM bdus_files WHERE id = 1", [], 'read');
        $this->assertSame('kw', $row[0]['keywords']);
        $this->assertNull($row[0]['attribution']);
    }

    public function testIsIdempotentAndKeepsUserEditedLabel(): void
    {
        M047_AddAttributionToFiles::run(static::$manage);
        static::$db->query(
            "UPDATE bdus_cfg_fields SET label = 'Credit' WHERE table_name = 'bdus_files' AND name = 'attribution'",
            [],
            'boolean'
        );

        M047_AddAttributionToFiles::run(static::$manage);

        $rows = $this->cfgRows();
        $this->assertCount(1, $rows);
        $this->assertSame('Credit', $rows[0]['label']);
    }
}
