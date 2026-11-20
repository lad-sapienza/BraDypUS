<?php

namespace Tests\Integration;

use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use DB\DB;
use Monolog\Logger;
use Monolog\Handler\NullHandler;

/**
 * hasSpatialExtension() runs inside write transactions (records_write wraps a
 * whole save in one). On PostgreSQL without PostGIS the old probe, a call to
 * ST_GeomFromText(), aborted the transaction and every following statement failed.
 */
class SpatialProbeEngineTest extends TestCase
{
    public static function engines(): array
    {
        return InspectObjectsEngineTest::engines();
    }

    #[DataProvider('engines')]
    public function testProbeInsideATransactionLeavesItUsable(array $cfg): void
    {
        $log = new Logger('t');
        $log->pushHandler(new NullHandler());
        try {
            $db = new DB('sp_' . $cfg['db_engine'], $cfg);
            $db->setLog($log);
            $db->query('SELECT 1');
        } catch (\Throwable $e) {
            $this->markTestSkipped("{$cfg['db_engine']} not reachable");
        }

        $db->beginTransaction();
        try {
            $db->hasSpatialExtension();
            $this->assertSame(1, (int) $db->query('SELECT 1 AS one', [], 0));
        } finally {
            $db->rollBack();
        }
    }

    #[DataProvider('engines')]
    public function testPhpBooleansReachABooleanColumn(array $cfg): void
    {
        if ($cfg['db_engine'] !== 'pgsql') {
            $this->markTestSkipped('PostgreSQL only: PDO sends PHP false as an empty string');
        }
        $log = new Logger('t');
        $log->pushHandler(new NullHandler());
        try {
            $db = new DB('sp_bool', $cfg);
            $db->setLog($log);
            $db->query('SELECT 1');
        } catch (\Throwable $e) {
            $this->markTestSkipped('pgsql not reachable');
        }
        $db->exec('DROP TABLE IF EXISTS sp_bool_t');
        $db->exec('CREATE TABLE sp_bool_t (id INTEGER PRIMARY KEY, flag BOOLEAN)');
        try {
            $db->query('INSERT INTO sp_bool_t (id, flag) VALUES (?, ?)', [1, true], 'boolean');
            $db->query('UPDATE sp_bool_t SET flag = ? WHERE id = ?', [false, 1], 'boolean');
            $this->assertFalse((bool) $db->query('SELECT flag FROM sp_bool_t WHERE id = 1', [], 0));
        } finally {
            $db->exec('DROP TABLE IF EXISTS sp_bool_t');
        }
    }
}
