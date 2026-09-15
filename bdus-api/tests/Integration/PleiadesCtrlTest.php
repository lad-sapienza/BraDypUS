<?php

namespace Tests\Integration;

use Tests\Support\BdusTestCase;

/**
 * Integration tests for Bdus\Controllers\Pleiades.
 *
 * Only the non-network paths are exercised here — parameter validation and
 * privilege gating. Unlike Zotero (whose search() has a lib-lookup gate that
 * naturally prevents any network call once no library is configured in
 * tests), Pleiades\Controller::search()/getPlace() call the real Pleiades API
 * as soon as validation passes, so a "valid query → success/pleiades_api_error"
 * test would be non-deterministic depending on CI network egress. That case
 * is covered instead by Pleiades\Client's pure parseSearchXml()/
 * normalizePlaceJson() unit tests (tests/Unit/PleiadesClientTest.php) and,
 * optionally, a live hurl phase — same status as Zotero's real-network
 * exception in the demo seed.
 */
class PleiadesCtrlTest extends BdusTestCase
{
    public function testSearchMissingQueryReturnsError(): void
    {
        $ctrl = $this->makeController('Bdus\\Controllers\\Pleiades', ['q' => '']);
        $res  = $this->callController($ctrl, 'search');

        $this->assertSame('error', $res['status']);
        $this->assertSame('parameter_missing', $res['code']);
    }

    public function testSearchRequiresEditPrivilege(): void
    {
        $this->setPrivilege(30); // reader — below edit
        $ctrl = $this->makeController('Bdus\\Controllers\\Pleiades', ['q' => 'Roma']);
        $res  = $this->callController($ctrl, 'search');
        $this->setPrivilege(1);

        $this->assertSame('error', $res['status']);
        $this->assertSame('not_enough_privilege', $res['code']);
    }

    public function testGetPlaceMissingIdReturnsError(): void
    {
        $ctrl = $this->makeController('Bdus\\Controllers\\Pleiades', ['id' => 0]);
        $res  = $this->callController($ctrl, 'getPlace');

        $this->assertSame('error', $res['status']);
        $this->assertSame('parameter_missing', $res['code']);
    }

    public function testGetPlaceRequiresEditPrivilege(): void
    {
        $this->setPrivilege(30); // reader — below edit
        $ctrl = $this->makeController('Bdus\\Controllers\\Pleiades', ['id' => 423025]);
        $res  = $this->callController($ctrl, 'getPlace');
        $this->setPrivilege(1);

        $this->assertSame('error', $res['status']);
        $this->assertSame('not_enough_privilege', $res['code']);
    }
}
