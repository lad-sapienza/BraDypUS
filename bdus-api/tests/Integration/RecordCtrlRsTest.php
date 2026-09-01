<?php

namespace Tests\Integration;

use Tests\Support\BdusTestCase;

/**
 * Integration tests for the RS (stratigraphic relations) endpoints:
 *   addRs(), deleteRs(), getRsMatrix()
 * and for the rs exposure in buildTableSchema() / getRecord().
 *
 * The items fixture has "rs": 1 (boolean flag).
 * Seed data: tb=items, first=1, second=2, relation=1  (item 1 is_covered_by item 2)
 *
 * first/second are now INTEGER record ids (not text identifiers).
 * getRs() returns first_label/second_label for display (resolved via id_field).
 */
class RecordCtrlRsTest extends BdusTestCase
{
    private const TB = 'items';

    // ── buildTableSchema exposes rs flag ──────────────────────────────────────

    public function testGetRecordSchemaExposesRsFlag(): void
    {
        $ctrl = $this->makeController('Bdus\\Controllers\\Record', ['tb' => self::TB, 'id' => 1]);
        $res  = $this->callController($ctrl, 'getRecord');

        $this->assertArrayHasKey('rs', $res['schema']);
        $this->assertTrue($res['schema']['rs'], 'rs in schema must be true when rs is configured');
    }

    public function testGetRecordSchemaExposesIdField(): void
    {
        $ctrl = $this->makeController('Bdus\\Controllers\\Record', ['tb' => self::TB, 'id' => 1]);
        $res  = $this->callController($ctrl, 'getRecord');

        $this->assertArrayHasKey('id_field', $res['schema']);
        $this->assertSame('id', $res['schema']['id_field']);
    }

    public function testGetRecordRsDataIncludedInResponse(): void
    {
        $ctrl = $this->makeController('Bdus\\Controllers\\Record', ['tb' => self::TB, 'id' => 1]);
        $res  = $this->callController($ctrl, 'getRecord');

        $this->assertArrayHasKey('rs', $res);
        $this->assertNotEmpty($res['rs'], 'Item 1 has one seeded RS entry');

        $found = false;
        foreach ($res['rs'] as $row) {
            if (
                (int)$row['first'] === 1 &&
                (int)$row['second'] === 2 &&
                (int)$row['relation'] === 1
            ) {
                $found = true;
                // Labels must be provided (id_field = 'id', so label = stringified id)
                $this->assertArrayHasKey('first_label',  $row);
                $this->assertArrayHasKey('second_label', $row);
                $this->assertSame('1', $row['first_label']);
                $this->assertSame('2', $row['second_label']);
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
            'first'    => 3,
            'relation' => 5,    // covers
            'second'   => 4,
        ]);
        $res = $this->callController($ctrl, 'addRs');

        $this->assertSame('success',        $res['status']);
        $this->assertSame('ok_relation_add', $res['code']);
        $this->assertGreaterThan(0, (int)$res['id']);

        $row = static::$db->query(
            "SELECT * FROM bdus_rs WHERE id = ?",
            [(int)$res['id']],
            'read'
        );
        $this->assertCount(1, $row);
        $this->assertSame(3, (int)$row[0]['first']);
        $this->assertSame(4, (int)$row[0]['second']);

        static::$db->query('DELETE FROM bdus_rs WHERE id = ?', [(int)$res['id']], 'boolean');
    }

    public function testAddRsRejectsDuplicateSameDirection(): void
    {
        $ctrl = $this->makeController('Bdus\\Controllers\\Record', [], [
            'tb'       => self::TB,
            'first'    => 1,
            'relation' => 1,
            'second'   => 2,
        ]);
        $res = $this->callController($ctrl, 'addRs');

        $this->assertSame('error',                  $res['status']);
        $this->assertSame('relation_already_exist', $res['code']);
    }

    public function testAddRsRejectsSymmetricDuplicate(): void
    {
        // Seeded: first=1, second=2, relation=1 (is_covered_by).
        // Inverse: first=2, second=1, relation=5 (covers) → must be rejected.
        $ctrl = $this->makeController('Bdus\\Controllers\\Record', [], [
            'tb'       => self::TB,
            'first'    => 2,
            'relation' => 5,
            'second'   => 1,
        ]);
        $res = $this->callController($ctrl, 'addRs');

        $this->assertSame('error',                  $res['status']);
        $this->assertSame('relation_already_exist', $res['code']);
    }

    public function testAddRsRejectsMissingParams(): void
    {
        $ctrl = $this->makeController('Bdus\\Controllers\\Record', [], [
            'tb'    => self::TB,
            'first' => 1,
        ]);
        $res = $this->callController($ctrl, 'addRs');

        $this->assertSame('error',             $res['status']);
        $this->assertSame('parameter_missing', $res['code']);
    }

    public function testAddRsSymmetricRelationsAllowed(): void
    {
        $ctrl = $this->makeController('Bdus\\Controllers\\Record', [], [
            'tb'       => self::TB,
            'first'    => 3,
            'relation' => 9,
            'second'   => 5,
        ]);
        $res1 = $this->callController($ctrl, 'addRs');
        $this->assertSame('success', $res1['status'], 'First insert of symmetric relation must succeed');
        $id1 = (int)$res1['id'];

        $ctrl2 = $this->makeController('Bdus\\Controllers\\Record', [], [
            'tb'       => self::TB,
            'first'    => 5,
            'relation' => 9,
            'second'   => 3,
        ]);
        $res2 = $this->callController($ctrl2, 'addRs');
        $this->assertSame('error', $res2['status'], 'Reverse of symmetric relation must be rejected');

        static::$db->query('DELETE FROM bdus_rs WHERE id = ?', [$id1], 'boolean');
    }

