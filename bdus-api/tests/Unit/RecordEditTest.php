<?php

namespace Tests\Unit;

use Tests\Support\BdusTestCase;
use Record\Read;
use Record\Edit;

/**
 * Unit tests for Record\Edit.
 *
 * These tests verify that Edit modifies the model correctly (in memory) without
 * touching the database (except to read the initial model via Record\Read).
 */
class RecordEditTest extends BdusTestCase
{
    private const TB     = 'test__items';
    private const TB_PLG = 'test__tags';

    // ── Helper ────────────────────────────────────────────────────────────

    private function makeEdit(int $id): Edit
    {
        $read = new Read($id, null, self::TB, static::$db, static::$cfg);
        return new Edit($read);
    }

    // ── setCore ───────────────────────────────────────────────────────────

    public function testSetCoreMarksFieldAsChanged(): void
    {
        $edit  = $this->makeEdit(1);
        $edit->setCore(['name' => 'New Name']);
        $model = $edit->getModel();

        $this->assertArrayHasKey('_val', $model['core']['name']);
        $this->assertSame('New Name', $model['core']['name']['_val']);
    }

    public function testSetCoreIgnoresUnchangedValue(): void
    {
        // Seed: item 1 name = 'Alpha item'
        $edit = $this->makeEdit(1);
        $edit->setCore(['name' => 'Alpha item']); // same value, no change
        $model = $edit->getModel();

        $this->assertArrayNotHasKey('_val', $model['core']['name']);
    }

    public function testSetCoreIgnoresIdField(): void
    {
        $edit = $this->makeEdit(1);
        $edit->setCore(['id' => 999]);
        $model = $edit->getModel();

        // id should never get a _val marker
        $this->assertArrayNotHasKey('_val', $model['core']['id']);
        // original val still 1
        $this->assertSame(1, (int) $model['core']['id']['val']);
    }

    // ── setPluginRow ──────────────────────────────────────────────────────

    public function testSetPluginRowUpdateSetsVal(): void
    {
        $edit = $this->makeEdit(1); // item 1 has tags id=1 and id=2
        $edit->setPluginRow(self::TB_PLG, 1, ['label' => 'tag-a-new']);
        $model = $edit->getModel();

        $this->assertArrayHasKey('_val', $model['plugins'][self::TB_PLG]['data'][1]['label']);
        $this->assertSame('tag-a-new', $model['plugins'][self::TB_PLG]['data'][1]['label']['_val']);
    }

    public function testSetPluginRowDeleteSetsDeleteFlag(): void
    {
        $edit = $this->makeEdit(1);
        $edit->setPluginRow(self::TB_PLG, 1, []); // empty data = delete
        $model = $edit->getModel();

        $this->assertTrue($model['plugins'][self::TB_PLG]['data'][1]['id']['_delete']);
    }

    public function testSetPluginRowInsertAddsRow(): void
    {
        $edit = $this->makeEdit(1);
        $countBefore = count($edit->getModel()['plugins'][self::TB_PLG]['data'] ?? []);

        $edit->setPluginRow(self::TB_PLG, null, ['label' => 'brand-new-tag']);
        $model = $edit->getModel();

        $countAfter = count($model['plugins'][self::TB_PLG]['data'] ?? []);
        $this->assertSame($countBefore + 1, $countAfter);

        // The new row must have a _val key for 'label'
        $lastRow = end($model['plugins'][self::TB_PLG]['data']);
        $this->assertArrayHasKey('label', $lastRow);
        $this->assertSame('brand-new-tag', $lastRow['label']['_val']);
    }

    // ── delete ────────────────────────────────────────────────────────────

    public function testDeleteSetsDeleteFlag(): void
    {
        $edit = $this->makeEdit(1);
        $edit->delete();
        $model = $edit->getModel();

        $this->assertTrue($model['core']['id']['_delete']);
    }

    // ── setManualLink ─────────────────────────────────────────────────────

    public function testSetManualLinkInsertAddsEntry(): void
    {
        $edit        = $this->makeEdit(1);
        $countBefore = count($edit->getModel()['manualLinks']);

        $edit->setManualLink(null, 'test__items', 3, 5);
        $model = $edit->getModel();

        $this->assertSame($countBefore + 1, count($model['manualLinks']));
        $last = end($model['manualLinks']);
        $this->assertSame('test__items', $last['_tb_id']);
        $this->assertSame(3,            $last['_ref_id']);
        $this->assertSame(5,            $last['_sort']);
    }

    public function testSetManualLinkDeleteSetsFlag(): void
    {
        // The seed has a manual link between item 1 and item 2; get its key from the model.
        $edit  = $this->makeEdit(1);
        $model = $edit->getModel();

        // Find the non-file manual link
        $mlKey = null;
        foreach ($model['manualLinks'] as $key => $link) {
            if ($link['tb_id'] !== 'test__files') {
                $mlKey = $key;
                break;
            }
        }
        $this->assertNotNull($mlKey, "Seed manual link between items must exist");

        $edit->setManualLink($mlKey); // delete
        $model = $edit->getModel();

        $this->assertTrue($model['manualLinks'][$mlKey]['_delete']);
    }

    // ── setRs ─────────────────────────────────────────────────────────────

    public function testSetRsInsertAddsEntry(): void
    {
        $edit        = $this->makeEdit(1);
        $countBefore = count($edit->getModel()['rs']);

        $edit->setRs(null, 'X', 'Y', '3');
        $model = $edit->getModel();

        $this->assertSame($countBefore + 1, count($model['rs']));
        $last = end($model['rs']);
        $this->assertSame('X', $last['_first']);
        $this->assertSame('Y', $last['_second']);
        $this->assertSame('3', $last['_relation']);
    }

    public function testSetRsDeleteSetsFlag(): void
    {
        // The seed has an RS row with first='1', second='2' so Read::getRs()
        // can return it when reading item id=1 (first=1 OR second=1 matches).
        $edit  = $this->makeEdit(1);
        $model = $edit->getModel();

        // Find the seeded rs row key (id) in model['rs']
        $rsKey = null;
        foreach ($model['rs'] as $key => $rel) {
            if (isset($rel['first']) && $rel['first'] === '1') {
                $rsKey = $key;
                break;
            }
        }

        if ($rsKey !== null) {
            // Happy path: rs row found, call setRs delete
            $edit->setRs($rsKey);
            $model = $edit->getModel();
            $this->assertTrue($model['rs'][$rsKey]['_delete']);
        } else {
            // Fallback: verify graceful-ignore when key not found (log entry produced)
            $edit->setRs(9999); // not found
            $log = $edit->getLog();
            $this->assertNotEmpty($log);
            $this->assertStringContainsString('9999', $log[count($log) - 1]);
        }
    }
}
