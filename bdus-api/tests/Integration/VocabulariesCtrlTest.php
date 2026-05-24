<?php

namespace Tests\Integration;

use Tests\Support\BdusTestCase;

/**
 * Integration tests for vocabularies_ctrl.
 *
 * BdusTestCase already creates bdus_vocabularies and seeds:
 *   test_cat: Cat-A (sort 1), Cat-B (sort 2), Cat-C (sort 3)
 *   other_set: Other-X (sort 1)
 *
 * Tests are ordered so mutations happen after read-only assertions.
 */
class VocabulariesCtrlTest extends BdusTestCase
{
    // ── list() ────────────────────────────────────────────────────────────

    public function testListReturnsSuccess(): void
    {
        $ctrl = $this->makeController('vocabularies_ctrl');
        $res  = $this->callController($ctrl, 'list');

        $this->assertSame('success', $res['status']);
        $this->assertArrayHasKey('vocs', $res);
        $this->assertIsArray($res['vocs']);
    }

    public function testListGroupsByVocName(): void
    {
        $ctrl = $this->makeController('vocabularies_ctrl');
        $res  = $this->callController($ctrl, 'list');

        $names = array_column($res['vocs'], 'name');
        $this->assertContains('test_cat',  $names);
        $this->assertContains('other_set', $names);
    }

    public function testListItemsHaveCorrectShape(): void
    {
        $ctrl = $this->makeController('vocabularies_ctrl');
        $res  = $this->callController($ctrl, 'list');

        // Find the test_cat group
        $group = null;
        foreach ($res['vocs'] as $v) {
            if ($v['name'] === 'test_cat') {
                $group = $v;
                break;
            }
        }

        $this->assertNotNull($group, 'test_cat group not found');
        $this->assertCount(3, $group['items']);

        $item = $group['items'][0];
        $this->assertArrayHasKey('id',   $item);
        $this->assertArrayHasKey('def',  $item);
        $this->assertArrayHasKey('sort', $item);
        $this->assertIsInt($item['id']);
        $this->assertIsInt($item['sort']);
    }

    public function testListOrderedBySortWithinVoc(): void
    {
        $ctrl = $this->makeController('vocabularies_ctrl');
        $res  = $this->callController($ctrl, 'list');

        $group = null;
        foreach ($res['vocs'] as $v) {
            if ($v['name'] === 'test_cat') {
                $group = $v;
                break;
            }
        }

        $this->assertSame('Cat-A', $group['items'][0]['def']);
        $this->assertSame('Cat-B', $group['items'][1]['def']);
        $this->assertSame('Cat-C', $group['items'][2]['def']);
    }

    // ── add() ─────────────────────────────────────────────────────────────

    public function testAddEntrySuccess(): void
    {
        $ctrl = $this->makeController('vocabularies_ctrl', ['voc' => 'test_cat', 'def' => 'Cat-D']);
        $res  = $this->callController($ctrl, 'add');

        $this->assertSame('success',     $res['status']);
        $this->assertSame('ok_def_added', $res['code']);
    }

    public function testAddedEntryAppearsInList(): void
    {
        $ctrl = $this->makeController('vocabularies_ctrl', ['voc' => 'new_set', 'def' => 'New-One']);
        $this->callController($ctrl, 'add');

        $ctrl = $this->makeController('vocabularies_ctrl');
        $res  = $this->callController($ctrl, 'list');

        $names = array_column($res['vocs'], 'name');
        $this->assertContains('new_set', $names);
    }

    // ── edit() ────────────────────────────────────────────────────────────

    public function testEditEntrySuccess(): void
    {
        // First, get an existing id from the seed (test_cat / Cat-A)
        $listCtrl = $this->makeController('vocabularies_ctrl');
        $list     = $this->callController($listCtrl, 'list');

        $id = null;
        foreach ($list['vocs'] as $voc) {
            if ($voc['name'] === 'test_cat') {
                $id = $voc['items'][0]['id'];
                break;
            }
        }
        $this->assertNotNull($id, 'seed item not found');

        $ctrl = $this->makeController('vocabularies_ctrl', ['id' => $id, 'val' => 'Cat-A-Edited']);
        $res  = $this->callController($ctrl, 'edit');

        $this->assertSame('success',       $res['status']);
        $this->assertSame('ok_def_update', $res['code']);
    }

