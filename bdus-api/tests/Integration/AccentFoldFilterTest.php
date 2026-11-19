<?php

namespace Tests\Integration;

use SQL\Filter\JsonFilter;
use Tests\Support\BdusTestCase;

/** #82: the JSON filter and the free-text search use the accent folding when it is switched on. */
class AccentFoldFilterTest extends BdusTestCase
{
    public static function setUpBeforeClass(): void
    {
        putenv('BRADYPUS_ACCENT_INSENSITIVE=1');
        parent::setUpBeforeClass();
        static::$db->query("INSERT INTO items (name, description) VALUES (?, ?)", ['Σερῆνε', 'perché sì'], 'boolean');
    }

    public static function tearDownAfterClass(): void
    {
        putenv('BRADYPUS_ACCENT_INSENSITIVE');
        parent::tearDownAfterClass();
    }

    private function names(array $filter): array
    {
        [$where, $vals] = (new JsonFilter(static::$cfg, 'items', 'sqlite'))->toSql($filter);
        return array_column(static::$db->query("SELECT name FROM items WHERE $where ORDER BY id", $vals, 'read'), 'name');
    }

    public function testContainsStartsWithAndNegationIgnoreAccents(): void
    {
        $this->assertSame(['Σερῆνε'], $this->names(['name' => ['_contains' => 'ερηνε']]));
        $this->assertSame(['Σερῆνε'], $this->names(['name' => ['_starts_with' => 'σερη']]));
        $this->assertSame(['Σερῆνε'], $this->names(['description' => ['_icontains' => 'PERCHE']]));
        $this->assertNotContains('Σερῆνε', $this->names(['name' => ['_ncontains' => 'ερηνε']]));
    }

    public function testEqualityStaysExact(): void
    {
        $this->assertSame([], $this->names(['name' => ['_eq' => 'Σερηνε']]));
        $this->assertSame(['Σερῆνε'], $this->names(['name' => ['_eq' => 'Σερῆνε']]));
    }
}
