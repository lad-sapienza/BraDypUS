<?php

namespace Tests\Integration;

use Tests\Support\BdusTestCase;

/**
 * Integration tests for the manual-links (userlinks) endpoints in record_ctrl:
 *   searchLinkCandidates(), addManualLink(), deleteManualLink()
 *
 * Seed state (from BdusTestCase):
 *   - 5 items in items (ids 1–5)
 *   - 1 existing userlink: items/1 ↔ items/2
 */
class RecordCtrlManualLinksTest extends BdusTestCase
{
    private const TB = 'items';

    // ── searchLinkCandidates ──────────────────────────────────────────────────

    public function testSearchLinkCandidatesReturnsSuccess(): void
    {
        $ctrl = $this->makeController('Bdus\\Controllers\\Record', ['tb' => self::TB, 'q' => '']);
        $res  = $this->callController($ctrl, 'searchLinkCandidates');

        $this->assertSame('success', $res['status']);
        $this->assertArrayHasKey('data', $res);
        $this->assertIsArray($res['data']);
    }

    public function testSearchLinkCandidatesReturnsRows(): void
    {
        $ctrl = $this->makeController('Bdus\\Controllers\\Record', ['tb' => self::TB, 'q' => '']);
        $res  = $this->callController($ctrl, 'searchLinkCandidates');

        $this->assertNotEmpty($res['data'], 'Should return at least one candidate');
        $this->assertArrayHasKey('id',    $res['data'][0]);
        $this->assertArrayHasKey('label', $res['data'][0]);
    }

    public function testSearchLinkCandidatesFiltersByQuery(): void
    {
        // id_field for items is 'id', so q matches by exact id
        $ctrl = $this->makeController('Bdus\\Controllers\\Record', ['tb' => self::TB, 'q' => '3']);
        $res  = $this->callController($ctrl, 'searchLinkCandidates');

        $this->assertSame('success', $res['status']);
        $this->assertCount(1, $res['data']);
        $this->assertSame(3, (int)$res['data'][0]['id']);
    }

    public function testSearchLinkCandidatesMissingTbReturnsError(): void
    {
        $ctrl = $this->makeController('Bdus\\Controllers\\Record', ['q' => 'foo']);
        $res  = $this->callController($ctrl, 'searchLinkCandidates');

        $this->assertSame('error', $res['status']);
    }

    public function testSearchLinkCandidatesRequiresReadPrivilege(): void
    {
        $this->setPrivilege(99);
        $ctrl = $this->makeController('Bdus\\Controllers\\Record', ['tb' => self::TB, 'q' => '']);
        $res  = $this->callController($ctrl, 'searchLinkCandidates');
        $this->setPrivilege(1);

        $this->assertSame('error', $res['status']);
        $this->assertSame('not_enough_privilege', $res['code']);
    }

    // ── addManualLink ─────────────────────────────────────────────────────────

    public function testAddManualLinkSuccess(): void
    {
        // Link item 1 → item 3 (no pre-existing link)
        $ctrl = $this->makeController('Bdus\\Controllers\\Record', [], [
            'tb_one' => self::TB, 'id_one' => 1,
            'tb_two' => self::TB, 'id_two' => 3,
        ]);
        $res = $this->callController($ctrl, 'addManualLink');

        $this->assertSame('success', $res['status']);
        $this->assertSame('all_links_saved', $res['code']);
        $this->assertArrayHasKey('link', $res);
        $this->assertSame(self::TB, $res['link']['tb_id']);
        $this->assertSame(3,        $res['link']['ref_id']);
        $this->assertGreaterThan(0, $res['link']['key']);
    }

    public function testAddManualLinkReturnedShapeIsComplete(): void
    {
        $ctrl = $this->makeController('Bdus\\Controllers\\Record', [], [
            'tb_one' => self::TB, 'id_one' => 1,
            'tb_two' => self::TB, 'id_two' => 4,
        ]);
        $res = $this->callController($ctrl, 'addManualLink');

        foreach (['key', 'tb_id', 'tb_stripped', 'tb_label', 'ref_id', 'ref_label', 'label'] as $k) {
            $this->assertArrayHasKey($k, $res['link'], "Missing key: $k");
        }
        $this->assertSame('items', $res['link']['tb_stripped']);
        $this->assertNull($res['link']['label']);
    }

