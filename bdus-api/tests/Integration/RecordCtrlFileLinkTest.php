<?php

namespace Tests\Integration;

use Tests\Support\BdusTestCase;

/**
 * Tests for Record::linkFile() and Record::unlinkFile().
 *
 * linkFile  — POST /api/record/{tb}/{id}/link-file
 *   Creates a bdus_file_links row for an existing file without uploading a new binary.
 *
 * unlinkFile — DELETE /api/file-link/{linkId}
 *   Deletes only the bdus_file_links row; the file binary and bdus_files record are kept.
 */
class RecordCtrlFileLinkTest extends BdusTestCase
{
    private const TB       = 'items';
    private const RECORD   = 2;   // item 2 has no files yet (items/1 has files 1+2)
    private const FILE_ID  = 1;   // jpg image, linked to items/1
    private const FILE_PDF = 2;   // pdf, linked to items/1

    // ── linkFile: parameter validation ──────────────────────────────────────

    public function testLinkFileMissingTbReturnsError(): void
    {
        $ctrl = $this->makeController('Bdus\\Controllers\\Record', ['id' => self::RECORD], ['fileId' => self::FILE_ID]);
        $res  = $this->callController($ctrl, 'linkFile');

        $this->assertSame('error',             $res['status']);
        $this->assertSame('parameter_missing', $res['code']);
    }

    public function testLinkFileMissingIdReturnsError(): void
    {
        $ctrl = $this->makeController('Bdus\\Controllers\\Record', ['tb' => self::TB], ['fileId' => self::FILE_ID]);
        $res  = $this->callController($ctrl, 'linkFile');

        $this->assertSame('error',             $res['status']);
        $this->assertSame('parameter_missing', $res['code']);
    }

    public function testLinkFileMissingFileIdReturnsError(): void
    {
        $ctrl = $this->makeController('Bdus\\Controllers\\Record', ['tb' => self::TB, 'id' => self::RECORD]);
        $res  = $this->callController($ctrl, 'linkFile');

        $this->assertSame('error',             $res['status']);
        $this->assertSame('parameter_missing', $res['code']);
    }

    // ── linkFile: not found ──────────────────────────────────────────────────

    public function testLinkFileUnknownFileIdReturnsError(): void
    {
        $ctrl = $this->makeController(
            'Bdus\\Controllers\\Record',
            ['tb' => self::TB, 'id' => self::RECORD],
            ['fileId' => 9999]
        );
        $res  = $this->callController($ctrl, 'linkFile');

        $this->assertSame('error',             $res['status']);
        $this->assertSame('record_not_found',  $res['code']);
    }

    // ── linkFile: privilege ──────────────────────────────────────────────────

    public function testLinkFileNotEnoughPrivilegeReturnsError(): void
    {
        $this->setPrivilege(30); // reader — can read but not edit (edit requires priv < 21)
        $ctrl = $this->makeController(
            'Bdus\\Controllers\\Record',
            ['tb' => self::TB, 'id' => self::RECORD],
            ['fileId' => self::FILE_ID]
        );
        $res  = $this->callController($ctrl, 'linkFile');
        $this->setPrivilege(10);

        $this->assertSame('error',                 $res['status']);
        $this->assertSame('not_enough_privilege',  $res['code']);
    }

    // ── linkFile: happy path ─────────────────────────────────────────────────

    public function testLinkFileSuccess(): void
    {
        $ctrl = $this->makeController(
            'Bdus\\Controllers\\Record',
            ['tb' => self::TB, 'id' => self::RECORD],
            ['fileId' => self::FILE_ID]
        );
        $res = $this->callController($ctrl, 'linkFile');

        $this->assertSame('success',       $res['status']);
        $this->assertSame('ok_file_linked', $res['code']);
        $this->assertArrayHasKey('file',   $res);

        $file = $res['file'];
        $this->assertSame(self::FILE_ID,   $file['id']);
        $this->assertSame('jpg',           $file['ext']);
        $this->assertTrue($file['is_image']);
        $this->assertArrayHasKey('link_id', $file);
        $this->assertIsInt($file['link_id']);
        $this->assertGreaterThan(0, $file['link_id']);

        // Verify the bdus_file_links row was created
        $rows = static::$db->query(
            "SELECT * FROM bdus_file_links WHERE id = ?",
            [$file['link_id']]
        );
        $this->assertCount(1, $rows);
        $this->assertSame(self::FILE_ID,                 (int)$rows[0]['file_id']);
        $this->assertSame(self::TB,                       $rows[0]['table_name']);
        $this->assertSame(self::RECORD,                   (int)$rows[0]['record_id']);
    }

    // ── unlinkFile: parameter validation ────────────────────────────────────

    public function testUnlinkFileMissingLinkIdReturnsError(): void
    {
        $ctrl = $this->makeController('Bdus\\Controllers\\Record', []);
        $res  = $this->callController($ctrl, 'unlinkFile');

        $this->assertSame('error',             $res['status']);
        $this->assertSame('parameter_missing', $res['code']);
    }

    // ── unlinkFile: not found ────────────────────────────────────────────────

    public function testUnlinkFileUnknownLinkIdReturnsError(): void
    {
        $ctrl = $this->makeController('Bdus\\Controllers\\Record', ['linkId' => 9999]);
        $res  = $this->callController($ctrl, 'unlinkFile');

        $this->assertSame('error',            $res['status']);
        $this->assertSame('record_not_found', $res['code']);
    }

    // ── unlinkFile: privilege ────────────────────────────────────────────────

    public function testUnlinkFileNotEnoughPrivilegeReturnsError(): void
    {
        // Fetch an existing link_id
        $links = static::$db->query(
            "SELECT id FROM bdus_file_links WHERE file_id = ? AND table_name = ? AND record_id = 1",
            [self::FILE_ID, self::TB]
        );
        $this->assertNotEmpty($links);
        $linkId = (int)$links[0]['id'];

        $this->setPrivilege(30); // reader — cannot edit
        $ctrl = $this->makeController('Bdus\\Controllers\\Record', ['linkId' => $linkId]);
        $res  = $this->callController($ctrl, 'unlinkFile');
        $this->setPrivilege(10);

        $this->assertSame('error',                $res['status']);
        $this->assertSame('not_enough_privilege', $res['code']);
    }

    // ── unlinkFile: happy path ───────────────────────────────────────────────

    public function testUnlinkFileDeletesLinkRowOnly(): void
    {
        // Get the link_id for file 2 (pdf) linked to items/1
        $links = static::$db->query(
            "SELECT id FROM bdus_file_links WHERE file_id = ? AND table_name = ? AND record_id = 1",
            [self::FILE_PDF, self::TB]
        );
        $this->assertNotEmpty($links);
        $linkId = (int)$links[0]['id'];

        $ctrl = $this->makeController('Bdus\\Controllers\\Record', ['linkId' => $linkId]);
        $res  = $this->callController($ctrl, 'unlinkFile');

        $this->assertSame('success',          $res['status']);
        $this->assertSame('ok_file_unlinked', $res['code']);

        // bdus_file_links row must be gone
        $remaining = static::$db->query(
            "SELECT id FROM bdus_file_links WHERE id = ?",
            [$linkId]
        );
        $this->assertEmpty($remaining, 'The file_links row must be deleted by unlinkFile');

        // The file itself (bdus_files) must still exist
        $file = static::$db->query(
            "SELECT id FROM bdus_files WHERE id = ?",
            [self::FILE_PDF]
        );
        $this->assertCount(1, $file, 'bdus_files row must NOT be deleted by unlinkFile');
    }
}
