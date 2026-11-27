<?php

namespace Tests\Integration;

use Config\Relations;
use Tests\Support\BdusTestCase;

/** #88: Config::saveRelation() refuses numeric <-> text column pairs. */
class ConfigSaveRelationTypeMismatchTest extends BdusTestCase
{
    public static function tearDownAfterClass(): void
    {
        static::$db->query('DELETE FROM bdus_cfg_relations', [], 'boolean');
        static::$db->query('DELETE FROM bdus_cfg_indexes',   [], 'boolean');
        parent::tearDownAfterClass();
    }

    protected function setUp(): void
    {
        static::$db->query('DELETE FROM bdus_cfg_relations', [], 'boolean');
        static::$db->query('DELETE FROM bdus_cfg_indexes',   [], 'boolean');
    }

    private function save(array $body, array $get = []): array
    {
        return $this->callController(
            $this->makeController('Bdus\\Controllers\\Config', $get, $body),
            'saveRelation'
        );
    }

    public function testRejectsNumericToTextAndNothingIsPersisted(): void
    {
        $res = $this->save(['from_tb' => 'items', 'from_col' => 'cat_ref', 'to_tb' => 'categories', 'to_col' => 'name']);
        $this->assertSame('error', $res['status']);
        $this->assertSame('relation_type_mismatch', $res['code']);
        $this->assertEmpty(static::$db->query('SELECT id FROM bdus_cfg_relations', [], 'read'));
    }

    public function testRejectsTextToNumeric(): void
    {
        $res = $this->save(['from_tb' => 'items', 'from_col' => 'name', 'to_tb' => 'categories', 'to_col' => 'id']);
        $this->assertSame('relation_type_mismatch', $res['code']);
    }

    public function testAcceptsMatchingTypes(): void
    {
        $res = $this->save(['from_tb' => 'items', 'from_col' => 'cat_ref', 'to_tb' => 'categories', 'to_col' => 'id']);
        $this->assertContains($res['status'], ['success', 'warning']);
    }

    public function testUpdateToMismatchingColumnIsRejected(): void
    {
        $created = $this->save(['from_tb' => 'items', 'from_col' => 'cat_ref', 'to_tb' => 'categories', 'to_col' => 'id']);
        $res = $this->save(
            ['from_tb' => 'items', 'from_col' => 'cat_ref', 'to_tb' => 'categories', 'to_col' => 'name'],
            ['id' => (string) $created['id']]
        );
        $this->assertSame('relation_type_mismatch', $res['code']);
        $row = static::$db->query('SELECT to_col FROM bdus_cfg_relations WHERE id=?', [$created['id']], 'read');
        $this->assertSame('id', $row[0]['to_col']);
    }

    #[\PHPUnit\Framework\Attributes\DataProvider('typeProvider')]
    public function testTypeClass(string $type, ?string $expected): void
    {
        $this->assertSame($expected, Relations::typeClass($type));
    }

    public static function typeProvider(): array
    {
        return [
            ['INTEGER', 'numeric'], ['integer', 'numeric'], ['int(11)', 'numeric'], ['bigint', 'numeric'],
            ['double precision', 'numeric'], ['decimal(10,2)', 'numeric'], ['REAL', 'numeric'],
            ['TEXT', 'text'], ['varchar(255)', 'text'], ['character varying', 'text'],
            ['timestamp without time zone', null], ['interval', null], ['point', null], ['date', null], ['', null],
        ];
    }
}