    public function testEditedValuePersisted(): void
    {
        // Get id of first test_cat entry
        $listCtrl = $this->makeController('vocabularies_ctrl');
        $list     = $this->callController($listCtrl, 'list');

        $id = null;
        foreach ($list['vocs'] as $voc) {
            if ($voc['name'] === 'test_cat') {
                $id = $voc['items'][0]['id'];
                break;
            }
        }

        // Edit
        $ctrl = $this->makeController('vocabularies_ctrl', ['id' => $id, 'val' => 'Modified-Value']);
        $this->callController($ctrl, 'edit');

        // Re-list and verify
        $ctrl2  = $this->makeController('vocabularies_ctrl');
        $list2  = $this->callController($ctrl2, 'list');
        $defs   = [];
        foreach ($list2['vocs'] as $voc) {
            if ($voc['name'] === 'test_cat') {
                $defs = array_column($voc['items'], 'def');
            }
        }
        $this->assertContains('Modified-Value', $defs);
    }

    // ── sort() via POST ───────────────────────────────────────────────────

    public function testSortViaPostSuccess(): void
    {
        // Get the ids of the test_cat entries
        $listCtrl = $this->makeController('vocabularies_ctrl');
        $list     = $this->callController($listCtrl, 'list');

        $ids = [];
        foreach ($list['vocs'] as $voc) {
            if ($voc['name'] === 'test_cat') {
                $ids = array_column($voc['items'], 'id');
                break;
            }
        }
        $this->assertNotEmpty($ids, 'test_cat must have entries');

        // Reverse the sort order via POST { ids: [id2, id1, id0] }
        $reversedIds = array_reverse($ids);
        $ctrl = $this->makeController(
            'vocabularies_ctrl',
            [],
            ['ids' => $reversedIds]
        );
        $res = $this->callController($ctrl, 'sort');

        $this->assertSame('success',        $res['status']);
        $this->assertSame('ok_sort_update', $res['code']);
    }

    public function testSortViaGetSuccess(): void
    {
        // Get ids of test_cat entries
        $listCtrl = $this->makeController('vocabularies_ctrl');
        $list     = $this->callController($listCtrl, 'list');

        $ids = [];
        foreach ($list['vocs'] as $voc) {
            if ($voc['name'] === 'test_cat') {
                $ids = array_column($voc['items'], 'id');
                break;
            }
        }
        $this->assertNotEmpty($ids);

        // Build GET sort[0]=id[0], sort[1]=id[1], ... (same order, just testing the GET path)
        $sortParam = [];
        foreach ($ids as $i => $id) {
            $sortParam[$i] = $id;
        }
        $ctrl = $this->makeController('vocabularies_ctrl', ['sort' => $sortParam]);
        $res  = $this->callController($ctrl, 'sort');

        $this->assertSame('success',        $res['status']);
        $this->assertSame('ok_sort_update', $res['code']);
    }

    // ── erase() ───────────────────────────────────────────────────────────

    public function testEraseEntrySuccess(): void
    {
        // Add a temporary entry then delete it
        $ctrl = $this->makeController('vocabularies_ctrl', ['voc' => 'tmp_set', 'def' => 'Tmp-One']);
        $this->callController($ctrl, 'add');

        // Find its id
        $listCtrl = $this->makeController('vocabularies_ctrl');
        $list     = $this->callController($listCtrl, 'list');

        $id = null;
        foreach ($list['vocs'] as $voc) {
            if ($voc['name'] === 'tmp_set') {
                $id = $voc['items'][0]['id'];
                break;
            }
        }
        $this->assertNotNull($id, 'tmp_set entry not found after add');

        $ctrl = $this->makeController('vocabularies_ctrl', ['id' => $id]);
        $res  = $this->callController($ctrl, 'erase');

        $this->assertSame('success',      $res['status']);
        $this->assertSame('ok_def_erase', $res['code']);
    }

    public function testErasedEntryDisappearsFromList(): void
    {
        // Add and immediately erase
        $ctrl = $this->makeController('vocabularies_ctrl', ['voc' => 'erase_set', 'def' => 'Erase-Me']);
        $this->callController($ctrl, 'add');

        $listCtrl = $this->makeController('vocabularies_ctrl');
        $list     = $this->callController($listCtrl, 'list');

        $id = null;
        foreach ($list['vocs'] as $voc) {
            if ($voc['name'] === 'erase_set') {
                $id = $voc['items'][0]['id'];
            }
        }
        $this->assertNotNull($id);

        $ctrl = $this->makeController('vocabularies_ctrl', ['id' => $id]);
        $this->callController($ctrl, 'erase');

        // Re-list
        $ctrl2 = $this->makeController('vocabularies_ctrl');
        $list2 = $this->callController($ctrl2, 'list');
        $names = array_column($list2['vocs'], 'name');
        $this->assertNotContains('erase_set', $names);
    }
}
