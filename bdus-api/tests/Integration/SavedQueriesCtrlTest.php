<?php

namespace Tests\Integration;

use Tests\Support\BdusTestCase;

/**
 * Integration tests for saved_queries_ctrl v5 endpoints:
 *   listQueries(), saveQuery(), shareQuery(), unshareQuery(), deleteQuery()
 *
 * The queries table is created in createSchema() and seeded in seedData().
 * All v5 methods use POST bodies; they are placed in $post (and $get/$request).
 */
class SavedQueriesCtrlTest extends BdusTestCase
{
    // ── Schema extension ──────────────────────────────────────────────────────

    protected static function createSchema(): void
    {
        // Build base schema (items, tags, log, userlinks, rs, geodata, files, file_links)
        parent::createSchema();

        // bdus_users table is required by saved_queries FK (present in real DB but not in base schema)
        static::$db->execInTransaction('
            CREATE TABLE bdus_users (
                id        INTEGER PRIMARY KEY AUTOINCREMENT,
                name      TEXT    NOT NULL,
                email     TEXT    NOT NULL,
                privilege INTEGER NOT NULL DEFAULT 99
            )
        ');

        // The new v5 queries table (no text/vals/date — fresh install schema)
        static::$db->execInTransaction('
            CREATE TABLE bdus_queries (
                id         INTEGER PRIMARY KEY AUTOINCREMENT,
                user_id    INTEGER NOT NULL,
                created_at INTEGER,
                name       TEXT    NOT NULL,
                tb         TEXT    NOT NULL,
                query      TEXT,
                is_global  INTEGER
            )
        ');
    }

    // ── Seed extension ────────────────────────────────────────────────────────

    protected static function seedData(): void
    {
        parent::seedData();

        // Insert the test user (id=1, matches BdusTestCase::CurrentUser id=1)
        static::$db->execInTransaction(
            "INSERT INTO bdus_users (id, name, email, privilege) VALUES (1, 'Test Admin', 'test@example.com', 1)"
        );

        // Seed one query owned by user 1
        static::$db->execInTransaction(
            "INSERT INTO bdus_queries (user_id, created_at, name, tb, query, is_global)
             VALUES (1, " . time() . ", 'My first search', 'items',
                     '{\"search_type\":\"sqlExpert\",\"querytext\":\"status = ''active''\"}', 0)"
        );
    }

    // ── listQueries ───────────────────────────────────────────────────────────

    public function testListQueriesReturnsSuccess(): void
    {
        $ctrl = $this->makeController('saved_queries_ctrl');
        $res  = $this->callController($ctrl, 'listQueries');

        $this->assertSame('success', $res['status']);
        $this->assertArrayHasKey('queries', $res);
        $this->assertIsArray($res['queries']);
    }

    public function testListQueriesContainsSeedRow(): void
    {
        $ctrl = $this->makeController('saved_queries_ctrl');
        $res  = $this->callController($ctrl, 'listQueries');

        $this->assertNotEmpty($res['queries']);
        $names = array_column($res['queries'], 'name');
        $this->assertContains('My first search', $names);
    }

    public function testListQueriesRowHasExpectedKeys(): void
    {
        $ctrl = $this->makeController('saved_queries_ctrl');
        $res  = $this->callController($ctrl, 'listQueries');

        $row = $res['queries'][0];
        foreach (['id', 'name', 'tb', 'query', 'is_global', 'tb_label', 'owned_by_me'] as $k) {
            $this->assertArrayHasKey($k, $row, "Missing key: $k");
        }
    }

    // ── saveQuery ─────────────────────────────────────────────────────────────

    public function testSaveQuerySuccess(): void
    {
        $ctrl = $this->makeController('saved_queries_ctrl', [], [
            'name'  => 'Test save',
            'tb'    => 'items',
            'query' => ['search_type' => 'sqlExpert', 'querytext' => 'id > 1'],
        ]);
        $res = $this->callController($ctrl, 'saveQuery');

        $this->assertSame('success', $res['status']);
        $this->assertSame('ok_saved_query', $res['code']);
    }

    public function testSaveQueryReturnsSavedObject(): void
    {
        $ctrl = $this->makeController('saved_queries_ctrl', [], [
            'name'  => 'Shaped query',
            'tb'    => 'items',
            'query' => ['search_type' => 'sqlExpert', 'querytext' => 'id > 2'],
        ]);
        $res = $this->callController($ctrl, 'saveQuery');

        $this->assertSame('success', $res['status']);
        $this->assertArrayHasKey('query', $res);
        $q = $res['query'];
        foreach (['id', 'name', 'tb', 'query', 'is_global', 'owned_by_me'] as $k) {
            $this->assertArrayHasKey($k, $q, "Missing key: $k in returned query");
        }
        $this->assertSame('Shaped query', $q['name']);
        $this->assertSame('items',  $q['tb']);
        $this->assertSame(0,              (int) $q['is_global']);
        $this->assertTrue($q['owned_by_me']);
        $this->assertIsArray($q['query']);
        $this->assertSame('sqlExpert', $q['query']['search_type']);
    }

    public function testSaveQueryMissingName(): void
    {
        $ctrl = $this->makeController('saved_queries_ctrl', [], [
            'tb'    => 'items',
            'query' => ['search_type' => 'sqlExpert', 'querytext' => 'id > 1'],
        ]);
        $res = $this->callController($ctrl, 'saveQuery');

        $this->assertSame('error', $res['status']);
        $this->assertSame('parameter_missing', $res['code']);
    }

    public function testSaveQueryMissingTb(): void
    {
        $ctrl = $this->makeController('saved_queries_ctrl', [], [
            'name'  => 'No table query',
            'query' => ['search_type' => 'sqlExpert', 'querytext' => 'id > 1'],
        ]);
        $res = $this->callController($ctrl, 'saveQuery');

        $this->assertSame('error', $res['status']);
        $this->assertSame('parameter_missing', $res['code']);
    }

    // ── shareQuery / unshareQuery ─────────────────────────────────────────────

    public function testShareQuerySuccess(): void
    {
        // First save a fresh query so we have a known id
        $save = $this->makeController('saved_queries_ctrl', [], [
            'name'  => 'To share',
            'tb'    => 'items',
            'query' => ['search_type' => 'sqlExpert', 'querytext' => 'status = \'active\''],
        ]);
        $saved = $this->callController($save, 'saveQuery');
        $id    = $saved['query']['id'];

        $ctrl = $this->makeController('saved_queries_ctrl', [], ['id' => $id]);
        $res  = $this->callController($ctrl, 'shareQuery');

        $this->assertSame('success', $res['status']);
        $this->assertSame('ok_sharing_query', $res['code']);

        // Verify it appears as global in list
        $list = $this->callController($this->makeController('saved_queries_ctrl'), 'listQueries');
        $row  = array_values(array_filter($list['queries'], fn($q) => (int)$q['id'] === (int)$id))[0] ?? null;
        $this->assertNotNull($row);
        $this->assertSame(1, (int) $row['is_global']);
    }

    public function testUnshareQuerySuccess(): void
    {
        // Save and share
        $save = $this->makeController('saved_queries_ctrl', [], [
            'name'  => 'To unshare',
            'tb'    => 'items',
            'query' => ['search_type' => 'sqlExpert', 'querytext' => 'id > 0'],
        ]);
        $saved = $this->callController($save, 'saveQuery');
        $id    = $saved['query']['id'];

        $this->callController($this->makeController('saved_queries_ctrl', [], ['id' => $id]), 'shareQuery');

        $ctrl = $this->makeController('saved_queries_ctrl', [], ['id' => $id]);
        $res  = $this->callController($ctrl, 'unshareQuery');

        $this->assertSame('success', $res['status']);
        $this->assertSame('ok_unsharing_query', $res['code']);

        // Verify is_global is back to 0
        $list = $this->callController($this->makeController('saved_queries_ctrl'), 'listQueries');
        $row  = array_values(array_filter($list['queries'], fn($q) => (int)$q['id'] === (int)$id))[0] ?? null;
        $this->assertNotNull($row);
        $this->assertSame(0, (int) $row['is_global']);
    }

    // ── deleteQuery ───────────────────────────────────────────────────────────

    public function testDeleteQuerySuccess(): void
    {
        // Save a query to delete
        $save = $this->makeController('saved_queries_ctrl', [], [
            'name'  => 'To delete',
            'tb'    => 'items',
            'query' => ['search_type' => 'sqlExpert', 'querytext' => 'id = 1'],
        ]);
        $saved = $this->callController($save, 'saveQuery');
        $id    = $saved['query']['id'];

        $ctrl = $this->makeController('saved_queries_ctrl', [], ['id' => $id]);
        $res  = $this->callController($ctrl, 'deleteQuery');

        $this->assertSame('success', $res['status']);
        $this->assertSame('ok_erasing_query', $res['code']);

        // Verify it is gone from the list
        $list = $this->callController($this->makeController('saved_queries_ctrl'), 'listQueries');
        $ids  = array_column($list['queries'], 'id');
        $this->assertNotContains($id, $ids);
    }

    public function testDeleteQueryNotFound(): void
    {
        $ctrl = $this->makeController('saved_queries_ctrl', [], ['id' => 999999]);
        $res  = $this->callController($ctrl, 'deleteQuery');

        $this->assertSame('error', $res['status']);
        $this->assertSame('query_not_found', $res['code']);
    }
}
