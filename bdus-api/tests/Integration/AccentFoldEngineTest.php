<?php

namespace Tests\Integration;

use DB\DB;
use Monolog\Handler\NullHandler;
use Monolog\Logger;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use SQL\Fold;
use SQL\Like;

/**
 * #82: accent-insensitive search on every engine, switched on with BRADYPUS_ACCENT_INSENSITIVE.
 * SQLite folds with its own function, PostgreSQL with `unaccent`, MariaDB/MySQL by collation.
 */
class AccentFoldEngineTest extends TestCase
{
    public static function engines(): array
    {
        return InspectObjectsEngineTest::engines();
    }

    protected function tearDown(): void
    {
        putenv('BRADYPUS_ACCENT_INSENSITIVE');
    }

    private function connect(array $cfg): DB
    {
        $log = new Logger('t');
        $log->pushHandler(new NullHandler());
        $db = new DB('af_' . $cfg['db_engine'], $cfg);
        $db->setLog($log);
        $db->query('SELECT 1');
        return $db;
    }

    #[DataProvider('engines')]
    public function testGreekAndLatinAccentsAreIgnoredWhenSwitchedOn(array $cfg): void
    {
        $engine = $cfg['db_engine'];
        putenv('BRADYPUS_ACCENT_INSENSITIVE=1');
        try {
            $db = $this->connect($cfg);
            if ($engine === 'pgsql') {
                $db->exec('CREATE EXTENSION IF NOT EXISTS unaccent');
                $db = $this->connect($cfg);   // registers again, now that the extension exists
            }
        } catch (\Throwable $e) {
            $this->markTestSkipped("$engine not reachable or extension not available");
        }

        $db->exec('DROP TABLE IF EXISTS af_t');
        $auto = ['sqlite' => 'INTEGER PRIMARY KEY AUTOINCREMENT', 'mysql' => 'INTEGER PRIMARY KEY AUTO_INCREMENT', 'pgsql' => 'SERIAL PRIMARY KEY'][$engine];
        $db->exec("CREATE TABLE af_t (id $auto, name VARCHAR(60))");
        try {
            foreach (['Σερῆνε', 'perché', 'ἄνθρωπος', 'Plain'] as $v) {
                $db->query('INSERT INTO af_t (name) VALUES (?)', [$v], 'boolean');
            }
            $this->assertTrue(Fold::active($engine) || $engine === 'mysql', "$engine can fold");

            $find = function (string $needle) use ($db, $engine): array {
                $sql = 'SELECT name FROM af_t WHERE ' . Fold::col($engine, 'name') . ' ' . Like::operator($engine)
                     . ' ' . Fold::val($engine) . ' ORDER BY id';
                return array_column($db->query($sql, ['%' . $needle . '%'], 'read'), 'name');
            };
            $this->assertSame(['Σερῆνε'], $find('Σερηνε'));
            $this->assertSame(['Σερῆνε'], $find('σερηνε'));
            $this->assertSame(['perché'], $find('perche'));
            $this->assertSame(['ἄνθρωπος'], $find('ανθρωπος'));
            $this->assertSame(['perché'], $find('PERCHÉ'));
            $this->assertSame([], $find('zzz'));
        } finally {
            $db->exec('DROP TABLE IF EXISTS af_t');
        }
    }

    #[DataProvider('engines')]
    public function testSqlIsUnchangedWhenSwitchedOff(array $cfg): void
    {
        putenv('BRADYPUS_ACCENT_INSENSITIVE');
        try {
            $this->connect($cfg);
        } catch (\Throwable $e) {
            $this->markTestSkipped("{$cfg['db_engine']} not reachable");
        }
        $this->assertFalse(Fold::active($cfg['db_engine']));
        $this->assertSame('t.name', Fold::col($cfg['db_engine'], 't.name'));
        $this->assertSame('?', Fold::val($cfg['db_engine']));
    }

    public function testFoldFunction(): void
    {
        $this->assertSame('σερηνε', Fold::fold('Σερῆνε'));
        $this->assertSame('perche', Fold::fold('PERCHÉ'));
        $this->assertSame('42', Fold::fold(42));
        $this->assertNull(Fold::fold(null));
    }
}
