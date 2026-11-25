<?php

namespace Tests\Integration;

use Config\FieldSources;
use Record\Validator;
use Tests\Support\BdusTestCase;

/**
 * Vocabulary, values from a column and a lookup table (FK) are alternatives for the values of
 * a field. A config that sets more than one is refused when it is saved, and one that already
 * does (written before) keeps saving text, as the form offers it, instead of failing the FK check.
 */
class FieldSourcesTest extends BdusTestCase
{
    protected function setUp(): void
    {
        $this->setPrivilege(1);
    }

    private function errors(array $cfg, mixed $value): array
    {
        return (new Validator(static::$db, static::$cfg))->validateFieldValue('cat_ref', $value, $cfg + ['type' => 'select'], 'items', null);
    }

    public function testSetListsTheSourcesInOrderOfPrecedence(): void
    {
        $this->assertSame([], FieldSources::set(['type' => 'select', 'id_from_tb' => '']));
        $this->assertSame(['id_from_tb'], FieldSources::set(['id_from_tb' => 'categories']));
        $this->assertSame(
            ['get_values_from_tb', 'id_from_tb', 'vocabulary_set'],
            FieldSources::set(['vocabulary_set' => 'v', 'id_from_tb' => 'categories', 'get_values_from_tb' => 'categories:name'])
        );
        $this->assertSame('get_values_from_tb', FieldSources::effective(['id_from_tb' => 'categories', 'get_values_from_tb' => 'categories:name']));
        $this->assertNull(FieldSources::effective(['type' => 'text']));
        $this->assertTrue(FieldSources::conflicts(['id_from_tb' => 'categories', 'vocabulary_set' => 'v']));
        $this->assertFalse(FieldSources::conflicts(['id_from_tb' => 'categories']));
    }

    public function testALookupFieldStillNeedsAnExistingId(): void
    {
        $rules = array_column($this->errors(['id_from_tb' => 'categories'], 'Saggio A'), 'rule');
        $this->assertContains('fk_not_found', $rules);
        $this->assertSame([], $this->errors(['id_from_tb' => 'categories'], (int) static::$db->query('SELECT id FROM categories LIMIT 1', [], 'read')[0]['id']));
    }

    public function testAFieldWithBothSourcesAcceptsTheTextTheFormOffers(): void
    {
        // values from a column is in force (it is what the form lists): no FK check on the text
        $cfg = ['id_from_tb' => 'categories', 'get_values_from_tb' => 'categories:label'];
        $this->assertSame([], $this->errors($cfg, 'Saggio A'));
    }

    public function testASavedConfigWithMoreThanOneSourceIsRefused(): void
    {
        $before = static::$cfg->get('tables.items.fields.cat_ref');
        $post   = ['name' => 'cat_ref', 'type' => 'select', 'label' => 'Cat', 'id_from_tb' => 'categories', 'get_values_from_tb' => 'categories:label'];

        $res = $this->callController($this->makeController(\Bdus\Controllers\Config::class, ['tb' => 'items', 'fld' => 'cat_ref'], $post), 'save_fld_properties');

        $this->assertSame('error', $res['status']);
        $this->assertSame('field_source_conflict', $res['code']);
        $this->assertSame('get_values_from_tb, id_from_tb', $res['detail']);
        $this->assertSame($before, static::$cfg->get('tables.items.fields.cat_ref'), 'nothing was saved');
    }

    public function testANewFieldWithMoreThanOneSourceIsRefused(): void
    {
        $post = ['name' => 'src_conflict', 'type' => 'select', 'db_type' => 'TEXT', 'id_from_tb' => 'categories', 'vocabulary_set' => 'colori'];

        $res = $this->callController($this->makeController(\Bdus\Controllers\Config::class, ['tb' => 'items'], $post), 'add_new_fld');

        $this->assertSame('field_source_conflict', $res['code']);
        $this->assertNotContains('src_conflict', array_column((new \DB\Inspect(static::$db))->tableColumns('items'), 'fld'));
        $this->assertContains('cat_ref', array_column((new \DB\Inspect(static::$db))->tableColumns('items'), 'fld'), 'the column listing carries names');
    }
}
