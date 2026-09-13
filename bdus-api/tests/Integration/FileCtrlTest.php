<?php

namespace Tests\Integration;

use Tests\Support\BdusTestCase;

/**
 * Integration tests for File::sortFiles().
 *
 * bdus_file_links is seeded by BdusTestCase with IDs 1 and 2 (both linked to items record 1).
 */
class FileCtrlTest extends BdusTestCase
{
    // ── getFiles ──────────────────────────────────────────────────────────────

    public function testGetFilesReturnsAllFiles(): void
    {
        $ctrl = $this->makeController('Bdus\\Controllers\\File', [], []);
        $res  = $this->callController($ctrl, 'getFiles');

        $this->assertSame('success', $res['status']);
        $this->assertArrayHasKey('total',    $res);
        $this->assertArrayHasKey('page',     $res);
        $this->assertArrayHasKey('per_page', $res);
        $this->assertArrayHasKey('files',    $res);
        $this->assertIsArray($res['files']);
        // BdusTestCase seeds 2 files (ids 1 & 2), both linked to items record 1
        $this->assertSame(2, $res['total']);
        $this->assertCount(2, $res['files']);
    }

    public function testGetFilesResponseShape(): void
    {
        $ctrl = $this->makeController('Bdus\\Controllers\\File', [], []);
        $res  = $this->callController($ctrl, 'getFiles');

        $this->assertSame('success', $res['status']);
        $this->assertSame(2, $res['total']);
        $this->assertCount(2, $res['files']);
        $file = $res['files'][0];
        $this->assertArrayHasKey('id',          $file);
        $this->assertArrayHasKey('ext',         $file);
        $this->assertArrayHasKey('filename',    $file);
        $this->assertArrayHasKey('description', $file);
        $this->assertArrayHasKey('keywords',    $file);
        $this->assertArrayHasKey('is_image',    $file);
        $this->assertArrayHasKey('links',       $file);
        $this->assertIsArray($file['links']);
        // Both seeded files are linked to items record 1
        $this->assertNotEmpty($file['links']);
        $this->assertSame('items',    $file['links'][0]['tb']);
        $this->assertSame(1, (int)$file['links'][0]['record_id']);
    }

    public function testGetFilesOrphansOnlyReturnsOnlyUnlinked(): void
    {
        // Insert an orphan file (no file_link row)
        static::$db->execInTransaction(
            "INSERT INTO bdus_files (id, creator, ext, filename) VALUES (99, 'admin', 'txt', 'orphan')"
        );

        $ctrl = $this->makeController('Bdus\\Controllers\\File', ['orphans_only' => '1'], []);
        $res  = $this->callController($ctrl, 'getFiles');

        $this->assertSame('success', $res['status']);
        $this->assertSame(1, $res['total']);
        $this->assertCount(1, $res['files']);
        $this->assertSame(99, (int)$res['files'][0]['id']);

        // Clean up
        static::$db->execInTransaction("DELETE FROM bdus_files WHERE id = 99");
    }

    public function testGetFilesOrphansOnlyEmptyWhenAllLinked(): void
    {
        $ctrl = $this->makeController('Bdus\\Controllers\\File', ['orphans_only' => '1'], []);
        $res  = $this->callController($ctrl, 'getFiles');

        $this->assertSame('success', $res['status']);
        $this->assertSame(0, $res['total']);
        $this->assertCount(0, $res['files']);
    }

    // ── getFiles: search ──────────────────────────────────────────────────────

    public function testGetFilesSearchMatchesFilename(): void
    {
        $ctrl = $this->makeController('Bdus\\Controllers\\File', ['search' => 'photo'], []);
        $res  = $this->callController($ctrl, 'getFiles');

        $this->assertSame('success', $res['status']);
        $this->assertSame(1, $res['total']);
        $this->assertSame(1, (int) $res['files'][0]['id']);
    }

    public function testGetFilesSearchMatchesDescription(): void
    {
        $ctrl = $this->makeController('Bdus\\Controllers\\File', ['search' => 'A document'], []);
        $res  = $this->callController($ctrl, 'getFiles');

        $this->assertSame('success', $res['status']);
        $this->assertSame(1, $res['total']);
        $this->assertSame(2, (int) $res['files'][0]['id']);
    }

    public function testGetFilesSearchMatchesIdExactly(): void
    {
        // Neither seeded file has a "1" anywhere in filename/description/keywords,
        // so a match here can only come from the id = 1 branch.
        $ctrl = $this->makeController('Bdus\\Controllers\\File', ['search' => '1'], []);
        $res  = $this->callController($ctrl, 'getFiles');

        $this->assertSame('success', $res['status']);
        $this->assertSame(1, $res['total']);
        $this->assertSame(1, (int) $res['files'][0]['id']);
    }

