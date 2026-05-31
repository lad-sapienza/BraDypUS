<?php

namespace Tests\Integration;

use Tests\Support\BdusTestCase;

/**
 * Integration tests for the RS (stratigraphic relations) endpoints in record_ctrl:
 *   addRs(), deleteRs(), getRsMatrix()
 * and for the rs_field exposure in buildTableSchema() / getRecord().
 *
 * The items fixture has "rs": "id", meaning the identifier used in RS
 * is the value of the `id` field.  Seed data provides one initial RS entry:
 *   tb=items, first='1', second='2', relation=1  (item 1 is_covered_by item 2)
 */
class RecordCtrlRsTest extends BdusTestCase
{
    private const TB = 'items';

    // ── buildTableSchema exposes rs_field ─────────────────────────────────────

    public function testGetRecordSchemaExposesRsField(): void
    {
        $ctrl = $this->makeController('Bdus\\Controllers\\Record', ['tb' => self::TB, 'id' => 1]);
        $res  = $this->callController($ctrl, 'getRecord');

        $this->assertArrayHasKey('rs_field', $res['schema']);
        $this->assertSame('id', $res['schema']['rs_field'],
            'rs_field in schema must match the "rs" config key value');
    }

    public function testGetRecordRsDataIncludedInResponse(): void
    {
        $ctrl = $this->makeController('Bdus\\Controllers\\Record', ['tb' => self::TB, 'id' => 1]);
        $res  = $this->callController($ctrl, 'getRecord');

        $this->assertArrayHasKey('rs', $res);
        $this->assertNotEmpty($res['rs'], 'Item 1 has one seeded RS entry');

        // Find the seeded relation (first='1', second='2', relation=1)
        $found = false;
        foreach ($res['rs'] as $row) {
            if ($row['first'] === '1' && $row['second'] === '2' && (int)$row['relation'] === 1) {
                $found = true;
                break;
            }
        }
        $this->assertTrue($found, 'Seeded RS entry (1 is_covered_by 2) must appear in getRecord()');
    }

    // ── addRs ─────────────────────────────────────────────────────────────────

    public function testAddRsInsertsRelation(): void
    {
        $ctrl = $this->makeController('Bdus\\Controllers\\Record', [], [
            'tb'       => self::TB,
            'first'    => '3',
            'relation' => 5,    // covers
            'second'   => '4',
        ]);
        $res = $this->callController($ctrl, 'addRs');

        $this->assertSame('success', $res['status']);
        $this->assertSame('ok_relation_add', $res['code']);
        $this->assertIsInt((int)$res['id']);
        $this->assertGreaterThan(0, (int)$res['id']);

        // Confirm row exists in DB
        $row = static::$db->query(
            "SELECT * FROM bdus_rs WHERE id = ?",
            [(int)$res['id']],
            'read'
        );
        $this->assertCount(1, $row);
        $this->assertSame('3', $row[0]['first']);
        $this->assertSame('4', $row[0]['second']);

        // Cleanup
        static::$db->query('DELETE FROM bdus_rs WHERE id = ?', [(int)$res['id']], 'boolean');
    }

    public function testAddRsRejectsDuplicateSameDirection(): void
    {
        // Seed entry: first='1', second='2', relation=1 already exists
        $ctrl = $this->makeController('Bdus\\Controllers\\Record', [], [
            'tb'       => self::TB,
            'first'    => '1',
            'relation' => 1,
            'second'   => '2',
        ]);
        $res = $this->callController($ctrl, 'addRs');

        $this->assertSame('error',                   $res['status']);
        $this->assertSame('relation_already_exist',  $res['code']);
    }

    public function testAddRsRejectsSymmetricDuplicate(): void
    {
        // The seeded relation is first='1', second='2', relation=1 (is_covered_by).
        // Its inverse is: first='2', second='1', relation=5 (covers).
        // Inserting the inverse must be rejected.
        $ctrl = $this->makeController('Bdus\\Controllers\\Record', [], [
            'tb'       => self::TB,
            'first'    => '2',
            'relation' => 5,    // covers — inverse of is_covered_by
            'second'   => '1',
        ]);
        $res = $this->callController($ctrl, 'addRs');

        $this->assertSame('error',                  $res['status']);
        $this->assertSame('relation_already_exist', $res['code']);
    }

    public function testAddRsRejectsMissingParams(): void
    {
        $ctrl = $this->makeController('Bdus\\Controllers\\Record', [], [
            'tb'    => self::TB,
            'first' => '1',
            // missing relation and second
        ]);
        $res = $this->callController($ctrl, 'addRs');

        $this->assertSame('error',             $res['status']);
        $this->assertSame('parameter_missing', $res['code']);
    }

