<?php

declare(strict_types=1);

namespace Tests\Integration;

use Tests\Support\BdusTestCase;

/**
 * Tests for GET /api/chrono/related/{tb}/{id}  (Bdus\Controllers\Chrono::related).
 *
 * Covers:
 *   - Requires authentication
 *   - Returns success with sources array
 *   - Discovers related table with fuzzy_date via bdus_cfg_relations
 *   - Filters records by the FK value (current record id)
 *   - Returns fk_col in the source descriptor
 *   - Returns empty sources when the record has no related chrono data
 *   - Tables without fuzzy_date are not included even if a FK exists
 */
class ChronoRelatedTest extends BdusTestCase
{
    private const PARENT_TB  = 'items';
    private const RELATED_TB = 'reviews';

    private static int $parentId1;
    private static int $parentId2;
    private static int $relId1;
    private static int $relId2;

    public static function setUpBeforeClass(): void
    {
        parent::setUpBeforeClass();

        // Add chrono columns to reviews
        $alter   = new \DB\Alter(static::$db);
        $inspect = new \DB\Inspect(static::$db);
        $existing = $inspect->tableColumns(self::RELATED_TB);

        foreach ([
            'chrono_from INTEGER',
            'chrono_to INTEGER',
            'chrono_label VARCHAR(200)',
            'chrono_certainty INTEGER',
            'chrono_period VARCHAR(200)',
        ] as $colDef) {
            [$col] = explode(' ', $colDef);
            if (!in_array($col, $existing, true)) {
                $alter->addFld(self::RELATED_TB, $col, substr($colDef, strlen($col) + 1));
            }
        }

        // Enable fuzzy_date in config for reviews
        $tbData               = static::$cfg->get('tables.' . self::RELATED_TB) ?: [];
        $tbData['name']       = self::RELATED_TB;
        $tbData['fuzzy_date'] = true;
        static::$cfg->setTable($tbData);

        // Register FK relation: reviews.item_ref → items.id
        static::$db->query(
            'INSERT INTO bdus_cfg_relations (from_tb, from_col, to_tb, to_col, on_delete)
             VALUES (?, ?, ?, ?, ?)',
            [self::RELATED_TB, 'item_ref', self::PARENT_TB, 'id', 'RESTRICT'],
            'boolean'
        );

        // Two parent records
        self::$parentId1 = (int) static::$db->query(
            'INSERT INTO items (creator, name) VALUES (?, ?)',
            [1, 'Density-Parent-A'],
            'id'
        );
        self::$parentId2 = (int) static::$db->query(
            'INSERT INTO items (creator, name) VALUES (?, ?)',
            [1, 'Density-Parent-B'],
            'id'
        );

        // Two reviews with chrono data, both linked to parent1
        self::$relId1 = (int) static::$db->query(
            'INSERT INTO reviews (item_ref, reviewer, chrono_from, chrono_to, chrono_certainty)
             VALUES (?, ?, ?, ?, ?)',
            [self::$parentId1, 'alice', -100, 100, 1],
            'id'
        );
        self::$relId2 = (int) static::$db->query(
            'INSERT INTO reviews (item_ref, reviewer, chrono_from, chrono_to, chrono_certainty)
             VALUES (?, ?, ?, ?, ?)',
            [self::$parentId1, 'bob', 200, 400, 2],
            'id'
        );
    }

    public static function tearDownAfterClass(): void
    {
        $tbData               = static::$cfg->get('tables.' . self::RELATED_TB) ?: [];
        $tbData['name']       = self::RELATED_TB;
        $tbData['fuzzy_date'] = false;
        static::$cfg->setTable($tbData);
        parent::tearDownAfterClass();
    }

    // ── Auth ──────────────────────────────────────────────────────────────────

    public function testRequiresAuth(): void
    {
        $this->setPrivilege(99);
        $ctrl = $this->makeController(
            'Bdus\\Controllers\\Chrono',
            ['tb' => self::PARENT_TB, 'id' => (string) self::$parentId1]
        );
        $res = $this->callController($ctrl, 'related');
        $this->assertSame('error',                $res['status']);
        $this->assertSame('not_enough_privilege', $res['code']);
    }

    // ── Response structure ────────────────────────────────────────────────────

