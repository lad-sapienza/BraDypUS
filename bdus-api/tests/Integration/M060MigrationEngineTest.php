<?php

namespace Tests\Integration;

use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use DB\DB;
use DB\System\Manage;
use DB\System\Migrations\M060_AddUuidToFiles;
use File\Store;
use Monolog\Logger;
use Monolog\Handler\NullHandler;

/**
 * M060 on every engine: the ALTERs, the backfill and above all the UNIQUE index on a TEXT
 * column (MySQL needs a prefix length). The file renames are engine independent and covered
 * by M060MigrationTest. Skipped when the engine is not reachable: run it with
 * `./test.sh --unit --db=pgsql` / `--db=mysql`.
 */
class M060MigrationEngineTest extends TestCase
{
    public static function engines(): array
    {
        return InspectObjectsEngineTest::engines();
    }

    #[DataProvider('engines')]
    public function testMigratesAnExistingFilesTable(array $cfg): void
    {
        $log = new Logger('t');
        $log->pushHandler(new NullHandler());
        try {
            $db = new DB('m060_' . $cfg['db_engine'], $cfg);
            $db->setLog($log);
            $db->query('SELECT 1');
        } catch (\Throwable $e) {
            $this->markTestSkipped("{$cfg['db_engine']} not reachable — run via test.sh --unit --db=...");
        }
        $engine = $cfg['db_engine'];
        $auto   = ['sqlite' => 'INTEGER PRIMARY KEY AUTOINCREMENT', 'mysql' => 'INTEGER PRIMARY KEY AUTO_INCREMENT', 'pgsql' => 'SERIAL PRIMARY KEY'][$engine];

        foreach (['bdus_files', 'bdus_cfg_fields'] as $t) {
            $db->exec("DROP TABLE IF EXISTS {$t}");
        }
        $manage = new Manage($db);

        // The shape of bdus_files before M060
        $db->exec(
            "CREATE TABLE bdus_files (id {$auto}, creator VARCHAR(50) NOT NULL, ext VARCHAR(20) NOT NULL, "
            . 'keywords TEXT, description TEXT, printable INTEGER, filename TEXT NOT NULL, attribution TEXT)'
        );
        $manage->createTable('bdus_cfg_fields');
        $db->exec("INSERT INTO bdus_files (creator, ext, filename) VALUES ('x', 'jpg', 'a'), ('x', 'png', 'b'), ('x', 'pdf', 'c')");

        $proj = sys_get_temp_dir() . '/bdus_m060e_' . uniqid() . '/';
        mkdir($proj, 0755, true);

        try {
            M060_AddUuidToFiles::run($manage, $proj);

            $this->assertTrue($manage->columnExists('bdus_files', 'uuid'));
            $this->assertTrue($manage->columnExists('bdus_files', 'public'));

            $rows = $db->query('SELECT uuid, public FROM bdus_files ORDER BY id', [], 'read');
            $this->assertCount(3, $rows);
            $uuids = array_column($rows, 'uuid');
            foreach ($uuids as $u) {
                $this->assertTrue(Store::isUuid($u), "{$engine}: {$u}");
            }
            $this->assertCount(3, array_unique($uuids));
            $this->assertSame([1, 1, 1], array_map(fn($r) => (int) $r['public'], $rows), 'existing files start public');

            // The unique index is real on this engine
            $threw = false;
            try {
                $db->query('UPDATE bdus_files SET uuid = ? WHERE id = (SELECT MAX(id) FROM (SELECT id FROM bdus_files) t)', [$uuids[0]], 'boolean');
            } catch (\Throwable $e) {
                $threw = true;
            }
            $this->assertTrue($threw, "{$engine}: duplicate uuid must be refused by the unique index");

            // A re-run keeps everything
            M060_AddUuidToFiles::run($manage, $proj);
            $this->assertSame($uuids, array_column($db->query('SELECT uuid FROM bdus_files ORDER BY id', [], 'read'), 'uuid'));
        } finally {
            foreach (['bdus_files', 'bdus_cfg_fields'] as $t) {
                $db->exec("DROP TABLE IF EXISTS {$t}");
            }
            @rmdir($proj);
        }
    }
}
