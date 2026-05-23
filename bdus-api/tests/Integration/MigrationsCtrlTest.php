<?php

namespace Tests\Integration;

use Tests\Support\BdusTestCase;

/**
 * Integration tests for home_ctrl::getMigrations.
 */
class MigrationsCtrlTest extends BdusTestCase
{
    // ── getMigrations ─────────────────────────────────────────────────────

    public function testGetMigrationsReturnsSuccessShape(): void
    {
        $ctrl = $this->makeController('home_ctrl', []);
        $res  = $this->callController($ctrl, 'getMigrations');

        $this->assertSame('success', $res['status']);
        $this->assertArrayHasKey('total',      $res);
        $this->assertArrayHasKey('applied',    $res);
        $this->assertArrayHasKey('migrations', $res);
        $this->assertIsArray($res['migrations']);
    }

    public function testGetMigrationsTotalMatchesKnownCount(): void
    {
        $ctrl = $this->makeController('home_ctrl', []);
        $res  = $this->callController($ctrl, 'getMigrations');

        // The total must equal the number of classes in Migrate::ALL_MIGRATIONS.
        $expected = count(\DB\System\Migrate::ALL_MIGRATIONS);
        $this->assertSame($expected, $res['total']);
    }

    public function testGetMigrationsRowsHaveRequiredKeys(): void
    {
        $ctrl = $this->makeController('home_ctrl', []);
        $res  = $this->callController($ctrl, 'getMigrations');

        foreach ($res['migrations'] as $m) {
            $this->assertArrayHasKey('name',       $m, 'Migration row missing "name"');
            $this->assertArrayHasKey('applied',    $m, 'Migration row missing "applied"');
            $this->assertArrayHasKey('applied_at', $m, 'Migration row missing "applied_at"');
            $this->assertIsBool($m['applied'], 'applied must be a boolean');
        }
    }

    public function testGetMigrationsAppliedCountIsConsistent(): void
    {
        $ctrl = $this->makeController('home_ctrl', []);
        $res  = $this->callController($ctrl, 'getMigrations');

        $countedApplied = count(array_filter($res['migrations'], fn($m) => $m['applied']));
        $this->assertSame($res['applied'], $countedApplied,
            '"applied" summary count must match actual applied rows');
    }

    public function testGetMigrationsNamesMatchKnownMigrations(): void
    {
        $ctrl = $this->makeController('home_ctrl', []);
        $res  = $this->callController($ctrl, 'getMigrations');

        $expectedNames = array_map(
            fn($class) => $class::NAME,
            \DB\System\Migrate::ALL_MIGRATIONS
        );
        $actualNames = array_column($res['migrations'], 'name');

        $this->assertSame($expectedNames, $actualNames,
            'Migration names must match ALL_MIGRATIONS in order');
    }
}
