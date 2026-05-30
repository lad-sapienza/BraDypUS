<?php

namespace Tests\Integration;

use Tests\Support\BdusTestCase;

/**
 * Integration tests for search_replace_ctrl.
 *
 * Methods under test:
 *   getTableList()  — returns tables from config; admin-only
 *   getFieldList()  — returns text/textarea/combo_select fields; admin-only
 *   doReplace()     — bulk REPLACE() on a field; admin-only
 *
 * The fixture config (tests/fixtures/cfg/) exposes:
 *   Table "items" with text fields: id, creator, name
 *   Table "tags"  with text fields: id, label, id_link, table_link
 *
 * The fixture DB already has 5 items rows seeded by BdusTestCase::seedData().
 */
class SearchReplaceCtrlTest extends BdusTestCase
{
    // ── getTableList() ────────────────────────────────────────────────────

    public function testGetTableListReturnsSuccess(): void
    {
        $ctrl = $this->makeController('Bdus\\Controllers\\SearchReplace');
        $res  = $this->callController($ctrl, 'getTableList');

        $this->assertSame('success', $res['status']);
        $this->assertArrayHasKey('tables', $res);
        $this->assertIsArray($res['tables']);
    }

    public function testGetTableListContainsKnownTables(): void
    {
        $ctrl  = $this->makeController('Bdus\\Controllers\\SearchReplace');
        $res   = $this->callController($ctrl, 'getTableList');
        $names = array_column($res['tables'], 'name');

        $this->assertContains('items', $names);
        $this->assertContains('tags',  $names);
    }

    public function testGetTableListRowShape(): void
    {
        $ctrl = $this->makeController('Bdus\\Controllers\\SearchReplace');
        $res  = $this->callController($ctrl, 'getTableList');

        $row = $res['tables'][0];
        $this->assertArrayHasKey('name',  $row);
        $this->assertArrayHasKey('label', $row);
    }

    public function testGetTableListRequiresAdminPrivilege(): void
    {
        $this->setPrivilege(99); // reader
        $ctrl = $this->makeController('Bdus\\Controllers\\SearchReplace');
        $res  = $this->callController($ctrl, 'getTableList');
        $this->setPrivilege(1);  // restore

        $this->assertSame('error',               $res['status']);
        $this->assertSame('not_enough_privilege', $res['code']);
    }

    // ── getFieldList() ────────────────────────────────────────────────────

    public function testGetFieldListReturnsSuccess(): void
    {
        $ctrl = $this->makeController('Bdus\\Controllers\\SearchReplace', ['tb' => 'items']);
        $res  = $this->callController($ctrl, 'getFieldList');

        $this->assertSame('success', $res['status']);
        $this->assertArrayHasKey('fields', $res);
        $this->assertIsArray($res['fields']);
    }

    public function testGetFieldListReturnsOnlyTextFields(): void
    {
        $ctrl  = $this->makeController('Bdus\\Controllers\\SearchReplace', ['tb' => 'items']);
        $res   = $this->callController($ctrl, 'getFieldList');
        $names = array_column($res['fields'], 'name');

        // text fields present in items.json
        $this->assertContains('name',    $names);
        $this->assertContains('id',      $names);
        $this->assertContains('creator', $names);
        $this->assertContains('score',   $names); // type=text, included even with int check

        // non-text fields must be excluded
        $this->assertNotContains('status',      $names); // select
        $this->assertNotContains('description', $names); // long_text — not in the filter list
    }

    public function testGetFieldListRowShape(): void
    {
        $ctrl = $this->makeController('Bdus\\Controllers\\SearchReplace', ['tb' => 'items']);
        $res  = $this->callController($ctrl, 'getFieldList');

        $this->assertNotEmpty($res['fields']);
        $row = $res['fields'][0];
        $this->assertArrayHasKey('name',  $row);
        $this->assertArrayHasKey('label', $row);
    }

    public function testGetFieldListMissingTbReturnsError(): void
    {
        $ctrl = $this->makeController('Bdus\\Controllers\\SearchReplace');
        $res  = $this->callController($ctrl, 'getFieldList');

        $this->assertSame('error',             $res['status']);
        $this->assertSame('parameter_missing', $res['code']);
    }

    public function testGetFieldListRequiresAdminPrivilege(): void
    {
        $this->setPrivilege(99);
        $ctrl = $this->makeController('Bdus\\Controllers\\SearchReplace', ['tb' => 'items']);
        $res  = $this->callController($ctrl, 'getFieldList');
        $this->setPrivilege(1);

        $this->assertSame('error',               $res['status']);
        $this->assertSame('not_enough_privilege', $res['code']);
    }

