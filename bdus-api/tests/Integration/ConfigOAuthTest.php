<?php

declare(strict_types=1);

namespace Tests\Integration;

use Tests\Support\BdusTestCase;

/**
 * Integration tests for the OAuth2/SSO credentials section of the app
 * config form:
 *   PUT /api/config/app → Config::save_app_properties() persists "oauth"
 *   GET /api/config/app → Config::getAppProperties() returns it back
 *
 * Config::BOOTSTRAP_KEYS lists "oauth" alongside the DB credentials so it
 * round-trips through config.json exactly like db_password does — see
 * Bdus\Controllers\OAuth::getCredentials(), which reads it straight off disk.
 */
class ConfigOAuthTest extends BdusTestCase
{
    /** @var array<string,string> path => original content, for fixture cleanup */
    private static array $fixtureSnapshot = [];
    private static string $fixtureDir;

    /**
     * The test-only file-based Config (see BdusTestCase) writes save_app_properties()
     * straight to tests/fixtures/cfg/config.json — shared by every test class in the
     * same PHPUnit run. Snapshot it here and restore in tearDownAfterClass() so this
     * class's writes don't leak into other test classes. See RadiocarbonCtrlTest for
     * the same pattern applied to table/field fixtures.
     */
    public static function setUpBeforeClass(): void
    {
        parent::setUpBeforeClass();

        self::$fixtureDir      = __DIR__ . '/../fixtures/cfg';
        self::$fixtureSnapshot = [];
        foreach (glob(self::$fixtureDir . '/*.json') as $file) {
            self::$fixtureSnapshot[$file] = file_get_contents($file);
        }
    }

    public static function tearDownAfterClass(): void
    {
        foreach (glob(self::$fixtureDir . '/*.json') as $file) {
            if (!isset(self::$fixtureSnapshot[$file])) {
                unlink($file);
            }
        }
        foreach (self::$fixtureSnapshot as $file => $content) {
            file_put_contents($file, $content);
        }
        parent::tearDownAfterClass();
    }

    public function testSaveAppPropertiesPersistsAndReturnsOAuthCredentials(): void
    {
        $ctrl = $this->makeController('Bdus\\Controllers\\Config', [], [
            'oauth' => [
                'google' => ['client_id' => 'GOOGLE_ID', 'client_secret' => 'GOOGLE_SECRET'],
                'orcid'  => ['client_id' => 'APP-ORCID',  'client_secret' => 'ORCID_SECRET'],
            ],
        ]);
        $res = $this->callController($ctrl, 'save_app_properties');
        $this->assertSame('success', $res['status']);

        $ctrl = $this->makeController('Bdus\\Controllers\\Config');
        $res  = $this->callController($ctrl, 'getAppProperties');

        $this->assertSame('GOOGLE_ID',     $res['main']['oauth']['google']['client_id']);
        $this->assertSame('GOOGLE_SECRET', $res['main']['oauth']['google']['client_secret']);
        $this->assertSame('APP-ORCID',     $res['main']['oauth']['orcid']['client_id']);
        $this->assertSame('ORCID_SECRET',  $res['main']['oauth']['orcid']['client_secret']);

        // Straight off disk too — Controllers\OAuth::getCredentials() never
        // goes through Config, it reads config.json directly on every request.
        $written = json_decode(file_get_contents(self::$fixtureDir . '/config.json'), true);
        $this->assertSame('GOOGLE_ID', $written['oauth']['google']['client_id']);
    }

    public function testSaveAppPropertiesRequiresSuperAdmin(): void
    {
        $this->setPrivilege(11);
        $ctrl = $this->makeController('Bdus\\Controllers\\Config', [], ['oauth' => []]);
        $res  = $this->callController($ctrl, 'save_app_properties');
        $this->setPrivilege(1);

        $this->assertSame('error',                $res['status']);
        $this->assertSame('not_enough_privilege', $res['code']);
    }
}
