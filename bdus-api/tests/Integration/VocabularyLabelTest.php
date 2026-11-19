<?php

namespace Tests\Integration;

use Record\FieldOptions;
use Record\Read;
use Tests\Support\BdusTestCase;

/**
 * #80: an optional label per vocabulary entry. The stored value never changes; forms
 * (field options) and the record (val_label) show the label; an entry without one behaves as before.
 */
class VocabularyLabelTest extends BdusTestCase
{
    private function entryId(string $def): int
    {
        return (int) static::$db->query("SELECT id FROM bdus_vocabularies WHERE voc='test_cat' AND def=?", [$def], 'read')[0]['id'];
    }

    private function options(): array
    {
        $res = (new FieldOptions(static::$db, static::$cfg, \Auth\Access::forCurrentUser(static::$db, static::$cfg)))->resolve('items', 'category');
        return array_column($res, 'label', 'value');
    }

    public function testEntriesWithoutLabelBehaveAsBefore(): void
    {
        $this->assertSame(['Cat-A' => 'Cat-A', 'Cat-B' => 'Cat-B', 'Cat-C' => 'Cat-C'], $this->options());
    }

    public function testControllerStoresReadsAndClearsLabels(): void
    {
        $this->setPrivilege(1);
        $ctrl = $this->makeController('Bdus\\Controllers\\Vocabularies', ['voc' => 'test_cat', 'def' => 'Cat-D', 'label' => '  Delta  ']);
        $this->assertSame('success', $this->callController($ctrl, 'add')['status']);

        $list = $this->callController($this->makeController('Bdus\\Controllers\\Vocabularies'), 'list');
        $items = array_column(array_column(array_filter($list['vocs'], fn($v) => $v['name'] === 'test_cat'), 'items')[0], null, 'def');
        $this->assertSame('Delta', $items['Cat-D']['label']);
        $this->assertNull($items['Cat-A']['label']);
        $this->assertSame('Delta', $this->options()['Cat-D']);

        $id = $this->entryId('Cat-D');
        // value edited, label not sent → label kept
        $this->callController($this->makeController('Bdus\\Controllers\\Vocabularies', ['id' => $id, 'val' => 'Cat-D']), 'edit');
        $this->assertSame('Delta', $this->options()['Cat-D']);
        // empty label → cleared, the value shows again
        $this->callController($this->makeController('Bdus\\Controllers\\Vocabularies', ['id' => $id, 'val' => 'Cat-D', 'label' => '  ']), 'edit');
        $this->assertSame('Cat-D', $this->options()['Cat-D']);
        $this->assertNull(static::$db->query('SELECT label FROM bdus_vocabularies WHERE id=?', [$id], 'read')[0]['label']);
    }

    public function testRecordCarriesTheLabelNextToTheStoredValue(): void
    {
        static::$db->query("UPDATE bdus_vocabularies SET label='Alfa' WHERE voc='test_cat' AND def='Cat-A'", [], 'boolean');
        static::$db->query("UPDATE items SET category='Cat-A' WHERE id=1", [], 'boolean');
        static::$db->query("UPDATE items SET category='Cat-B' WHERE id=2", [], 'boolean');
        static::$db->query("UPDATE items SET category='Gone' WHERE id=3", [], 'boolean');

        $core = fn(int $id) => (new Read($id, null, 'items', static::$db, static::$cfg))->getCore();

        $a = $core(1)['category'];
        $this->assertSame('Cat-A', $a['val']);
        $this->assertSame('Alfa', $a['val_label']);

        $b = $core(2)['category'];            // entry without a label
        $this->assertSame('Cat-B', $b['val']);
        $this->assertArrayNotHasKey('val_label', $b);

        $g = $core(3)['category'];            // value not in the vocabulary
        $this->assertSame('Gone', $g['val']);
        $this->assertArrayNotHasKey('val_label', $g);
    }
}
