<?php

namespace Tests\Integration;

use Tests\Support\BdusTestCase;

/**
 * Integration tests for zotero_ctrl.
 *
 * Tests all non-network endpoints:
 *   getLibs, addLib, deleteLib — library management
 *   getLinks, editLink, deleteLink — link CRUD (no Zotero API needed)
 *   addLink — attempts to fetch citation but handles ZoteroException gracefully
 *   syncRecord, syncAll — return early when no links / libs not reachable
 *   search — returns error when Zotero API is not reachable (expected in CI)
 *
 * Methods that require live Zotero network access are exercised only for their
 * error-handling path (ZoteroException → status=error, code=zotero_api_error).
 */
class ZoteroCtrlTest extends BdusTestCase
{
    // ── Extra schema: Zotero system tables ────────────────────────────────────

    protected static function createSchema(): void
    {
        parent::createSchema();

        static::$db->execInTransaction('
            CREATE TABLE bdus_zotero_libs (
                id             INTEGER PRIMARY KEY AUTOINCREMENT,
                type           TEXT    NOT NULL,
                zotero_id      TEXT    NOT NULL,
                name           TEXT    NOT NULL,
                api_key        TEXT,
                citation_style TEXT,
                created_at     INTEGER
            )
        ');

        static::$db->execInTransaction('
            CREATE UNIQUE INDEX zl_type_id_idx ON bdus_zotero_libs (type, zotero_id)
        ');

        static::$db->execInTransaction('
            CREATE TABLE bdus_zotero_links (
                id             INTEGER PRIMARY KEY AUTOINCREMENT,
                tb             TEXT    NOT NULL,
                record_id      INTEGER NOT NULL,
                lib_id         INTEGER NOT NULL,
                zotero_key     TEXT    NOT NULL,
                pages          TEXT,
                notes          TEXT,
                sort           INTEGER,
                author_year    TEXT,
                full_citation  TEXT,
                zotero_version INTEGER,
                synced_at      INTEGER,
                detached       INTEGER NOT NULL DEFAULT 0,
                created_at     INTEGER
            )
        ');

        static::$db->execInTransaction('
            CREATE INDEX zln_record_idx ON bdus_zotero_links (tb, record_id)
        ');

        static::$db->execInTransaction('
            CREATE INDEX zln_item_idx ON bdus_zotero_links (lib_id, zotero_key)
        ');
    }

    // ── Helpers ───────────────────────────────────────────────────────────────

    /** Insert a library row and return its id. */
    private function insertLib(string $type = 'group', string $zoteroId = '123456', string $name = 'Test Lib'): int
    {
        static::$db->execInTransaction(
            "INSERT INTO bdus_zotero_libs (type, zotero_id, name, citation_style)
             VALUES ('{$type}', '{$zoteroId}', '{$name}', 'chicago-author-date')"
        );
        $rows = static::$db->query("SELECT last_insert_rowid() AS id", [], 'read');
        return (int) $rows[0]['id'];
    }

    /** Insert a link row and return its id. */
    private function insertLink(int $libId, string $tb = 'items', int $recordId = 1, string $key = 'ABCD1234'): int
    {
        static::$db->execInTransaction(
            "INSERT INTO bdus_zotero_links (tb, record_id, lib_id, zotero_key, sort)
             VALUES ('{$tb}', {$recordId}, {$libId}, '{$key}', 0)"
        );
        $rows = static::$db->query("SELECT last_insert_rowid() AS id", [], 'read');
        return (int) $rows[0]['id'];
    }

    /** Remove all rows from both Zotero tables between tests. */
    protected function setUp(): void
    {
        static::$db->execInTransaction('DELETE FROM bdus_zotero_links');
        static::$db->execInTransaction('DELETE FROM bdus_zotero_libs');
    }

    // ── getLibs ───────────────────────────────────────────────────────────────

    public function testGetLibsReturnsSuccessWhenEmpty(): void
    {
        $ctrl = $this->makeController('zotero_ctrl');
        $res  = $this->callController($ctrl, 'getLibs');

        $this->assertSame('success', $res['status']);
        $this->assertIsArray($res['libs']);
        $this->assertEmpty($res['libs']);
    }

    public function testGetLibsReturnsInsertedLibs(): void
    {
        $this->insertLib('group', '111', 'Lib One');
        $this->insertLib('user',  '222', 'Lib Two');

        $ctrl = $this->makeController('zotero_ctrl');
        $res  = $this->callController($ctrl, 'getLibs');

        $this->assertSame('success', $res['status']);
        $this->assertCount(2, $res['libs']);
    }

    public function testGetLibsRedactsApiKey(): void
    {
        static::$db->execInTransaction(
            "INSERT INTO bdus_zotero_libs (type, zotero_id, name, api_key)
             VALUES ('group', '333', 'Secret Lib', 'supersecretkey')"
        );

        $ctrl = $this->makeController('zotero_ctrl');
        $res  = $this->callController($ctrl, 'getLibs');

        $lib = $res['libs'][0];
        $this->assertArrayNotHasKey('api_key', $lib, 'api_key must not be in response');
        $this->assertArrayHasKey('has_api_key', $lib);
        $this->assertTrue($lib['has_api_key']);
    }

    public function testGetLibsHasApiKeyFalseWhenNoKey(): void
    {
        $this->insertLib();

        $ctrl = $this->makeController('zotero_ctrl');
        $res  = $this->callController($ctrl, 'getLibs');

        $this->assertFalse($res['libs'][0]['has_api_key']);
    }

    public function testGetLibsRequiresAdminPrivilege(): void
    {
        $this->setPrivilege(25); // edit, not admin
        $ctrl = $this->makeController('zotero_ctrl');
        $res  = $this->callController($ctrl, 'getLibs');
        $this->setPrivilege(1);

        $this->assertSame('error', $res['status']);
        $this->assertSame('not_enough_privilege', $res['code']);
    }

    // ── addLib ────────────────────────────────────────────────────────────────

    public function testAddLibSuccess(): void
    {
        $ctrl = $this->makeController('zotero_ctrl', [], [
            'type'      => 'group',
            'zotero_id' => '654321',
            'name'      => 'My Library',
        ]);
        $res = $this->callController($ctrl, 'addLib');

        $this->assertSame('success', $res['status']);
        $this->assertSame('ok_lib_added', $res['code']);
        $this->assertGreaterThan(0, (int) $res['id']);
    }

    public function testAddLibMissingTypeReturnsError(): void
    {
        $ctrl = $this->makeController('zotero_ctrl', [], [
            'zotero_id' => '654321',
            'name'      => 'No Type Lib',
        ]);
        $res = $this->callController($ctrl, 'addLib');

        $this->assertSame('error', $res['status']);
        $this->assertSame('parameter_missing', $res['code']);
    }

    public function testAddLibInvalidTypeReturnsError(): void
    {
        $ctrl = $this->makeController('zotero_ctrl', [], [
            'type'      => 'institution',   // not user or group
            'zotero_id' => '654321',
            'name'      => 'Bad Type',
        ]);
        $res = $this->callController($ctrl, 'addLib');

        $this->assertSame('error', $res['status']);
        $this->assertSame('parameter_missing', $res['code']);
    }

    public function testAddLibMissingZoteroIdReturnsError(): void
    {
        $ctrl = $this->makeController('zotero_ctrl', [], [
            'type' => 'group',
            'name' => 'Missing ID',
        ]);
        $res = $this->callController($ctrl, 'addLib');

        $this->assertSame('error', $res['status']);
        $this->assertSame('parameter_missing', $res['code']);
    }

    public function testAddLibRequiresAdminPrivilege(): void
    {
        $this->setPrivilege(25); // edit, not admin
        $ctrl = $this->makeController('zotero_ctrl', [], [
            'type'      => 'group',
            'zotero_id' => '654321',
            'name'      => 'Priv Test',
        ]);
        $res = $this->callController($ctrl, 'addLib');
        $this->setPrivilege(1);

        $this->assertSame('error', $res['status']);
        $this->assertSame('not_enough_privilege', $res['code']);
    }

    public function testAddLibDefaultsCitationStyle(): void
    {
        $ctrl = $this->makeController('zotero_ctrl', [], [
            'type'      => 'group',
            'zotero_id' => '777777',
            'name'      => 'Default Style',
        ]);
        $this->callController($ctrl, 'addLib');

        $rows = static::$db->query(
            "SELECT citation_style FROM bdus_zotero_libs WHERE zotero_id = '777777'",
            [], 'read'
        );
        $this->assertSame('chicago-author-date', $rows[0]['citation_style']);
    }

    // ── deleteLib ─────────────────────────────────────────────────────────────

    public function testDeleteLibSuccess(): void
    {
        $libId = $this->insertLib();

        $ctrl = $this->makeController('zotero_ctrl', ['id' => $libId]);
        $res  = $this->callController($ctrl, 'deleteLib');

        $this->assertSame('success', $res['status']);
        $this->assertSame('ok_lib_deleted', $res['code']);

        $rows = static::$db->query(
            "SELECT id FROM bdus_zotero_libs WHERE id = ?", [$libId], 'read'
        );
        $this->assertEmpty($rows, 'Library row must be gone after delete');
    }

    public function testDeleteLibMissingIdReturnsError(): void
    {
        $ctrl = $this->makeController('zotero_ctrl');
        $res  = $this->callController($ctrl, 'deleteLib');

        $this->assertSame('error', $res['status']);
        $this->assertSame('parameter_missing', $res['code']);
    }

    public function testDeleteLibRequiresAdminPrivilege(): void
    {
        $libId = $this->insertLib();
        $this->setPrivilege(25);
        $ctrl = $this->makeController('zotero_ctrl', ['id' => $libId]);
        $res  = $this->callController($ctrl, 'deleteLib');
        $this->setPrivilege(1);

        $this->assertSame('error', $res['status']);
        $this->assertSame('not_enough_privilege', $res['code']);
    }

    // ── getLinks ──────────────────────────────────────────────────────────────

    public function testGetLinksReturnsEmptyWhenNone(): void
    {
        $ctrl = $this->makeController('zotero_ctrl', ['tb' => 'items', 'id' => 1]);
        $res  = $this->callController($ctrl, 'getLinks');

        $this->assertSame('success', $res['status']);
        $this->assertIsArray($res['links']);
        $this->assertEmpty($res['links']);
    }

    public function testGetLinksReturnsLinks(): void
    {
        $libId = $this->insertLib('group', '555', 'My Group');
        $this->insertLink($libId, 'items', 1, 'KEY001');
        $this->insertLink($libId, 'items', 1, 'KEY002');

        $ctrl = $this->makeController('zotero_ctrl', ['tb' => 'items', 'id' => 1]);
        $res  = $this->callController($ctrl, 'getLinks');

        $this->assertSame('success', $res['status']);
        $this->assertCount(2, $res['links']);
    }

    public function testGetLinksContainsZoteroUrl(): void
    {
        $libId = $this->insertLib('group', '888', 'Public Group');
        $this->insertLink($libId, 'items', 1, 'URLTEST');

        $ctrl = $this->makeController('zotero_ctrl', ['tb' => 'items', 'id' => 1]);
        $res  = $this->callController($ctrl, 'getLinks');

        $this->assertArrayHasKey('zotero_url', $res['links'][0]);
        $this->assertStringContainsString('zotero.org', $res['links'][0]['zotero_url']);
    }

    public function testGetLinksUserLibHasNullUrl(): void
    {
        $libId = $this->insertLib('user', '9999', 'User Lib');
        $this->insertLink($libId, 'items', 1, 'USERKEY');

        $ctrl = $this->makeController('zotero_ctrl', ['tb' => 'items', 'id' => 1]);
        $res  = $this->callController($ctrl, 'getLinks');

        $this->assertNull($res['links'][0]['zotero_url']);
    }

    public function testGetLinksMissingTbReturnsError(): void
    {
        $ctrl = $this->makeController('zotero_ctrl', ['id' => 1]);
        $res  = $this->callController($ctrl, 'getLinks');

        $this->assertSame('error', $res['status']);
        $this->assertSame('parameter_missing', $res['code']);
    }

    public function testGetLinksMissingIdReturnsError(): void
    {
        $ctrl = $this->makeController('zotero_ctrl', ['tb' => 'items']);
        $res  = $this->callController($ctrl, 'getLinks');

        $this->assertSame('error', $res['status']);
        $this->assertSame('parameter_missing', $res['code']);
    }

    // ── addLink ───────────────────────────────────────────────────────────────
    // fetchCacheData catches ZoteroException; link is saved even without cache.

    public function testAddLinkSucceeds(): void
    {
        $libId = $this->insertLib();

        $ctrl = $this->makeController('zotero_ctrl', [], [
            'tb'        => 'items',
            'record_id' => 1,
            'lib_id'    => $libId,
            'zotero_key'=> 'NEWKEY1',
        ]);
        $res = $this->callController($ctrl, 'addLink');

        $this->assertSame('success', $res['status']);
        $this->assertSame('ok_link_added', $res['code']);
        $this->assertGreaterThan(0, (int) $res['id']);
    }

    public function testAddLinkRowPersisted(): void
    {
        $libId = $this->insertLib();

        $ctrl = $this->makeController('zotero_ctrl', [], [
            'tb'        => 'items',
            'record_id' => 2,
            'lib_id'    => $libId,
            'zotero_key'=> 'PERSIST1',
            'pages'     => '10–15',
        ]);
        $this->callController($ctrl, 'addLink');

        $rows = static::$db->query(
            "SELECT * FROM bdus_zotero_links WHERE zotero_key = 'PERSIST1'",
            [], 'read'
        );
        $this->assertCount(1, $rows);
        $this->assertSame('10–15', $rows[0]['pages']);
    }

    public function testAddLinkMissingParamsReturnsError(): void
    {
        $ctrl = $this->makeController('zotero_ctrl', [], [
            'tb'     => 'items',
            'lib_id' => 1,
            // record_id and zotero_key missing
        ]);
        $res = $this->callController($ctrl, 'addLink');

        $this->assertSame('error', $res['status']);
        $this->assertSame('parameter_missing', $res['code']);
    }

    public function testAddLinkUnknownLibReturnsError(): void
    {
        $ctrl = $this->makeController('zotero_ctrl', [], [
            'tb'        => 'items',
            'record_id' => 1,
            'lib_id'    => 99999,
            'zotero_key'=> 'BADLIB1',
        ]);
        $res = $this->callController($ctrl, 'addLink');

        $this->assertSame('error', $res['status']);
        $this->assertSame('lib_not_found', $res['code']);
    }

    public function testAddLinkRequiresEditPrivilege(): void
    {
        $libId = $this->insertLib();
        $this->setPrivilege(99);
        $ctrl = $this->makeController('zotero_ctrl', [], [
            'tb'        => 'items',
            'record_id' => 1,
            'lib_id'    => $libId,
            'zotero_key'=> 'PRIVKEY',
        ]);
        $res = $this->callController($ctrl, 'addLink');
        $this->setPrivilege(1);

        $this->assertSame('error', $res['status']);
        $this->assertSame('not_enough_privilege', $res['code']);
    }

    // ── editLink ──────────────────────────────────────────────────────────────

    public function testEditLinkSuccess(): void
    {
        $libId  = $this->insertLib();
        $linkId = $this->insertLink($libId);

        $ctrl = $this->makeController('zotero_ctrl', ['id' => $linkId], ['pages' => '5–6', 'notes' => 'See also']);
        $res  = $this->callController($ctrl, 'editLink');

        $this->assertSame('success', $res['status']);
        $this->assertSame('ok_link_updated', $res['code']);
    }

    public function testEditLinkPersistsValues(): void
    {
        $libId  = $this->insertLib();
        $linkId = $this->insertLink($libId);

        $ctrl = $this->makeController('zotero_ctrl', ['id' => $linkId], ['pages' => '99–100']);
        $this->callController($ctrl, 'editLink');

        $rows = static::$db->query(
            "SELECT pages FROM bdus_zotero_links WHERE id = ?", [$linkId], 'read'
        );
        $this->assertSame('99–100', $rows[0]['pages']);
    }

    public function testEditLinkMissingIdReturnsError(): void
    {
        $ctrl = $this->makeController('zotero_ctrl', [], ['pages' => '1']);
        $res  = $this->callController($ctrl, 'editLink');

        $this->assertSame('error', $res['status']);
        $this->assertSame('parameter_missing', $res['code']);
    }

    public function testEditLinkEmptyDataReturnsError(): void
    {
        $libId  = $this->insertLib();
        $linkId = $this->insertLink($libId);

        $ctrl = $this->makeController('zotero_ctrl', ['id' => $linkId], []);
        $res  = $this->callController($ctrl, 'editLink');

        $this->assertSame('error', $res['status']);
        $this->assertSame('parameter_missing', $res['code']);
    }

    // ── deleteLink ────────────────────────────────────────────────────────────

    public function testDeleteLinkSuccess(): void
    {
        $libId  = $this->insertLib();
        $linkId = $this->insertLink($libId);

        $ctrl = $this->makeController('zotero_ctrl', ['id' => $linkId]);
        $res  = $this->callController($ctrl, 'deleteLink');

        $this->assertSame('success', $res['status']);
        $this->assertSame('ok_link_deleted', $res['code']);
    }

    public function testDeleteLinkRowGone(): void
    {
        $libId  = $this->insertLib();
        $linkId = $this->insertLink($libId);

        $ctrl = $this->makeController('zotero_ctrl', ['id' => $linkId]);
        $this->callController($ctrl, 'deleteLink');

        $rows = static::$db->query(
            "SELECT id FROM bdus_zotero_links WHERE id = ?", [$linkId], 'read'
        );
        $this->assertEmpty($rows);
    }

    public function testDeleteLinkMissingIdReturnsError(): void
    {
        $ctrl = $this->makeController('zotero_ctrl');
        $res  = $this->callController($ctrl, 'deleteLink');

        $this->assertSame('error', $res['status']);
        $this->assertSame('parameter_missing', $res['code']);
    }

    // ── syncRecord ────────────────────────────────────────────────────────────

    public function testSyncRecordWithNoLinksReturnsZeroCounts(): void
    {
        $ctrl = $this->makeController('zotero_ctrl', ['tb' => 'items', 'id' => 1]);
        $res  = $this->callController($ctrl, 'syncRecord');

        $this->assertSame('success', $res['status']);
        $this->assertSame(0, (int) $res['updated']);
        $this->assertSame(0, (int) $res['detached']);
    }

    public function testSyncRecordMissingTbReturnsError(): void
    {
        $ctrl = $this->makeController('zotero_ctrl', ['id' => 1]);
        $res  = $this->callController($ctrl, 'syncRecord');

        $this->assertSame('error', $res['status']);
        $this->assertSame('parameter_missing', $res['code']);
    }

    public function testSyncRecordMissingIdReturnsError(): void
    {
        $ctrl = $this->makeController('zotero_ctrl', ['tb' => 'items']);
        $res  = $this->callController($ctrl, 'syncRecord');

        $this->assertSame('error', $res['status']);
        $this->assertSame('parameter_missing', $res['code']);
    }

    // ── syncAll ───────────────────────────────────────────────────────────────

    public function testSyncAllWithNoLinksReturnsZeroCounts(): void
    {
        $ctrl = $this->makeController('zotero_ctrl');
        $res  = $this->callController($ctrl, 'syncAll');

        $this->assertSame('success', $res['status']);
        $this->assertSame(0, (int) $res['total']);
        $this->assertSame(0, (int) $res['updated']);
        $this->assertSame(0, (int) $res['detached']);
    }

    public function testSyncAllRequiresAdminPrivilege(): void
    {
        $this->setPrivilege(25);
        $ctrl = $this->makeController('zotero_ctrl');
        $res  = $this->callController($ctrl, 'syncAll');
        $this->setPrivilege(1);

        $this->assertSame('error', $res['status']);
        $this->assertSame('not_enough_privilege', $res['code']);
    }

    // ── search (error path — no network in CI) ────────────────────────────────

    public function testSearchMissingLibIdReturnsError(): void
    {
        $ctrl = $this->makeController('zotero_ctrl', ['q' => 'test']);
        $res  = $this->callController($ctrl, 'search');

        $this->assertSame('error', $res['status']);
        $this->assertSame('parameter_missing', $res['code']);
    }

    public function testSearchMissingQueryReturnsError(): void
    {
        $ctrl = $this->makeController('zotero_ctrl', ['lib_id' => 1]);
        $res  = $this->callController($ctrl, 'search');

        $this->assertSame('error', $res['status']);
        $this->assertSame('parameter_missing', $res['code']);
    }

    public function testSearchUnknownLibReturnsError(): void
    {
        $ctrl = $this->makeController('zotero_ctrl', ['lib_id' => 99999, 'q' => 'test']);
        $res  = $this->callController($ctrl, 'search');

        $this->assertSame('error', $res['status']);
        $this->assertSame('lib_not_found', $res['code']);
    }
}
