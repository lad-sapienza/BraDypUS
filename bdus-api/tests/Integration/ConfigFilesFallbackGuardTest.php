<?php

namespace Tests\Integration;

use Adbar\Dot;
use Config\Config;
use Config\ConfigException;
use Tests\Support\BdusTestCase;

/**
 * #59 — a Config given a DB with no row in bdus_cfg_tables falls back to the legacy files mode.
 * Under tests/fixtures that is a test mistake and must fail loudly, not rewrite the fixtures.
 */
class ConfigFilesFallbackGuardTest extends BdusTestCase
{
    private const FIXTURES = __DIR__ . '/../fixtures/cfg/';

    public function testDbWithoutConfigRowsCannotRewriteFixtures(): void
    {
        $before = md5_file(self::FIXTURES . 'tables.json');

        // A DB that has the bdus_cfg_* tables but no row in bdus_cfg_tables
        $db = new \DB\DB('guard', ['db_engine' => 'sqlite', 'db_path' => ':memory:']);
        (new \DB\System\Manage($db))->createTable('bdus_cfg_tables');
        $cfg = new Config(new Dot(), self::FIXTURES, $db);

        try {
            $cfg->setTable(['name' => 'guard_probe', 'label' => 'x', 'fields' => []]);
            $this->fail('ConfigException expected');
        } catch (ConfigException $e) {
            $this->assertStringContainsString('tests/fixtures', $e->getMessage());
        }

        $this->assertSame($before, md5_file(self::FIXTURES . 'tables.json'));
        $this->assertFileDoesNotExist(self::FIXTURES . 'guard_probe.json');
    }
}
