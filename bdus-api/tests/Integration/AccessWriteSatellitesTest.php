<?php

namespace Tests\Integration;

use Tests\Support\BdusTestCase;

/**
 * Locks and group rules on the writers that live outside the Record
 * controller (#78): GeoFace geometries, Zotero links, import, search & replace.
 *
 * Same fixture as AccessWriteEnforcementTest: item 2 (inactive) is frozen by a
 * lock, the acting principal is a writer (tier 20, user 52) unless said otherwise.
 */
class AccessWriteSatellitesTest extends BdusTestCase
{
    protected function setUp(): void
    {
        static::$db->exec("INSERT INTO bdus_users (id, name, email, password, privilege) VALUES (52, 'w', 'w@example.com', 'x', 20)");
        static::$db->query(
            "INSERT INTO bdus_table_locks (table_name, filter) VALUES ('items', ?)",
            [json_encode(['status' => ['_eq' => 'inactive']])], 'boolean'
        );
        static::$db->exec("INSERT INTO bdus_geodata (id, table_link, id_link, geometry) VALUES (901, 'items', 1, 'POINT(1 1)'), (902, 'items', 2, 'POINT(2 2)')");
        $this->actAs(20);
    }

    protected function tearDown(): void
    {
        static::$db->exec('DELETE FROM bdus_user_groups');
        static::$db->exec('DELETE FROM bdus_group_rules');
        static::$db->exec('DELETE FROM bdus_groups');
        static::$db->exec('DELETE FROM bdus_table_locks');
        static::$db->exec('DELETE FROM bdus_users WHERE id = 52');
        static::$db->exec("DELETE FROM bdus_geodata WHERE id >= 900 OR id_link IN (3, 4)");
        static::$db->exec('DELETE FROM bdus_zotero_links');
        static::$db->exec('DELETE FROM bdus_zotero_libs');
        static::$db->exec("DELETE FROM bdus_files WHERE id > 2");
        static::$db->exec("DELETE FROM bdus_file_links WHERE file_id > 2");
        static::$db->exec("DELETE FROM items WHERE id > 5");
        static::$db->exec("UPDATE items SET description = name");
        $this->setPrivilege(1);
    }

    private function actAs(int $tier, int $id = 52): void
    {
        \Auth\CurrentUser::set(['id' => $id, 'name' => 'w', 'email' => 'w@example.com', 'privilege' => $tier, 'app' => 'test']);
    }

    private function inGroup(array $rules): void
    {
        $g = (int) static::$db->query("INSERT INTO bdus_groups (name) VALUES ('G')", [], 'id');
        static::$db->exec("INSERT INTO bdus_user_groups (user_id, group_id) VALUES (52, {$g})");
        foreach ($rules as [$table, $action, $filter]) {
            static::$db->query(
                'INSERT INTO bdus_group_rules (group_id, table_name, action, filter) VALUES (?, ?, ?, ?)',
                [$g, $table, $action, $filter === null ? null : json_encode($filter)], 'boolean'
            );
        }
    }

    private function call(string $class, string $method, array $get = [], array $post = []): array
    {
        return $this->callController($this->makeController("Bdus\\Controllers\\{$class}", $get, $post), $method);
    }

    private function denied(array $res): void
    {
        $this->assertSame('not_enough_privilege', $res['code'] ?? null, json_encode($res));
    }

    private function scalar(string $sql, array $v = []): int
    {
        return (int) static::$db->query($sql, $v, 'read')[0]['n'];
    }

    private function plant(string $content, string $suffix = ''): string
    {
        $id = bin2hex(random_bytes(8));
        file_put_contents(sys_get_temp_dir() . '/bdus_import_' . $id . $suffix, $content);
        return $id;
    }

    // ── GeoFace ───────────────────────────────────────────────────────────────

    public function testGeometriesFollowTheirRecordsLock(): void
    {
        $this->assertSame('success', $this->call('Geoface', 'saveNew', [], ['tb' => 'items', 'id' => 3, 'geometry' => 'POINT(3 3)'])['status']);
        $this->denied($this->call('Geoface', 'saveNew', [], ['tb' => 'items', 'id' => 2, 'geometry' => 'POINT(9 9)']));
        $this->assertSame(0, $this->scalar("SELECT COUNT(*) AS n FROM bdus_geodata WHERE geometry = 'POINT(9 9)'"));

        $this->assertSame('success', $this->call('Geoface', 'updateGeometry', [], ['geodata' => [['id' => 901, 'geometry' => 'POINT(5 5)']]])['status']);
        $this->denied($this->call('Geoface', 'updateGeometry', [], ['geodata' => [['id' => 902, 'geometry' => 'POINT(6 6)']]]));
        $this->assertSame('POINT(2 2)', static::$db->query('SELECT geometry AS g FROM bdus_geodata WHERE id = 902', [], 'read')[0]['g']);
    }