    public function testAddRsSymmetricRelationsAllowed(): void
    {
        // Relations 9 (is_the_same_as) and 10 (is_bound_to) are self-inverse
        // (same code for both directions). Inserting (A,B,9) when (B,A,9) exists
        // should still be rejected (same inverse: 9 → 9).
        $ctrl = $this->makeController('Bdus\\Controllers\\Record', [], [
            'tb'       => self::TB,
            'first'    => '3',
            'relation' => 9,
            'second'   => '5',
        ]);
        $res1 = $this->callController($ctrl, 'addRs');
        $this->assertSame('success', $res1['status'], 'First insert of symmetric relation must succeed');
        $id1 = (int)$res1['id'];

        // Reverse direction — must be rejected
        $ctrl2 = $this->makeController('Bdus\\Controllers\\Record', [], [
            'tb'       => self::TB,
            'first'    => '5',
            'relation' => 9,
            'second'   => '3',
        ]);
        $res2 = $this->callController($ctrl2, 'addRs');
        $this->assertSame('error', $res2['status'], 'Reverse of symmetric relation must be rejected');

        // Cleanup
        static::$db->query('DELETE FROM bdus_rs WHERE id = ?', [$id1], 'boolean');
    }

    // ── deleteRs ──────────────────────────────────────────────────────────────

    public function testDeleteRsRemovesRow(): void
    {
        // Insert a throwaway relation
        static::$db->query(
            "INSERT INTO bdus_rs (tb, first, second, relation) VALUES (?, ?, ?, ?)",
            [self::TB, '4', '5', 2],
            'boolean'
        );
        $tmpId = (int) static::$db->query('SELECT last_insert_rowid() AS id', [], 'read')[0]['id'];

        $ctrl = $this->makeController('Bdus\\Controllers\\Record', ['id' => $tmpId]);
        $res  = $this->callController($ctrl, 'deleteRs');

        $this->assertSame('success',            $res['status']);
        $this->assertSame('ok_relation_erased', $res['code']);

        $row = static::$db->query('SELECT id FROM bdus_rs WHERE id = ?', [$tmpId], 'read');
        $this->assertEmpty($row, 'Row must be gone after deleteRs');
    }

    public function testDeleteRsUnknownIdReturnsError(): void
    {
        $ctrl = $this->makeController('Bdus\\Controllers\\Record', ['id' => 99999]);
        $res  = $this->callController($ctrl, 'deleteRs');

        $this->assertSame('error',     $res['status']);
        $this->assertSame('not_found', $res['code']);
    }

    public function testDeleteRsMissingIdReturnsError(): void
    {
        $ctrl = $this->makeController('Bdus\\Controllers\\Record', []);
        $res  = $this->callController($ctrl, 'deleteRs');

        $this->assertSame('error',             $res['status']);
        $this->assertSame('parameter_missing', $res['code']);
    }

    // ── getRsMatrix ───────────────────────────────────────────────────────────

    public function testGetRsMatrixReturnsAllNodes(): void
    {
        // No filter → all 5 seeded items must appear as nodes
        $ctrl = $this->makeController('Bdus\\Controllers\\Record', ['tb' => self::TB]);
        $res  = $this->callController($ctrl, 'getRsMatrix');

        $this->assertSame('id', $res['rs_field']);
        $this->assertCount(5, $res['nodes'], 'All 5 items must appear as nodes');

        // All in_filter because no filter was applied
        foreach ($res['nodes'] as $node) {
            $this->assertTrue($node['in_filter'], 'All nodes must be in_filter=true when no filter');
        }

        // Must include the seeded relation
        $this->assertCount(1, $res['relations']);
        $rel = $res['relations'][0];
        $this->assertSame('1', $rel['first']);
        $this->assertSame('2', $rel['second']);
        $this->assertSame(1,   (int)$rel['relation']);
    }

    public function testGetRsMatrixIncludesIsolatedNodes(): void
    {
        // Items 3, 4, 5 have no RS relations — they must still appear as nodes
        $ctrl = $this->makeController('Bdus\\Controllers\\Record', ['tb' => self::TB]);
        $res  = $this->callController($ctrl, 'getRsMatrix');

        $identifiers = array_column($res['nodes'], 'identifier');
        $this->assertContains('3', $identifiers, 'Isolated item 3 must appear as node');
        $this->assertContains('4', $identifiers, 'Isolated item 4 must appear as node');
        $this->assertContains('5', $identifiers, 'Isolated item 5 must appear as node');
    }

    public function testGetRsMatrixFilteredByJsonFilter(): void
    {
        // Filter to item 1 only; item 2 appears via its relation but not in filter
        $ctrl = $this->makeController('Bdus\\Controllers\\Record', [
            'tb'     => self::TB,
            'filter' => ['id' => ['_eq' => 1]],
        ]);
        $res = $this->callController($ctrl, 'getRsMatrix');

        $this->assertSame('success', $res['status'] ?? 'success');

        $byIdent  = array_column($res['nodes'], null, 'identifier');

        // Item 1 must be in_filter=true
        $this->assertArrayHasKey('1', $byIdent);
        $this->assertTrue($byIdent['1']['in_filter']);

        // Item 2 must appear (via RS) but in_filter=false
        $this->assertArrayHasKey('2', $byIdent);
        $this->assertFalse($byIdent['2']['in_filter']);

        // Items 3,4,5 must NOT appear (not in filter, not in any relation)
        $this->assertArrayNotHasKey('3', $byIdent);
        $this->assertArrayNotHasKey('4', $byIdent);
        $this->assertArrayNotHasKey('5', $byIdent);

        // One relation returned
        $this->assertCount(1, $res['relations']);
    }