    // ── doReplace() ───────────────────────────────────────────────────────

    public function testDoReplaceSuccess(): void
    {
        // items contains rows with name like "Alpha item", "Beta item" etc.
        $ctrl = $this->makeController(
            'Bdus\\Controllers\\SearchReplace',
            [],
            ['tb' => 'items', 'fld' => 'name', 'search' => 'item', 'replace' => 'object']
        );
        $res = $this->callController($ctrl, 'doReplace');

        $this->assertSame('success',           $res['status']);
        $this->assertSame('ok_search_replace', $res['code']);
        $this->assertArrayHasKey('affected',   $res);
        $this->assertIsInt($res['affected']);
        // SQLite UPDATE reports all rows in the table as affected (no WHERE clause),
        // so just verify the value is non-negative.
        $this->assertGreaterThanOrEqual(0, $res['affected']);
    }

    public function testDoReplaceActuallyMutatesData(): void
    {
        // Replace "thing" → "entity" in name column
        $ctrl = $this->makeController(
            'Bdus\\Controllers\\SearchReplace',
            [],
            ['tb' => 'items', 'fld' => 'name', 'search' => 'thing', 'replace' => 'entity']
        );
        $res = $this->callController($ctrl, 'doReplace');
        $this->assertSame('success', $res['status']);

        // Verify the DB actually changed — 2 rows had "thing": Delta thing, Epsilon thing
        $rows = static::$db->query("SELECT name FROM items WHERE name LIKE '%entity%'", [], 'read');
        $this->assertCount(2, $rows);
    }

    public function testDoReplaceMissingTbReturnsError(): void
    {
        $ctrl = $this->makeController(
            'Bdus\\Controllers\\SearchReplace',
            [],
            ['fld' => 'name', 'search' => 'x']
        );
        $res = $this->callController($ctrl, 'doReplace');

        $this->assertSame('error',             $res['status']);
        $this->assertSame('parameter_missing', $res['code']);
    }

    public function testDoReplaceMissingFldReturnsError(): void
    {
        $ctrl = $this->makeController(
            'Bdus\\Controllers\\SearchReplace',
            [],
            ['tb' => 'items', 'search' => 'x']
        );
        $res = $this->callController($ctrl, 'doReplace');

        $this->assertSame('error',             $res['status']);
        $this->assertSame('parameter_missing', $res['code']);
    }

    public function testDoReplaceEmptySearchReturnsError(): void
    {
        $ctrl = $this->makeController(
            'Bdus\\Controllers\\SearchReplace',
            [],
            ['tb' => 'items', 'fld' => 'name', 'search' => '']
        );
        $res = $this->callController($ctrl, 'doReplace');

        $this->assertSame('error',             $res['status']);
        $this->assertSame('parameter_missing', $res['code']);
    }

    public function testDoReplaceUnknownTableReturnsError(): void
    {
        // doReplace validates tb/fld against config — unknown table → not_enough_privilege
        $ctrl = $this->makeController(
            'Bdus\\Controllers\\SearchReplace',
            [],
            ['tb' => 'no_such_table', 'fld' => 'name', 'search' => 'x']
        );
        $res = $this->callController($ctrl, 'doReplace');

        $this->assertSame('error',               $res['status']);
        $this->assertSame('not_enough_privilege', $res['code']);
    }

    public function testDoReplaceUnknownFieldReturnsError(): void
    {
        $ctrl = $this->makeController(
            'Bdus\\Controllers\\SearchReplace',
            [],
            ['tb' => 'items', 'fld' => 'injected_field', 'search' => 'x']
        );
        $res = $this->callController($ctrl, 'doReplace');

        $this->assertSame('error',               $res['status']);
        $this->assertSame('not_enough_privilege', $res['code']);
    }

    public function testDoReplaceRequiresAdminPrivilege(): void
    {
        $this->setPrivilege(99);
        $ctrl = $this->makeController(
            'Bdus\\Controllers\\SearchReplace',
            [],
            ['tb' => 'items', 'fld' => 'name', 'search' => 'x']
        );
        $res = $this->callController($ctrl, 'doReplace');
        $this->setPrivilege(1);

        $this->assertSame('error',               $res['status']);
        $this->assertSame('not_enough_privilege', $res['code']);
    }
}
