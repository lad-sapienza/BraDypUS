<?php

namespace Tests\Integration;

use Tests\Support\BdusTestCase;

/**
 * Integration tests for assemblage_analysis_ctrl privilege gating.
 *
 * listAnalyses/getSources/getTableMeta/getData had no privilege check at all
 * before this fix — anyone authenticated could reach them regardless of role.
 * Full CRUD/pivot-engine coverage is out of scope here; this file only
 * verifies the 'read' gate now in place.
 */
class AssemblageAnalysisCtrlTest extends BdusTestCase
{
    public function testListAnalysesRequiresAtLeastReader(): void
    {
        $this->setPrivilege(40); // waiting — fails even 'read' (≤30)

        $ctrl = $this->makeController('Bdus\\Controllers\\AssemblageAnalysis');
        $res  = $this->callController($ctrl, 'listAnalyses');
        $this->assertSame('not_enough_privilege', $res['code']);

        $this->setPrivilege(1);
    }

    public function testReaderCanListAnalyses(): void
    {
        $this->setPrivilege(30); // reader

        $ctrl = $this->makeController('Bdus\\Controllers\\AssemblageAnalysis');
        $res  = $this->callController($ctrl, 'listAnalyses');
        $this->assertSame('success', $res['status']);

        $this->setPrivilege(1);
    }

    public function testGetSourcesRequiresAtLeastReader(): void
    {
        $this->setPrivilege(40);

        $ctrl = $this->makeController('Bdus\\Controllers\\AssemblageAnalysis');
        $res  = $this->callController($ctrl, 'getSources');
        $this->assertSame('not_enough_privilege', $res['code']);

        $this->setPrivilege(1);
    }

    public function testReaderCanGetSources(): void
    {
        $this->setPrivilege(30);

        $ctrl = $this->makeController('Bdus\\Controllers\\AssemblageAnalysis');
        $res  = $this->callController($ctrl, 'getSources');
        $this->assertSame('success', $res['status']);

        $this->setPrivilege(1);
    }

    public function testGetTableMetaRequiresAtLeastReader(): void
    {
        $this->setPrivilege(40);

        $ctrl = $this->makeController('Bdus\\Controllers\\AssemblageAnalysis', ['tb' => 'items']);
        $res  = $this->callController($ctrl, 'getTableMeta');
        $this->assertSame('not_enough_privilege', $res['code']);

        $this->setPrivilege(1);
    }

    public function testReaderCanGetTableMeta(): void
    {
        $this->setPrivilege(30);

        $ctrl = $this->makeController('Bdus\\Controllers\\AssemblageAnalysis', ['tb' => 'items']);
        $res  = $this->callController($ctrl, 'getTableMeta');
        $this->assertSame('success', $res['status']);

        $this->setPrivilege(1);
    }

    public function testGetDataRequiresAtLeastReader(): void
    {
        $this->setPrivilege(40);

        $ctrl = $this->makeController('Bdus\\Controllers\\AssemblageAnalysis', [], ['definition' => []]);
        $res  = $this->callController($ctrl, 'getData');
        $this->assertSame('not_enough_privilege', $res['code']);

        $this->setPrivilege(1);
    }
}