    public function testGetFilesSearchNoMatchReturnsEmpty(): void
    {
        $ctrl = $this->makeController('Bdus\\Controllers\\File', ['search' => 'no_such_thing_zzz'], []);
        $res  = $this->callController($ctrl, 'getFiles');

        $this->assertSame('success', $res['status']);
        $this->assertSame(0, $res['total']);
        $this->assertCount(0, $res['files']);
    }

    public function testGetFilesSearchCombinesWithOrphansOnly(): void
    {
        static::$db->execInTransaction(
            "INSERT INTO bdus_files (id, creator, ext, filename) VALUES (98, 'admin', 'txt', 'photo_orphan')"
        );

        $ctrl = $this->makeController('Bdus\\Controllers\\File', ['search' => 'photo', 'orphans_only' => '1'], []);
        $res  = $this->callController($ctrl, 'getFiles');

        // File 1 ("photo") matches the search but is linked (not orphan); only
        // the newly inserted orphan matches both conditions.
        $this->assertSame('success', $res['status']);
        $this->assertSame(1, $res['total']);
        $this->assertSame(98, (int) $res['files'][0]['id']);

        static::$db->execInTransaction("DELETE FROM bdus_files WHERE id = 98");
    }

    public function testGetFilesNotEnoughPrivilege(): void
    {
        $this->setPrivilege(99);

        $ctrl = $this->makeController('Bdus\\Controllers\\File', [], []);
        $res  = $this->callController($ctrl, 'getFiles');

        $this->assertSame('error',               $res['status']);
        $this->assertSame('not_enough_privilege', $res['code']);

        $this->setPrivilege(1);
    }

    // ── updateFile ────────────────────────────────────────────────────────────

    public function testUpdateFileSuccess(): void
    {
        $ctrl = $this->makeController(
            'Bdus\\Controllers\\File',
            ['fileId' => 1],
            ['description' => 'Updated desc', 'keywords' => 'kw1 kw2']
        );
        $res = $this->callController($ctrl, 'updateFile');

        $this->assertSame('success',          $res['status']);
        $this->assertSame('ok_file_updated',  $res['code']);

        // Verify DB was updated
        $rows = static::$db->query(
            "SELECT description, keywords FROM bdus_files WHERE id = 1",
            [], 'read'
        );
        $this->assertSame('Updated desc', $rows[0]['description']);
        $this->assertSame('kw1 kw2',      $rows[0]['keywords']);
    }

    public function testUpdateFileRenameSuccess(): void
    {
        $ctrl = $this->makeController(
            'Bdus\\Controllers\\File',
            ['fileId' => 1],
            ['filename' => 'renamed-photo']
        );
        $res = $this->callController($ctrl, 'updateFile');

        $this->assertSame('success',         $res['status']);
        $this->assertSame('ok_file_updated', $res['code']);

        $rows = static::$db->query("SELECT filename, ext FROM bdus_files WHERE id = 1", [], 'read');
        $this->assertSame('renamed-photo', $rows[0]['filename']);
        // The extension (and by extension the physical {id}.{ext} filesystem
        // path) is never touched by a rename — only the DB label changes.
        $this->assertSame('jpg', $rows[0]['ext']);

        // Restore for other tests in this class
        static::$db->execInTransaction("UPDATE bdus_files SET filename = 'photo' WHERE id = 1");
    }

    public function testUpdateFileRenameRejectsEmptyFilename(): void
    {
        $ctrl = $this->makeController(
            'Bdus\\Controllers\\File',
            ['fileId' => 1],
            ['filename' => '   ']
        );
        $res = $this->callController($ctrl, 'updateFile');

        $this->assertSame('error',             $res['status']);
        $this->assertSame('filename_required', $res['code']);

        // Unchanged
        $rows = static::$db->query("SELECT filename FROM bdus_files WHERE id = 1", [], 'read');
        $this->assertSame('photo', $rows[0]['filename']);
    }

    public function testUpdateFileNotFound(): void
    {
        $ctrl = $this->makeController(
            'Bdus\\Controllers\\File',
            ['fileId' => 9999],
            ['description' => 'x']
        );
        $res = $this->callController($ctrl, 'updateFile');

        $this->assertSame('error',           $res['status']);
        $this->assertSame('record_not_found', $res['code']);
    }