    public function testReturnsSuccess(): void
    {
        $this->setPrivilege(30);
        $ctrl = $this->makeController(
            'Bdus\\Controllers\\Chrono',
            ['tb' => self::PARENT_TB, 'id' => (string) self::$parentId1]
        );
        $res = $this->callController($ctrl, 'related');
        $this->assertSame('success', $res['status']);
        $this->assertArrayHasKey('sources', $res);
        $this->assertIsArray($res['sources']);
    }

    public function testFindsRelatedTable(): void
    {
        $this->setPrivilege(30);
        $ctrl = $this->makeController(
            'Bdus\\Controllers\\Chrono',
            ['tb' => self::PARENT_TB, 'id' => (string) self::$parentId1]
        );
        $res = $this->callController($ctrl, 'related');
        $this->assertCount(1, $res['sources']);
        $this->assertSame(self::RELATED_TB, $res['sources'][0]['tb_id']);
    }

    public function testFkColPresent(): void
    {
        $this->setPrivilege(30);
        $ctrl = $this->makeController(
            'Bdus\\Controllers\\Chrono',
            ['tb' => self::PARENT_TB, 'id' => (string) self::$parentId1]
        );
        $res = $this->callController($ctrl, 'related');
        $this->assertSame('item_ref', $res['sources'][0]['fk_col']);
    }

    public function testRecordsFilteredByFk(): void
    {
        $this->setPrivilege(30);
        $ctrl = $this->makeController(
            'Bdus\\Controllers\\Chrono',
            ['tb' => self::PARENT_TB, 'id' => (string) self::$parentId1]
        );
        $res  = $this->callController($ctrl, 'related');
        $ids  = array_column($res['sources'][0]['records'], 'id');
        $this->assertContains(self::$relId1, $ids);
        $this->assertContains(self::$relId2, $ids);
    }

    public function testRecordStructure(): void
    {
        $this->setPrivilege(30);
        $ctrl = $this->makeController(
            'Bdus\\Controllers\\Chrono',
            ['tb' => self::PARENT_TB, 'id' => (string) self::$parentId1]
        );
        $res = $this->callController($ctrl, 'related');
        $rec = $res['sources'][0]['records'][0];
        $this->assertArrayHasKey('id',        $rec);
        $this->assertArrayHasKey('label',     $rec);
        $this->assertArrayHasKey('from',      $rec);
        $this->assertArrayHasKey('to',        $rec);
        $this->assertArrayHasKey('certainty', $rec);
        $this->assertIsInt($rec['certainty']);
    }

    // ── FK isolation ──────────────────────────────────────────────────────────

    public function testEmptyWhenNoLinkedRecords(): void
    {
        $this->setPrivilege(30);
        // parent2 has no reviews
        $ctrl = $this->makeController(
            'Bdus\\Controllers\\Chrono',
            ['tb' => self::PARENT_TB, 'id' => (string) self::$parentId2]
        );
        $res = $this->callController($ctrl, 'related');
        $this->assertSame('success', $res['status']);
        $this->assertEmpty($res['sources']);
    }

    // ── Table without fuzzy_date excluded ─────────────────────────────────────

    public function testTableWithoutFuzzyDateExcluded(): void
    {
        $this->setPrivilege(30);
        // 'tags' is a plugin of items but has no FK in bdus_cfg_relations
        // and no fuzzy_date — it must not appear
        $ctrl = $this->makeController(
            'Bdus\\Controllers\\Chrono',
            ['tb' => self::PARENT_TB, 'id' => (string) self::$parentId1]
        );
        $res   = $this->callController($ctrl, 'related');
        $tbIds = array_column($res['sources'], 'tb_id');
        $this->assertNotContains('tags',  $tbIds);
        $this->assertNotContains('items', $tbIds);
    }

    // ── Invalid parameters ────────────────────────────────────────────────────

    public function testInvalidTbReturnsError(): void
    {
        $this->setPrivilege(30);
        $ctrl = $this->makeController(
            'Bdus\\Controllers\\Chrono',
            ['tb' => 'bad-name!', 'id' => '1']
        );
        $res = $this->callController($ctrl, 'related');
        $this->assertSame('error',              $res['status']);
        $this->assertSame('invalid_parameters', $res['code']);
    }

    public function testZeroIdReturnsError(): void
    {
        $this->setPrivilege(30);
        $ctrl = $this->makeController(
            'Bdus\\Controllers\\Chrono',
            ['tb' => self::PARENT_TB, 'id' => '0']
        );
        $res = $this->callController($ctrl, 'related');
        $this->assertSame('error',              $res['status']);
        $this->assertSame('invalid_parameters', $res['code']);
    }
}
