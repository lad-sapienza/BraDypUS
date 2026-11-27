<?php

namespace Tests\Integration;

use Adbar\Dot;
use Config\Config;
use Record\ColumnValue;
use Record\Edit;
use Record\Read;
use Record\Validator;
use Tests\Support\BdusTestCase;

/**
 * An empty value written to an INTEGER (or other numeric/boolean/date) column is stored as
 * NULL: PostgreSQL refuses '' there (SQLSTATE 22P02). Runs on every engine of test.sh.
 */
class RecordPersistEmptyIntegerTest extends BdusTestCase
{
    public static function tearDownAfterClass(): void
    {
        try {
            static::$db->query('DROP TABLE nn_test', [], 'boolean');
        } catch (\Throwable) {
        }
        parent::tearDownAfterClass();
    }

    private function cfgWithTypes(array $types): Config
    {
        foreach ($types as $fld => $type) {
            static::$db->query(
                "UPDATE bdus_cfg_fields SET db_type = ? WHERE table_name = 'items' AND name = ?",
                [$type, $fld],
                'boolean'
            );
        }
        return new Config(new Dot(), __DIR__ . '/../fixtures/cfg/', static::$db);
    }

    public function testUpdateWithEmptyIntegerStoresNull(): void
    {
        $cfg = $this->cfgWithTypes(['cat_ref' => 'INTEGER']);
        static::$db->query('UPDATE items SET cat_ref = 1 WHERE id = 2', [], 'boolean');

        $edit = new Edit(new Read(2, null, 'items', static::$db, $cfg));
        $edit->setCore(['cat_ref' => '']);
        $edit->persist(static::$db, $cfg);

        $row = static::$db->query('SELECT cat_ref FROM items WHERE id = 2', [], 'read')[0];
        $this->assertNull($row['cat_ref']);
    }

    public function testInsertWithEmptyIntegerStoresNull(): void
    {
        $cfg = $this->cfgWithTypes(['cat_ref' => 'INTEGER']);

        $core = [];
        foreach (['name' => 'empty-int', 'cat_ref' => ''] as $f => $v) {
            $core[$f] = ['name' => $f, '_val' => $v];
        }
        $res = \Record\Persist::all([
            'metadata' => ['tb_id' => 'items', 'rec_id' => null],
            'core' => $core, 'plugins' => [], 'manualLinks' => [], 'files' => [], 'geodata' => [], 'rs' => [],
        ], static::$db, $cfg);

        $id  = (int) $res['core']['id'];
        $row = static::$db->query('SELECT name, cat_ref FROM items WHERE id = ?', [$id], 'read')[0];
        $this->assertSame('empty-int', $row['name']);
        $this->assertNull($row['cat_ref']);
    }

    public function testTextColumnKeepsEmptyString(): void
    {
        $cfg = $this->cfgWithTypes(['status' => 'TEXT']);
        $this->assertSame('', ColumnValue::forColumn($cfg, 'items', 'status', ''));
    }

    public function testEmptyIsNullByType(): void
    {
        foreach (['INTEGER', 'bigint', 'SMALLINT', 'int(11)', 'decimal(10,2)', 'NUMERIC', 'BOOLEAN', 'REAL', 'DATE', 'TIMESTAMP'] as $t) {
            $this->assertTrue(ColumnValue::emptyIsNull($t), $t);
        }
        foreach (['TEXT', 'varchar(20)', '', null] as $t) {
            $this->assertFalse(ColumnValue::emptyIsNull($t), (string) $t);
        }
    }

    public function testNotNullIntegerWithoutDefaultReportsRequired(): void
    {
        static::$db->query('CREATE TABLE nn_test (id INTEGER NOT NULL, qty INTEGER NOT NULL, opt INTEGER)', [], 'boolean');
        $v = new Validator(static::$db, new Config(new Dot(), __DIR__ . '/../fixtures/cfg/', static::$db));

        $req = $v->validateFieldValue('qty', '', ['label' => 'Qty', 'db_type' => 'INTEGER'], 'nn_test', null);
        $this->assertSame('required', $req[0]['rule'] ?? null);

        $opt = $v->validateFieldValue('opt', '', ['label' => 'Opt', 'db_type' => 'INTEGER'], 'nn_test', null);
        $this->assertSame([], $opt, 'a nullable integer may be left empty');
    }
}
