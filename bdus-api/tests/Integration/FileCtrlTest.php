<?php

namespace Tests\Integration;

use Tests\Support\BdusTestCase;

/**
 * Integration tests for File::sortFiles().
 *
 * bdus_file_links is seeded by BdusTestCase with IDs 1 and 2 (both linked to items record 1).
 */
class FileCtrlTest extends BdusTestCase
{
    // ── sortFiles ─────────────────────────────────────────────────────────────

    public function testSortFilesSuccess(): void
    {
        // Reverse the sort order: file_link 2 first, file_link 1 second.
        $ctrl = $this->makeController(
            'Bdus\\Controllers\\File',
            [],
            ['order' => [2, 1]] // sort 0 → id 2, sort 1 → id 1
        );
        $res = $this->callController($ctrl, 'sortFiles');

        $this->assertSame('success',               $res['status']);
        $this->assertSame('ok_file_sorting_update', $res['code']);

        // Verify the sort column was actually updated.
        $rows = static::$db->query(
            "SELECT id, sort FROM bdus_file_links ORDER BY sort",
            [],
            'read'
        );
        $this->assertSame(2, (int) $rows[0]['id']);
        $this->assertSame(0, (int) $rows[0]['sort']);
        $this->assertSame(1, (int) $rows[1]['id']);
        $this->assertSame(1, (int) $rows[1]['sort']);
    }

    public function testSortFilesSingleElement(): void
    {
        $ctrl = $this->makeController('Bdus\\Controllers\\File', [], ['order' => [1]]);
        $res  = $this->callController($ctrl, 'sortFiles');

        $this->assertSame('success', $res['status']);
    }

    public function testSortFilesEmptyOrderReturnsParameterMissing(): void
    {
        $ctrl = $this->makeController('Bdus\\Controllers\\File', [], ['order' => []]);
        $res  = $this->callController($ctrl, 'sortFiles');

        $this->assertSame('error',             $res['status']);
        $this->assertSame('parameter_missing', $res['code']);
    }

    public function testSortFilesMissingOrderReturnsParameterMissing(): void
    {
        $ctrl = $this->makeController('Bdus\\Controllers\\File', [], []);
        $res  = $this->callController($ctrl, 'sortFiles');

        $this->assertSame('error',             $res['status']);
        $this->assertSame('parameter_missing', $res['code']);
    }

    public function testSortFilesNotEnoughPrivilege(): void
    {
        $this->setPrivilege(99);

        $ctrl = $this->makeController('Bdus\\Controllers\\File', [], ['order' => [1, 2]]);
        $res  = $this->callController($ctrl, 'sortFiles');

        $this->assertSame('error',                 $res['status']);
        $this->assertSame('not_enough_privilege',  $res['code']);

        $this->setPrivilege(1);
    }
}