    public function testAddManualLinkWithLabelStoresAndReturnsLabel(): void
    {
        $ctrl = $this->makeController('Bdus\\Controllers\\Record', [], [
            'tb_one' => self::TB, 'id_one' => 1,
            'tb_two' => self::TB, 'id_two' => 5,
            'label'  => 'cites',
        ]);
        $res = $this->callController($ctrl, 'addManualLink');

        $this->assertSame('success', $res['status']);
        $this->assertSame('cites', $res['link']['label']);

        // Verify stored in DB
        $row = static::$db->query(
            'SELECT label FROM bdus_userlinks WHERE id = ?',
            [$res['link']['key']],
            'read'
        );
        $this->assertSame('cites', $row[0]['label']);

        // Clean up
        static::$db->query('DELETE FROM bdus_userlinks WHERE id = ?', [$res['link']['key']], 'boolean');
    }

    public function testAddManualLinkDuplicateReturnsError(): void
    {
        // items 1 ↔ 2 already linked in seed
        $ctrl = $this->makeController('Bdus\\Controllers\\Record', [], [
            'tb_one' => self::TB, 'id_one' => 1,
            'tb_two' => self::TB, 'id_two' => 2,
        ]);
        $res = $this->callController($ctrl, 'addManualLink');

        $this->assertSame('error', $res['status']);
        $this->assertSame('link_already_exists', $res['code']);
    }

    public function testAddManualLinkDuplicateReverseReturnsError(): void
    {
        // reverse direction of the seed link (2 → 1) must also be rejected
        $ctrl = $this->makeController('Bdus\\Controllers\\Record', [], [
            'tb_one' => self::TB, 'id_one' => 2,
            'tb_two' => self::TB, 'id_two' => 1,
        ]);
        $res = $this->callController($ctrl, 'addManualLink');

        $this->assertSame('error', $res['status']);
        $this->assertSame('link_already_exists', $res['code']);
    }

    public function testAddManualLinkMissingParamsReturnsError(): void
    {
        $ctrl = $this->makeController('Bdus\\Controllers\\Record', [], ['tb_one' => self::TB]);
        $res  = $this->callController($ctrl, 'addManualLink');

        $this->assertSame('error', $res['status']);
        $this->assertSame('parameter_missing', $res['code']);
    }

    public function testAddManualLinkRequiresEditPrivilege(): void
    {
        $this->setPrivilege(99);
        $ctrl = $this->makeController('Bdus\\Controllers\\Record', [], [
            'tb_one' => self::TB, 'id_one' => 1,
            'tb_two' => self::TB, 'id_two' => 5,
        ]);
        $res = $this->callController($ctrl, 'addManualLink');
        $this->setPrivilege(1);

        $this->assertSame('error', $res['status']);
        $this->assertSame('not_enough_privilege', $res['code']);
    }

    // ── deleteManualLink ──────────────────────────────────────────────────────

    public function testDeleteManualLinkSuccess(): void
    {
        // First add a link to delete
        $add = $this->makeController('Bdus\\Controllers\\Record', [], [
            'tb_one' => self::TB, 'id_one' => 2,
            'tb_two' => self::TB, 'id_two' => 5,
        ]);
        $addRes = $this->callController($add, 'addManualLink');
        $linkId = $addRes['link']['key'];

        $ctrl = $this->makeController('Bdus\\Controllers\\Record', [], ['id' => $linkId]);
        $res  = $this->callController($ctrl, 'deleteManualLink');

        $this->assertSame('success', $res['status']);
        $this->assertSame('ok_userlink_erased', $res['code']);
    }

    public function testDeleteManualLinkUnknownIdReturnsError(): void
    {
        $ctrl = $this->makeController('Bdus\\Controllers\\Record', [], ['id' => 99999]);
        $res  = $this->callController($ctrl, 'deleteManualLink');

        $this->assertSame('error', $res['status']);
        $this->assertSame('not_found', $res['code']);
    }

    public function testDeleteManualLinkMissingIdReturnsError(): void
    {
        $ctrl = $this->makeController('Bdus\\Controllers\\Record');
        $res  = $this->callController($ctrl, 'deleteManualLink');

        $this->assertSame('error', $res['status']);
        $this->assertSame('parameter_missing', $res['code']);
    }

    public function testDeleteManualLinkRequiresEditPrivilege(): void
    {
        $this->setPrivilege(99);
        $ctrl = $this->makeController('Bdus\\Controllers\\Record', [], ['id' => 1]);
        $res  = $this->callController($ctrl, 'deleteManualLink');
        $this->setPrivilege(1);

        $this->assertSame('error', $res['status']);
        $this->assertSame('not_enough_privilege', $res['code']);
    }
}
