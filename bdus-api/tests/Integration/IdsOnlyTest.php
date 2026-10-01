<?php

namespace Tests\Integration;

use Tests\Support\BdusTestCase;

/**
 * `ids_only=1` on getRecords / getFiles: the ordered id list of the WHOLE
 * result set, used by the Previous/Next navigation. The contract that matters
 * is that it orders exactly like the paginated list.
 */
class IdsOnlyTest extends BdusTestCase
{
    private function pagedIds(array $params, int $perPage): array
    {
        $ids = [];
        for ($page = 1; $page <= 10; $page++) {
            $ctrl = $this->makeController('Bdus\\Controllers\\Record',
                array_merge(['tb' => 'items', 'page' => $page, 'per_page' => $perPage], $params)
            );
            $res = $this->callController($ctrl, 'getRecords');
            $this->assertSame('success', $res['status']);
            if (!$res['data']) {
                break;
            }
            foreach ($res['data'] as $row) {
                $ids[] = (int) $row['id'];
            }
        }
        return $ids;
    }

    private function idsOnly(array $params): array
    {
        $ctrl = $this->makeController('Bdus\\Controllers\\Record',
            array_merge(['tb' => 'items', 'ids_only' => 1], $params)
        );
        return $this->callController($ctrl, 'getRecords');
    }

    public function testRecordsIdsOnlyShape(): void
    {
        $res = $this->idsOnly([]);

        $this->assertSame('success', $res['status']);
        $this->assertSame(5, $res['total']);
        $this->assertCount(5, $res['ids']);
        $this->assertContainsOnlyInt($res['ids']);
        $this->assertFalse($res['truncated']);
        $this->assertArrayNotHasKey('data', $res);
    }

    public function testRecordsIdsOnlyHonoursPaginationParamsByReturningEverything(): void
    {
        $res = $this->idsOnly(['page' => 2, 'per_page' => 2]);
        $this->assertCount(5, $res['ids']);
    }

    public function testRecordsIdsOnlyMatchesPaginatedOrderOnNonUniqueSort(): void
    {
        // status has ties (3 × 'active'): without a tie-breaker the page
        // boundaries and the id list could disagree.
        foreach (['asc', 'desc'] as $dir) {
            $params = ['sort_field' => 'status', 'sort_dir' => $dir];
            $this->assertSame(
                $this->pagedIds($params, 2),
                $this->idsOnly($params)['ids'],
                "ids_only order must equal the paginated list order ({$dir})"
            );
        }
    }

    public function testRecordsIdsOnlyHonoursFilter(): void
    {
        $params = ['filter' => ['status' => ['_eq' => 'active']]];
        $res = $this->idsOnly($params);

        $this->assertSame(3, $res['total']);
        $this->assertSame($this->pagedIds($params, 2), $res['ids']);
    }

    public function testRecordsIdsOnlyRequiresReadPrivilege(): void
    {
        $this->setPrivilege(40);
        $res = $this->idsOnly([]);
        $this->setPrivilege(1);

        $this->assertSame('error', $res['status']);
        $this->assertSame('not_enough_privilege', $res['code']);
    }

    public function testFilesIdsOnlyShapeAndOrder(): void
    {
        $ctrl = $this->makeController('Bdus\\Controllers\\File', ['ids_only' => 1], []);
        $res  = $this->callController($ctrl, 'getFiles');

        $this->assertSame('success', $res['status']);
        $this->assertSame(2, $res['total']);
        $this->assertFalse($res['truncated']);
        $this->assertSame([2, 1], array_column($res['files'], 'id'), 'same order as the list: id DESC');
        foreach ($res['files'] as $f) {
            $this->assertSame(['id', 'ext', 'filename', 'is_image'], array_keys($f));
        }
    }

    public function testFilesIdsOnlyHonoursOrphansFilter(): void
    {
        // Seeded files are both linked → no orphans
        $ctrl = $this->makeController('Bdus\\Controllers\\File', ['ids_only' => 1, 'orphans_only' => 1], []);
        $res  = $this->callController($ctrl, 'getFiles');

        $this->assertSame(0, $res['total']);
        $this->assertSame([], $res['files']);
    }

    public function testFilesIdsOnlyRequiresReadPrivilege(): void
    {
        $this->setPrivilege(40);
        $ctrl = $this->makeController('Bdus\\Controllers\\File', ['ids_only' => 1], []);
        $res  = $this->callController($ctrl, 'getFiles');
        $this->setPrivilege(1);

        $this->assertSame('error', $res['status']);
        $this->assertSame('not_enough_privilege', $res['code']);
    }
}
