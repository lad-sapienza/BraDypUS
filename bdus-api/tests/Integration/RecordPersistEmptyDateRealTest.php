<?php

namespace Tests\Integration;

use Adbar\Dot;
use Config\Config;
use Record\Edit;
use Record\Read;
use Tests\Support\BdusTestCase;

/**
 * #84: an empty value written to a DATE or REAL column is stored as NULL (PostgreSQL and
 * MySQL refuse '' there); columns of other types are left alone.
 */
class RecordPersistEmptyDateRealTest extends BdusTestCase
{
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

    private function save(Config $cfg, int $id, array $data): void
    {
        $edit = new Edit(new Read($id, null, 'items', static::$db, $cfg));
        $edit->setCore($data);
        $edit->persist(static::$db, $cfg);
    }

    public function testEmptyValuesBecomeNullInDateAndRealColumnsOnly(): void
    {
        $cfg = $this->cfgWithTypes(['birth_date' => 'DATE', 'score' => 'REAL', 'status' => 'TEXT']);
        static::$db->query("UPDATE items SET birth_date='2020-01-02', score='1.5', status='open' WHERE id = 2", [], 'boolean');

        $this->save($cfg, 2, ['birth_date' => '', 'score' => '  ', 'status' => '']);

        $row = static::$db->query('SELECT birth_date, score, status FROM items WHERE id = 2', [], 'read')[0];
        $this->assertNull($row['birth_date']);
        $this->assertNull($row['score']);
        $this->assertSame('', $row['status'], 'a TEXT column keeps the empty string');
    }

    public function testNonEmptyValuesAreWrittenAsTheyAre(): void
    {
        $cfg = $this->cfgWithTypes(['birth_date' => 'DATE', 'score' => 'REAL']);
        $this->save($cfg, 2, ['birth_date' => '2021-03-04', 'score' => '2.75']);

        $row = static::$db->query('SELECT birth_date, score FROM items WHERE id = 2', [], 'read')[0];
        $this->assertSame('2021-03-04', $row['birth_date']);
        $this->assertEquals(2.75, $row['score']);
    }
}