    public function testGetRsMatrixMissingTbReturnsError(): void
    {
        $ctrl = $this->makeController('Bdus\\Controllers\\Record', []);
        $res  = $this->callController($ctrl, 'getRsMatrix');

        $this->assertSame('error',             $res['status']);
        $this->assertSame('parameter_missing', $res['code']);
    }

    public function testGetRsMatrixTableWithoutRsReturnsError(): void
    {
        // tags has no "rs" config key
        $ctrl = $this->makeController('Bdus\\Controllers\\Record', ['tb' => 'tags']);
        $res  = $this->callController($ctrl, 'getRsMatrix');

        $this->assertSame('error',              $res['status']);
        $this->assertSame('rs_not_configured',  $res['code']);
    }

    // ── has_fuzzy_date + chrono fields in nodes ───────────────────────────────

    public function testGetRsMatrixHasFuzzyDateFalseByDefault(): void
    {
        $ctrl = $this->makeController('Bdus\\Controllers\\Record', ['tb' => self::TB]);
        $res  = $this->callController($ctrl, 'getRsMatrix');

        $this->assertArrayHasKey('has_fuzzy_date', $res);
        $this->assertFalse($res['has_fuzzy_date']);
    }

    public function testGetRsMatrixHasFuzzyDateTrueWhenPluginActive(): void
    {
        // Activate fuzzy_date plugin
        $alter = new \DB\Alter(static::$db);
        $existing = (new \DB\Inspect(static::$db))->tableColumns(self::TB);
        foreach (['chrono_from INTEGER', 'chrono_to INTEGER', 'chrono_label VARCHAR(200)'] as $colDef) {
            [$col] = explode(' ', $colDef);
            if (!in_array($col, $existing, true)) {
                $alter->addFld(self::TB, $col, substr($colDef, strlen($col) + 1));
            }
        }
        $tbData = static::$cfg->get('tables.' . self::TB) ?: [];
        $tbData['name'] = self::TB;
        $tbData['fuzzy_date'] = true;
        static::$cfg->setTable($tbData);

        $ctrl = $this->makeController('Bdus\\Controllers\\Record', ['tb' => self::TB]);
        $res  = $this->callController($ctrl, 'getRsMatrix');

        $this->assertTrue($res['has_fuzzy_date']);

        // Each node must now carry chrono keys
        foreach ($res['nodes'] as $node) {
            $this->assertArrayHasKey('chrono_from',  $node);
            $this->assertArrayHasKey('chrono_to',    $node);
            $this->assertArrayHasKey('chrono_label', $node);
        }

        // Clean up: deactivate plugin so other tests are not affected
        $tbData['fuzzy_date'] = false;
        static::$cfg->setTable($tbData);
    }

    public function testGetRsMatrixChronoValuesMatchStoredData(): void
    {
        // Activate + seed chrono on item 1
        $alter = new \DB\Alter(static::$db);
        $existing = (new \DB\Inspect(static::$db))->tableColumns(self::TB);
        foreach (['chrono_from INTEGER', 'chrono_to INTEGER', 'chrono_label VARCHAR(200)'] as $colDef) {
            [$col] = explode(' ', $colDef);
            if (!in_array($col, $existing, true)) {
                $alter->addFld(self::TB, $col, substr($colDef, strlen($col) + 1));
            }
        }
        static::$db->query(
            'UPDATE ' . self::TB . ' SET chrono_from=?, chrono_to=?, chrono_label=? WHERE id=1',
            [-350, -300, 'Late 4th cent. BCE'],
            'boolean'
        );

        $tbData = static::$cfg->get('tables.' . self::TB) ?: [];
        $tbData['name'] = self::TB;
        $tbData['fuzzy_date'] = true;
        static::$cfg->setTable($tbData);

        $ctrl = $this->makeController('Bdus\\Controllers\\Record', ['tb' => self::TB]);
        $res  = $this->callController($ctrl, 'getRsMatrix');

        $nodeById = array_column($res['nodes'], null, 'db_id');
        $node1 = $nodeById[1] ?? null;

        $this->assertNotNull($node1);
        $this->assertSame(-350, $node1['chrono_from']);
        $this->assertSame(-300, $node1['chrono_to']);
        $this->assertSame('Late 4th cent. BCE', $node1['chrono_label']);

        // Deactivate
        $tbData['fuzzy_date'] = false;
        static::$cfg->setTable($tbData);
    }
}