    public function testAMixedBatchIsRefusedAsAWhole(): void
    {
        $this->denied($this->call('Geoface', 'updateGeometry', [], ['geodata' => [
            ['id' => 901, 'geometry' => 'POINT(7 7)'], ['id' => 902, 'geometry' => 'POINT(8 8)'],
        ]]));
        $this->assertSame('POINT(1 1)', static::$db->query('SELECT geometry AS g FROM bdus_geodata WHERE id = 901', [], 'read')[0]['g']);

        $this->denied($this->call('Geoface', 'eraseGeometry', [], ['ids' => [901, 902]]));
        $this->assertSame(2, $this->scalar('SELECT COUNT(*) AS n FROM bdus_geodata WHERE id IN (901, 902)'));

        $this->assertSame('success', $this->call('Geoface', 'eraseGeometry', [], ['ids' => [901]])['status']);
    }

    public function testAdminsChangeGeometriesOfFrozenRecords(): void
    {
        $this->actAs(10, 1);

        $this->assertSame('success', $this->call('Geoface', 'eraseGeometry', [], ['ids' => [902]])['status']);
    }

    public function testRuleBasedGeometryEditsStayInTheRuleScope(): void
    {
        $this->actAs(30);
        $this->inGroup([['items', 'update', ['status' => ['_eq' => 'pending']]]]);

        $this->assertSame('success', $this->call('Geoface', 'saveNew', [], ['tb' => 'items', 'id' => 4, 'geometry' => 'POINT(4 4)'])['status']);
        $this->denied($this->call('Geoface', 'saveNew', [], ['tb' => 'items', 'id' => 3, 'geometry' => 'POINT(3 3)']));
        $this->denied($this->call('Geoface', 'updateGeometry', [], ['geodata' => [['id' => 901, 'geometry' => 'POINT(1 2)']]]));
    }

    public function testReadersCannotAddGeometries(): void
    {
        $this->actAs(30);

        $this->denied($this->call('Geoface', 'saveNew', [], ['tb' => 'items', 'id' => 3, 'geometry' => 'POINT(3 3)']));
    }

    // ── Zotero ────────────────────────────────────────────────────────────────

    private function zoteroLink(int $record): int
    {
        if (!$this->scalar('SELECT COUNT(*) AS n FROM bdus_zotero_libs')) {
            static::$db->exec("INSERT INTO bdus_zotero_libs (id, type, zotero_id, name, created_at) VALUES (1, 'user', '1', 'lib', 1)");
        }
        static::$db->query(
            "INSERT INTO bdus_zotero_links (tb, record_id, lib_id, zotero_key, notes) VALUES ('items', ?, 1, 'K', 'orig')",
            [$record], 'boolean'
        );
        return (int) static::$db->query('SELECT last_insert_rowid() AS id', [], 'read')[0]['id'];
    }

    public function testZoteroLinksOfAFrozenRecordCannotBeChanged(): void
    {
        $frozen = $this->zoteroLink(2);
        $free   = $this->zoteroLink(3);

        $this->denied($this->call('Zotero', 'editLink', ['id' => $frozen], ['notes' => 'x']));
        $this->denied($this->call('Zotero', 'deleteLink', ['id' => $frozen]));
        $this->assertSame(1, $this->scalar("SELECT COUNT(*) AS n FROM bdus_zotero_links WHERE id = ? AND notes = 'orig'", [$frozen]));

        $this->assertSame('success', $this->call('Zotero', 'editLink', ['id' => $free], ['notes' => 'x'])['status']);
        $this->assertSame('success', $this->call('Zotero', 'deleteLink', ['id' => $free])['status']);
    }

    public function testNewZoteroLinkOnAFrozenRecordIsRefusedBeforeAnyNetworkCall(): void
    {
        $this->denied($this->call('Zotero', 'addLink', [], ['tb' => 'items', 'record_id' => 2, 'lib_id' => 1, 'zotero_key' => 'K']));
    }

    public function testZoteroLinksOfHiddenRecordsAreNotListed(): void
    {
        $this->actAs(39);
        $this->inGroup([['items', 'read', ['status' => ['_eq' => 'active']]]]);
        $this->zoteroLink(1);
        $this->zoteroLink(2);

        $this->assertSame('success', $this->call('Zotero', 'getLinks', ['tb' => 'items', 'id' => 1])['status']);
        $this->denied($this->call('Zotero', 'getLinks', ['tb' => 'items', 'id' => 2]));
    }

    // ── Import ────────────────────────────────────────────────────────────────