    // ── deleteRs ──────────────────────────────────────────────────────────────

    public function testDeleteRsRemovesRow(): void
    {
        static::$db->query(
            "INSERT INTO bdus_rs (tb, first, second, relation) VALUES (?, ?, ?, ?)",
            [self::TB, 4, 5, 2],
            'boolean'
        );
        $tmpId = (int) static::$db->query('SELECT last_insert_rowid() AS id', [], 'read')[0]['id'];

        $ctrl = $this->makeController('Bdus\\Controllers\\Record', ['id' => $tmpId]);
        $res  = $this->callController($ctrl, 'deleteRs');

        $this->assertSame('success',            $res['status']);
        $this->assertSame('ok_relation_erased', $res['code']);

        $row = static::$db->query('SELECT id FROM bdus_rs WHERE id = ?', [$tmpId], 'read');
        $this->assertEmpty($row);
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
        $ctrl = $this->makeController('Bdus\\Controllers\\Record', ['tb' => self::TB]);
        $res  = $this->callController($ctrl, 'getRsMatrix');

        // rs_field no longer in response
        $this->assertArrayNotHasKey('rs_field', $res);
        $this->assertCount(5, $res['nodes'], 'All 5 items must appear as nodes');

        foreach ($res['nodes'] as $node) {
            $this->assertTrue($node['in_filter'], 'All nodes must be in_filter=true when no filter');
        }

        $this->assertCount(1, $res['relations']);
        $rel = $res['relations'][0];
        // first/second are now integers
        $this->assertSame(1, (int)$rel['first']);
        $this->assertSame(2, (int)$rel['second']);
        $this->assertSame(1, (int)$rel['relation']);
    }

    public function testGetRsMatrixIncludesIsolatedNodes(): void
    {
        $ctrl = $this->makeController('Bdus\\Controllers\\Record', ['tb' => self::TB]);
        $res  = $this->callController($ctrl, 'getRsMatrix');

        // Items with id_field='id': identifier = stringified db_id
        $dbIds = array_column($res['nodes'], 'db_id');
        $this->assertContains(3, array_map('intval', $dbIds), 'Isolated item 3 must appear');
        $this->assertContains(4, array_map('intval', $dbIds), 'Isolated item 4 must appear');
        $this->assertContains(5, array_map('intval', $dbIds), 'Isolated item 5 must appear');
    }

    public function testGetRsMatrixFilteredByJsonFilter(): void
    {
        $ctrl = $this->makeController('Bdus\\Controllers\\Record', [
            'tb'     => self::TB,
            'filter' => ['id' => ['_eq' => 1]],
        ]);
        $res = $this->callController($ctrl, 'getRsMatrix');

        $this->assertSame('success', $res['status'] ?? 'success');

        $byDbId = array_column($res['nodes'], null, 'db_id');

        $this->assertArrayHasKey(1, $byDbId);
        $this->assertTrue($byDbId[1]['in_filter']);

        $this->assertArrayHasKey(2, $byDbId);
        $this->assertFalse($byDbId[2]['in_filter']);

        $this->assertArrayNotHasKey(3, $byDbId);
        $this->assertArrayNotHasKey(4, $byDbId);
        $this->assertArrayNotHasKey(5, $byDbId);

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
        $ctrl = $this->makeController('Bdus\\Controllers\\Record', ['tb' => 'tags']);
        $res  = $this->callController($ctrl, 'getRsMatrix');

        $this->assertSame('error',             $res['status']);
        $this->assertSame('rs_not_configured', $res['code']);
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
        $alter   = new \DB\Alter(static::$db);
        $existing = (new \DB\Inspect(static::$db))->tableColumns(self::TB);
        foreach (['chrono_from INTEGER', 'chrono_to INTEGER', 'chrono_label VARCHAR(200)'] as $colDef) {
            [$col] = explode(' ', $colDef);
            if (!in_array($col, $existing, true)) {
                $alter->addFld(self::TB, $col, substr($colDef, strlen($col) + 1));
            }
        }
        $tbData = static::$cfg->get('tables.' . self::TB) ?: [];
        $tbData['name']       = self::TB;
        $tbData['fuzzy_date'] = true;
        static::$cfg->setTable($tbData);

        $ctrl = $this->makeController('Bdus\\Controllers\\Record', ['tb' => self::TB]);
        $res  = $this->callController($ctrl, 'getRsMatrix');

        $this->assertTrue($res['has_fuzzy_date']);
        foreach ($res['nodes'] as $node) {
            $this->assertArrayHasKey('chrono_from',  $node);
            $this->assertArrayHasKey('chrono_to',    $node);
            $this->assertArrayHasKey('chrono_label', $node);
        }

        $tbData['fuzzy_date'] = false;
        static::$cfg->setTable($tbData);
    }

    public function testGetRsMatrixChronoValuesMatchStoredData(): void
    {
        $alter   = new \DB\Alter(static::$db);
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
        $tbData['name']       = self::TB;
        $tbData['fuzzy_date'] = true;
        static::$cfg->setTable($tbData);

        $ctrl = $this->makeController('Bdus\\Controllers\\Record', ['tb' => self::TB]);
        $res  = $this->callController($ctrl, 'getRsMatrix');

        $nodeById = array_column($res['nodes'], null, 'db_id');
        $node1    = $nodeById[1] ?? null;
        $this->assertNotNull($node1);
        $this->assertSame(-350, $node1['chrono_from']);
        $this->assertSame(-300, $node1['chrono_to']);
        $this->assertSame('Late 4th cent. BCE', $node1['chrono_label']);

        $tbData['fuzzy_date'] = false;
        static::$cfg->setTable($tbData);
    }
}
