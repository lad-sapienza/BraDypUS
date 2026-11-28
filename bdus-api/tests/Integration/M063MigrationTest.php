<?php

namespace Tests\Integration;

use DB\DB;
use DB\System\Manage;
use DB\System\Migrations\M063_DropPrintableFromFiles;
use Monolog\Handler\NullHandler;
use Monolog\Logger;
use PHPUnit\Framework\TestCase;

/** M063: the fossil `printable` is dropped (column and field config); `public` and the data stay; idempotent. */
class M063MigrationTest extends TestCase
{
    private DB $db;
    private Manage $manage;

    protected function setUp(): void
    {
        $log = new Logger('t');
        $log->pushHandler(new NullHandler());
        $this->db = new DB('m063_' . uniqid(), ['db_engine' => 'sqlite', 'db_path' => ':memory:']);
        $this->db->setLog($log);
        $this->manage = new Manage($this->db);
        $this->manage->createTable('bdus_cfg_fields');
        $this->manage->createTable('bdus_files');
        $this->db->exec('ALTER TABLE bdus_files ADD COLUMN printable INTEGER');
        $this->db->query("INSERT INTO bdus_files (creator, ext, filename, printable, public) VALUES ('x', 'jpg', 'a', 1, 1)", [], 'boolean');
        foreach (['printable', 'public'] as $n) {
            $this->db->query(
                'INSERT INTO bdus_cfg_fields (table_name, name, label, type, db_type) VALUES (?, ?, ?, ?, ?)',
                ['bdus_files', $n, $n, 'boolean', 'INTEGER'],
                'boolean'
            );
        }
    }

    public function testItDropsTheColumnAndTheFieldAndKeepsTheRest(): void
    {
        M063_DropPrintableFromFiles::run($this->manage);

        $this->assertFalse($this->manage->columnExists('bdus_files', 'printable'));
        $row = $this->db->query('SELECT filename, public FROM bdus_files', [], 'read')[0];
        $this->assertSame('a', $row['filename']);
        $this->assertSame(1, (int) $row['public']);
        $names = array_column($this->db->query('SELECT name FROM bdus_cfg_fields WHERE table_name = ?', ['bdus_files'], 'read'), 'name');
        $this->assertSame(['public'], $names);
    }

    public function testItIsIdempotent(): void
    {
        M063_DropPrintableFromFiles::run($this->manage);
        M063_DropPrintableFromFiles::run($this->manage);

        $this->assertFalse($this->manage->columnExists('bdus_files', 'printable'));
    }
}
