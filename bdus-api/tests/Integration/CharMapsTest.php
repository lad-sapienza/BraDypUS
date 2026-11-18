<?php

namespace Tests\Integration;

use Config\AppSettings;
use Config\CharPalette;
use DB\System\Manage;
use DB\System\Migrations\M056_AddCharMapsToCfgApp;
use Tests\Support\BdusTestCase;

/**
 * The application's own char map presets: stored in bdus_cfg_app (M056), listed and
 * saved by Config::getCharMaps / saveCharMaps (super admin), resolved by name.
 */
class CharMapsTest extends BdusTestCase
{
    protected function setUp(): void
    {
        M056_AddCharMapsToCfgApp::run(new Manage(static::$db));
        if (!AppSettings::isAvailable(static::$db)) {
            static::$db->query("INSERT INTO bdus_cfg_app (id, status, max_image_size) VALUES (1, 'on', 0)", [], 'boolean');
        }
        AppSettings::saveCharMaps(static::$db, []);
        $this->setPrivilege(1);
    }

    protected function tearDown(): void
    {
        AppSettings::saveCharMaps(static::$db, []);
        $this->setPrivilege(1);
    }

    private function call(string $method, array $post = []): array
    {
        return $this->callController($this->makeController('Bdus\\Controllers\\Config', [], $post), $method);
    }

    public function testTheMigrationIsIdempotentAndNothingIsStoredByDefault(): void
    {
        M056_AddCharMapsToCfgApp::run(new Manage(static::$db));

        $this->assertSame([], AppSettings::getCharMaps(static::$db));
    }

    public function testListShowsTheBuiltInMapsAsButtonsAndTheOwnOnesAsWritten(): void
    {
        $this->call('saveCharMaps', ['custom' => ['epigrafia' => 'Α Β']]);
        $res = $this->call('getCharMaps');

        $this->assertSame('success', $res['status']);
        $this->assertArrayHasKey('greek-polytonic', $res['system']);
        $this->assertSame('Α Β', ((array) $res['custom'])['epigrafia']);
    }

    public function testSavedMapsComeBackAndResolveByName(): void
    {
        $res = $this->call('saveCharMaps', ['custom' => ['segni' => ['alpha' => 'α'], 'epigrafia' => ' Α Β ']]);

        $this->assertSame('success', $res['status']);
        $stored = AppSettings::getCharMaps(static::$db);
        $this->assertSame(['segni', 'epigrafia'], array_keys($stored));
        $this->assertSame([['label' => 'Α', 'value' => 'Α'], ['label' => 'Β', 'value' => 'Β']], CharPalette::normalize('epigrafia', $stored));
    }

    public function testSaveReplacesEverythingAndAnEmptyListClears(): void
    {
        $this->call('saveCharMaps', ['custom' => ['uno-a' => 'α', 'due-b' => 'β']]);
        $this->call('saveCharMaps', ['custom' => ['tre-c' => 'γ']]);
        $this->assertSame(['tre-c'], array_keys(AppSettings::getCharMaps(static::$db)));

        $this->assertSame('success', $this->call('saveCharMaps', ['custom' => []])['status']);
        $this->assertSame([], AppSettings::getCharMaps(static::$db));
    }

    public function testABadMapRefusesTheWholeSaveAndKeepsWhatWasThere(): void
    {
        $this->call('saveCharMaps', ['custom' => ['buona' => 'α']]);

        $res = $this->call('saveCharMaps', ['custom' => ['ok-map' => 'β', 'Cattiva' => 'γ']]);

        $this->assertSame('error', $res['status']);
        $this->assertSame('invalid_char_maps', $res['code']);
        $this->assertStringContainsString('Cattiva', $res['detail']);
        $this->assertSame(['buona'], array_keys(AppSettings::getCharMaps(static::$db)));
    }

    public function testMissingOrWrongPayloadIsRefused(): void
    {
        $this->assertSame('parameter_invalid', $this->call('saveCharMaps', [])['code']);
        $this->assertSame('parameter_invalid', $this->call('saveCharMaps', ['custom' => 'α β'])['code']);
    }

    public function testOnlyASuperAdminMayListOrSave(): void
    {
        foreach ([10, 20, 30] as $tier) {
            $this->setPrivilege($tier);
            $this->assertSame('not_enough_privilege', $this->call('getCharMaps')['code'], "tier $tier");
            $this->assertSame('not_enough_privilege', $this->call('saveCharMaps', ['custom' => ['abc' => 'α']])['code'], "tier $tier");
        }
        $this->setPrivilege(1);
        $this->assertSame([], AppSettings::getCharMaps(static::$db));
    }

    public function testTheFieldFormOffersTheNamesOfBothKinds(): void
    {
        $this->call('saveCharMaps', ['custom' => ['epigrafia' => 'Α']]);

        $res = $this->callController($this->makeController('Bdus\\Controllers\\Config', ['tb' => 'items']), 'getFldStructure');
        $values = $res['structure']['char_palette']['values'] ?? $res['char_palette']['values'] ?? null;

        $this->assertNotNull($values, json_encode(array_keys($res)));
        $this->assertSame(['greek-polytonic', 'epigrafia'], $values);
    }
}