    public function testUpdateFileMissingId(): void
    {
        $ctrl = $this->makeController('Bdus\\Controllers\\File', [], ['description' => 'x']);
        $res  = $this->callController($ctrl, 'updateFile');

        $this->assertSame('error',             $res['status']);
        $this->assertSame('parameter_missing', $res['code']);
    }

    public function testUpdateFileNoFieldsIsNoop(): void
    {
        $ctrl = $this->makeController('Bdus\\Controllers\\File', ['fileId' => 1], []);
        $res  = $this->callController($ctrl, 'updateFile');

        $this->assertSame('success',         $res['status']);
        $this->assertSame('ok_file_updated', $res['code']);
    }

    public function testUpdateFileNotEnoughPrivilege(): void
    {
        $this->setPrivilege(99);

        $ctrl = $this->makeController(
            'Bdus\\Controllers\\File',
            ['fileId' => 1],
            ['description' => 'x']
        );
        $res = $this->callController($ctrl, 'updateFile');

        $this->assertSame('error',               $res['status']);
        $this->assertSame('not_enough_privilege', $res['code']);

        $this->setPrivilege(1);
    }

    // ── replaceFile ───────────────────────────────────────────────────────────

    public function testReplaceFileMissingId(): void
    {
        $ctrl = $this->makeController('Bdus\\Controllers\\File', [], []);
        $res  = $this->callController($ctrl, 'replaceFile');

        $this->assertSame('error',             $res['status']);
        $this->assertSame('parameter_missing', $res['code']);
    }

    public function testReplaceFileMissingUpload(): void
    {
        // No $_FILES set → error_uploading_file
        $ctrl = $this->makeController('Bdus\\Controllers\\File', ['fileId' => 1], []);
        $res  = $this->callController($ctrl, 'replaceFile');

        $this->assertSame('error',                $res['status']);
        $this->assertSame('error_uploading_file', $res['code']);
    }

    public function testReplaceFileNotEnoughPrivilege(): void
    {
        $this->setPrivilege(99);

        $ctrl = $this->makeController('Bdus\\Controllers\\File', ['fileId' => 1], []);
        $res  = $this->callController($ctrl, 'replaceFile');

        $this->assertSame('error',               $res['status']);
        $this->assertSame('not_enough_privilege', $res['code']);

        $this->setPrivilege(1);
    }

    // ── sortFiles ─────────────────────────────────────────────────────────────

    public function testSortFilesSuccess(): void
    {
        // Reverse the sort order: file_link 2 first, file_link 1 second.
        $ctrl = $this->makeController(
            'Bdus\\Controllers\\File',
            [],
            ['order' => [2, 1]] // sort 0 → id 2, sort 1 → id 1
        );
        $res = $this->callController($ctrl, 'sortFiles');

        $this->assertSame('success',               $res['status']);
        $this->assertSame('ok_file_sorting_update', $res['code']);

        // Verify the sort column was actually updated.
        $rows = static::$db->query(
            "SELECT id, sort FROM bdus_file_links ORDER BY sort",
            [],
            'read'
        );
        $this->assertSame(2, (int) $rows[0]['id']);
        $this->assertSame(0, (int) $rows[0]['sort']);
        $this->assertSame(1, (int) $rows[1]['id']);
        $this->assertSame(1, (int) $rows[1]['sort']);
    }

    public function testSortFilesSingleElement(): void
    {
        $ctrl = $this->makeController('Bdus\\Controllers\\File', [], ['order' => [1]]);
        $res  = $this->callController($ctrl, 'sortFiles');

        $this->assertSame('success', $res['status']);
    }

    public function testSortFilesEmptyOrderReturnsParameterMissing(): void
    {
        $ctrl = $this->makeController('Bdus\\Controllers\\File', [], ['order' => []]);
        $res  = $this->callController($ctrl, 'sortFiles');

        $this->assertSame('error',             $res['status']);
        $this->assertSame('parameter_missing', $res['code']);
    }

    public function testSortFilesMissingOrderReturnsParameterMissing(): void
    {
        $ctrl = $this->makeController('Bdus\\Controllers\\File', [], []);
        $res  = $this->callController($ctrl, 'sortFiles');

        $this->assertSame('error',             $res['status']);
        $this->assertSame('parameter_missing', $res['code']);
    }

    public function testSortFilesNotEnoughPrivilege(): void
    {
        $this->setPrivilege(99);

        $ctrl = $this->makeController('Bdus\\Controllers\\File', [], ['order' => [1, 2]]);
        $res  = $this->callController($ctrl, 'sortFiles');

        $this->assertSame('error',                 $res['status']);
        $this->assertSame('not_enough_privilege',  $res['code']);

        $this->setPrivilege(1);
    }
}