    private function importCsv(string $csv, array $extra = []): array
    {
        return $this->call('Import', 'importData', [], array_merge([
            'temp_id' => $this->plant($csv), 'type' => 'csv', 'tb' => 'items',
            'mapping' => ['name' => 'name', 'description' => 'description'], 'key_field' => 'name',
        ], $extra));
    }

    public function testImportTouchingAFrozenRecordIsCancelledAsAWhole(): void
    {
        $res = $this->importCsv("name,description\nAlpha item,imported\nBeta item,imported\n");

        $this->denied($res);
        $this->assertSame(0, $this->scalar("SELECT COUNT(*) AS n FROM items WHERE description = 'imported'"));
    }

    public function testImportOfFreeRecordsStillWorksForWriters(): void
    {
        $res = $this->importCsv("name,description\nAlpha item,imported\nBrand new,imported\n");

        $this->assertSame('success', $res['status']);
        $this->assertSame(1, $res['updated']);
        $this->assertSame(1, $res['inserted']);
    }

    public function testAdminsImportOverFrozenRecords(): void
    {
        $this->actAs(10, 1);

        $this->assertSame(2, $this->importCsv("name,description\nAlpha item,imported\nBeta item,imported\n")['updated']);
    }

    public function testRuleBasedImportNeedsTheRightsForWhatItDoes(): void
    {
        $this->actAs(30);
        $this->inGroup([['items', 'update', ['status' => ['_eq' => 'pending']]]]);

        // Updates inside the scope are fine; a new record needs a create rule; a record outside the scope is refused.
        $this->assertSame(1, $this->importCsv("name,description\nDelta thing,imported\n")['updated']);
        $this->denied($this->importCsv("name,description\nBrand new,imported\n"));
        $this->denied($this->importCsv("name,description\nAlpha item,imported\n"));

        static::$db->exec("INSERT INTO bdus_group_rules (group_id, table_name, action) SELECT id, 'items', 'create' FROM bdus_groups");
        $this->assertSame(1, $this->importCsv("name,description\nBrand new,imported\n")['inserted']);
    }

    public function testGeoJsonImportOnAFrozenRecordIsCancelled(): void
    {
        $geo = fn(string ...$names) => json_encode(['type' => 'FeatureCollection', 'features' => array_map(
            fn($n) => ['type' => 'Feature', 'properties' => ['n' => $n], 'geometry' => ['type' => 'Point', 'coordinates' => [10, 10]]],
            $names
        )]);
        $run = fn(string $json) => $this->call('Import', 'importGeoJson', [], [
            'temp_id' => $this->plant($json), 'tb' => 'items', 'geo_prop' => 'n', 'key_field' => 'name',
        ]);

        $this->denied($run($geo('Gamma item', 'Beta item')));
        $this->assertSame(0, $this->scalar("SELECT COUNT(*) AS n FROM bdus_geodata WHERE id_link = 3"));

        $this->assertSame('success', $run($geo('Gamma item'))['status']);
    }

    public function testPhotoImportOnAFrozenRecordWritesNothing(): void
    {
        if (!class_exists(\ZipArchive::class)) {
            $this->markTestSkipped('ext-zip not available');
        }
        $zipId   = bin2hex(random_bytes(8));
        $zipPath = sys_get_temp_dir() . '/bdus_import_' . $zipId . '.zip';
        $zip     = new \ZipArchive();
        $zip->open($zipPath, \ZipArchive::CREATE);
        $zip->addFromString('a.jpg', 'x');
        $zip->addFromString('b.jpg', 'x');
        $zip->close();
        file_put_contents(sys_get_temp_dir() . '/bdus_import_' . $zipId . '.csv', "filename,record_id\na.jpg,3\nb.jpg,2\n");

        $before = $this->scalar('SELECT COUNT(*) AS n FROM bdus_files');
        $this->denied($this->call('Import', 'importPhotos', [], ['temp_id' => $zipId, 'tb' => 'items']));
        $this->assertSame($before, $this->scalar('SELECT COUNT(*) AS n FROM bdus_files'));
    }

    // ── Search & replace ──────────────────────────────────────────────────────

    public function testSearchReplaceIsAdminOnlyAndAdminsAreNotLocked(): void
    {
        $args = ['tb' => 'items', 'fld' => 'name', 'search' => 'item', 'replace' => 'ITEM'];
        $this->denied($this->call('SearchReplace', 'doReplace', [], $args));

        $this->actAs(10, 1);
        $this->assertSame('success', $this->call('SearchReplace', 'doReplace', [], $args)['status']);
        $this->assertSame('Beta ITEM', static::$db->query('SELECT name AS n FROM items WHERE id = 2', [], 'read')[0]['n']);

        static::$db->exec("UPDATE items SET name = REPLACE(name, 'ITEM', 'item')");
    }
}
