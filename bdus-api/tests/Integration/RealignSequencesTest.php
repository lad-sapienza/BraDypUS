<?php

namespace Tests\Integration;

use DB\DB;
use DB\System\Manage;
use DB\System\Migrations\M059_RealignPostgresSequences;
use PHPUnit\Framework\TestCase;

/**
 * M059 / Manage::realignSequences(): a PostgreSQL-only repair. On any other engine it must
 * do nothing and leave the data alone. The PostgreSQL behaviour itself runs under
 * `test.sh --all-engines`.
 */
class RealignSequencesTest extends TestCase
{
    public function testNoOpOnSqlite(): void
    {
        $db = new DB('test_realign', ['db_engine' => 'sqlite', 'db_path' => ':memory:']);
        $db->exec('CREATE TABLE t (id INTEGER PRIMARY KEY AUTOINCREMENT, v TEXT)');
        $db->exec("INSERT INTO t (id, v) VALUES (5, 'a')");
        $manage = new Manage($db);

        $this->assertSame([], $manage->realignSequences());
        M059_RealignPostgresSequences::run($manage);

        $this->assertSame(1, (int) $db->query('SELECT COUNT(*) AS c FROM t', [], 'read')[0]['c']);
    }

    public function testMigrationIsRegistered(): void
    {
        $this->assertContains(
            M059_RealignPostgresSequences::class,
            \DB\System\Migrate::ALL_MIGRATIONS
        );
        $this->assertSame('M059_realign_postgres_sequences', M059_RealignPostgresSequences::NAME);
    }
}
