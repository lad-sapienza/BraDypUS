<?php

namespace Tests\Integration;

use Tests\Support\BdusTestCase;

require_once __DIR__ . '/../Support/UploadShim.php';

/**
 * The file endpoints judge each file through Auth\FileAccess (matrix in FileAccessTest):
 * list, download, update, replace, sort, delete, link. A principal that can change records of
 * `items` only must not touch files that belong (also) to anything else, nor orphans.
 *
 * Files: 701 on items 1 · 702 on items 1 + categories 1 · 703 on categories 1 · 704 orphan.
 */
class FileEndpointsAccessTest extends BdusTestCase
{
    protected function setUp(): void
    {
        foreach ([701, 702, 703, 704] as $id) {
            $uuid = static::insertFile($id, 'png', "f{$id}");
            file_put_contents(PROJ_DIR . "files/{$uuid}.png", "BIN{$id}");
        }
        static::$db->exec("INSERT INTO bdus_file_links (file_id, table_name, record_id, sort) VALUES
            (701, 'items', 1, 1), (702, 'items', 1, 2), (702, 'categories', 1, 1), (703, 'categories', 1, 2)");
        static::$db->exec("INSERT INTO bdus_users (id, name, email, password, privilege) VALUES (72, 'fe', 'fe72@example.com', 'x', 39)");
    }

    protected function tearDown(): void
    {
        foreach ([701, 702, 703, 704] as $id) {
            @unlink(PROJ_DIR . 'files/' . static::fileUuid($id) . '.png');
        }
        static::$db->exec('DELETE FROM bdus_file_links WHERE file_id >= 700 OR record_id = 3');
        static::$db->exec('DELETE FROM bdus_files WHERE id >= 701');
        static::$db->exec('DELETE FROM bdus_user_groups');
        static::$db->exec('DELETE FROM bdus_group_rules');
        static::$db->exec('DELETE FROM bdus_groups');
        static::$db->exec('DELETE FROM bdus_users WHERE id = 72');
        unset($_SERVER['HTTP_IF_NONE_MATCH']);
        $this->setPrivilege(1);
    }

    private function as(int $tier, array $rules = []): void
    {
        if ($rules) {
            $g = (int) static::$db->query("INSERT INTO bdus_groups (name) VALUES ('G')", [], 'id');
            foreach ($rules as [$table, $action]) {
                static::$db->query(
                    'INSERT INTO bdus_group_rules (group_id, table_name, action, filter) VALUES (?, ?, ?, NULL)',
                    [$g, $table, $action],
                    'boolean'
                );
            }
            static::$db->exec("INSERT INTO bdus_user_groups (user_id, group_id) VALUES (72, {$g})");
        }
        \Auth\CurrentUser::set(['id' => 72, 'name' => 'fe', 'email' => 'fe72@example.com', 'privilege' => $tier, 'app' => 'test']);
    }

    private function call(string $class, string $method, array $get = [], array $post = []): array
    {
        return $this->callController($this->makeController("Bdus\\Controllers\\{$class}", $get, $post), $method);
    }

    private function download(string $uuid): array
    {
        http_response_code(200);
        $ctrl = $this->makeController('Bdus\\Controllers\\File', ['uuid' => $uuid], []);
        ob_start();
        $ctrl->download();
        $body = ob_get_clean();
        return ['code' => http_response_code(), 'body' => $body];
    }

    private function exists(int $id): bool
    {
        return (int) static::$db->query('SELECT COUNT(*) AS n FROM bdus_files WHERE id = ?', [$id], 'read')[0]['n'] > 0;
    }

    // ── list ──────────────────────────────────────────────────────────────────

    public function testTheListHoldsOnlyReadableFilesAndTheirReadableLinks(): void
    {
        $this->as(39, [['items', 'read']]);
        $res = $this->call('File', 'getFiles');

        $ids = array_values(array_filter(array_column($res['files'], 'id'), fn($i) => $i >= 700));
        $this->assertSame([702, 701], $ids, 'files of categories only and orphans stay out');
        $byId = array_column($res['files'], null, 'id');
        $this->assertSame([['tb' => 'items', 'record_id' => 1]], $byId[702]['links'], 'the categories link is not shown');
    }

    public function testAFilesRuleShowsOrphansToo(): void
    {
        $this->as(39, [['bdus_files', 'read']]);
        $ids = array_values(array_filter(array_column($this->call('File', 'getFiles')['files'], 'id'), fn($i) => $i >= 700));
        $this->assertSame([704, 703, 702, 701], $ids);
    }

    // ── the generic record path sees the same files ───────────────────────────

    public function testTheGenericTableListAndRecordFollowTheSameRule(): void
    {
        $this->as(39, [['items', 'read']]);

        $res = $this->call('Record', 'getRecords', ['tb' => 'bdus_files', 'per_page' => 100]);
        $this->assertSame('success', $res['status'], json_encode($res));
        $ids = array_values(array_filter(array_map(fn($r) => (int) ($r['id'] ?? 0), $res['data'] ?? []), fn($i) => $i >= 700));
        sort($ids);
        $this->assertSame([701, 702], $ids, 'only files attached to readable records: not categories-only, not orphans');
        $this->assertSame(4, $res['total'], 'the count follows the same rule (the two fixture files are on items 1 too)');
        $this->assertFalse($res['can']['update'], 'reading gives no write ability on the generic table');

        $this->assertSame('success', $this->call('Record', 'getRecord', ['tb' => 'bdus_files', 'id' => 701])['status']);
        $this->assertSame('not_enough_privilege', $this->call('Record', 'getRecord', ['tb' => 'bdus_files', 'id' => 703])['code'] ?? null);
        $this->assertSame('not_enough_privilege', $this->call('Record', 'getRecord', ['tb' => 'bdus_files', 'id' => 704])['code'] ?? null);
    }

    public function testSomeoneWhoCannotReadAnythingGetsNoFileTable(): void
    {
        $this->as(39);
        $this->assertSame('not_enough_privilege', $this->call('Record', 'getRecords', ['tb' => 'bdus_files'])['code']);
    }

    // ── download ──────────────────────────────────────────────────────────────

    public function testDownloadSendsReadableFilesOnly(): void
    {
        $this->as(39, [['items', 'read']]);

        $ok = $this->download(static::fileUuid(701));
        $this->assertSame('BIN701', $ok['body']);

        foreach ([703 => 'attached to a table it cannot read', 704 => 'an orphan'] as $id => $why) {
            $res = $this->download(static::fileUuid($id));
            $this->assertSame(404, $res['code'], $why);
            $this->assertSame('Not found', $res['body'], $why);
        }
    }

    public function testDownloadRefusalLooksLikeAnUnknownAddress(): void
    {
        $this->as(39, [['items', 'read']]);
        $unreadable = $this->download(static::fileUuid(703));
        $unknown    = $this->download(static::fileUuid(99999));
        $this->assertSame($unknown, $unreadable, 'same status and body: no way to tell them apart');
    }

    public function testDownloadRefusesAnythingThatIsNotAUuid(): void
    {
        $this->as(1);
        foreach (['701', '../' . static::fileUuid(701), '', strtoupper(static::fileUuid(701)) . 'x'] as $bad) {
            $this->assertSame(404, $this->download($bad)['code'], "'$bad'");
        }
    }

    public function testDownloadOfAFileWhoseBinaryIsMissingIs404(): void
    {
        $this->as(1);
        @unlink(PROJ_DIR . 'files/' . static::fileUuid(704) . '.png');
        $this->assertSame(404, $this->download(static::fileUuid(704))['code']);
    }

    public function testDownloadRevalidatesWithTheEtag(): void
    {
        $this->as(1);
        $path = PROJ_DIR . 'files/' . static::fileUuid(701) . '.png';
        $etag = '"' . dechex((int) filemtime($path)) . '-' . dechex((int) filesize($path)) . '"';
        $_SERVER['HTTP_IF_NONE_MATCH'] = $etag;

        $res = $this->download(static::fileUuid(701));
        $this->assertSame(304, $res['code']);
        $this->assertSame('', $res['body']);
    }

    public function testDownloadRevalidationDoesNotLeakAFileTheUserCannotRead(): void
    {
        $this->as(39, [['items', 'read']]);
        $path = PROJ_DIR . 'files/' . static::fileUuid(703) . '.png';
        $_SERVER['HTTP_IF_NONE_MATCH'] = '"' . dechex((int) filemtime($path)) . '-' . dechex((int) filesize($path)) . '"';

        $this->assertSame(404, $this->download(static::fileUuid(703))['code'], 'a right ETag must not turn 404 into 304');
    }

    // ── update / replace ──────────────────────────────────────────────────────

    public function testUpdateNeedsEveryAttachedRecordToBeChangeable(): void
    {
        $this->as(39, [['items', 'update']]);

        $this->assertSame('ok_file_updated', $this->call('File', 'updateFile', ['fileId' => 701], ['description' => 'x'])['code']);
        foreach ([702, 703, 704] as $id) {
            $this->assertSame('not_enough_privilege', $this->call('File', 'updateFile', ['fileId' => $id], ['description' => 'x'])['code'], "file $id");
        }
        $this->assertSame('x', static::$db->query('SELECT description FROM bdus_files WHERE id = 701', [], 'read')[0]['description']);
        $this->assertNull(static::$db->query('SELECT description FROM bdus_files WHERE id = 702', [], 'read')[0]['description']);
    }

    public function testReplaceIsGatedPerFileBeforeAnythingIsRead(): void
    {
        $this->as(39, [['items', 'update']]);

        foreach ([702, 703, 704] as $id) {
            $this->assertSame('not_enough_privilege', $this->call('File', 'replaceFile', ['fileId' => $id])['code'], "file $id");
        }
        $this->assertNotSame('not_enough_privilege', $this->call('File', 'replaceFile', ['fileId' => 701])['code'],
            'an allowed file gets past the gate (and then fails for want of an upload)');
    }

    public function testTheGenericSaveJudgesAFileLikeTheFileEndpoint(): void
    {
        $this->as(39, [['items', 'update'], ['items', 'read']]);
        $save = fn(int $id) => $this->call('Record', 'saveRecord', [], ['tb' => 'bdus_files', 'id' => $id, 'core' => ['keywords' => 'viaSave']]);

        $this->assertSame('success', $save(701)['status'], 'attached to a changeable record only');
        foreach ([702, 703, 704] as $id) {
            $this->assertSame('not_enough_privilege', $save($id)['code'], "file $id");
            $this->assertNotSame('viaSave', static::$db->query('SELECT keywords FROM bdus_files WHERE id = ?', [$id], 'read')[0]['keywords']);
        }
    }

    // ── sort ──────────────────────────────────────────────────────────────────

    public function testSortingIsGatedPerRecordOfEachLink(): void
    {
        $linkOf = fn(int $file, string $tb) => (int) static::$db->query(
            'SELECT id FROM bdus_file_links WHERE file_id = ? AND table_name = ?', [$file, $tb], 'read')[0]['id'];

        $this->as(39, [['items', 'update']]);

        $this->assertSame('ok_file_sorting_update', $this->call('File', 'sortFiles', [], ['order' => [$linkOf(702, 'items'), $linkOf(701, 'items')]])['code']);
        $this->assertSame('not_enough_privilege', $this->call('File', 'sortFiles', [], ['order' => [$linkOf(702, 'categories')]])['code'],
            'a link on a record this principal cannot change');
        $this->assertSame('not_enough_privilege', $this->call('File', 'sortFiles', [], ['order' => [$linkOf(701, 'items'), $linkOf(703, 'categories')]])['code'],
            'one refused link refuses the whole reorder');
        $this->assertSame('record_not_found', $this->call('File', 'sortFiles', [], ['order' => [99999999]])['code'], 'no such link');
    }

    // ── delete ────────────────────────────────────────────────────────────────

    public function testDeleteNeedsEveryAttachedRecordToBeChangeable(): void
    {
        $this->as(39, [['items', 'update']]);

        foreach ([702, 703, 704] as $id) {
            $this->assertSame('not_enough_privilege', $this->call('Record', 'deleteFile', [], ['fileId' => $id])['code'], "file $id");
            $this->assertTrue($this->exists($id), "file $id is still there");
        }
        $this->assertSame('ok_file_deleted', $this->call('Record', 'deleteFile', [], ['fileId' => 701])['code']);
        $this->assertFalse($this->exists(701));
        $this->assertFileDoesNotExist(PROJ_DIR . 'files/' . static::fileUuid(701) . '.png', 'its binary goes with it');
    }

    public function testADeleteRuleOnFilesDeletesAnOrphan(): void
    {
        $this->as(39, [['bdus_files', 'delete'], ['bdus_files', 'update']]);
        $this->assertSame('ok_file_deleted', $this->call('Record', 'deleteFile', [], ['fileId' => 704])['code']);
        $this->assertFalse($this->exists(704));
    }

    public function testDeletingAMissingFileIsNotFound(): void
    {
        $this->as(39, [['items', 'update']]);
        $this->assertSame('record_not_found', $this->call('Record', 'deleteFile', [], ['fileId' => 999999])['code']);
    }

    // ── link ──────────────────────────────────────────────────────────────────

    public function testAFileCannotBeAttachedWithoutBeingAbleToRead(): void
    {
        $this->as(39, [['items', 'update'], ['items', 'read']]);

        $denied = $this->call('Record', 'linkFile', ['tb' => 'items', 'id' => 3], ['fileId' => 703]);
        $this->assertSame('not_enough_privilege', $denied['code'], 'file 703 is on categories only: not readable, so not attachable');
        $this->assertSame('not_enough_privilege', $this->call('Record', 'linkFile', ['tb' => 'items', 'id' => 3], ['fileId' => 704])['code'], 'an orphan');

        $this->assertSame('ok_file_linked', $this->call('Record', 'linkFile', ['tb' => 'items', 'id' => 3], ['fileId' => 701])['code']);
    }

    public function testAttachingAlsoNeedsTheRecordToBeChangeable(): void
    {
        $this->as(39, [['items', 'read']]);
        $this->assertSame('not_enough_privilege', $this->call('Record', 'linkFile', ['tb' => 'items', 'id' => 3], ['fileId' => 701])['code']);
    }
}
