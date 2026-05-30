<?php

namespace Tests\Integration;

use Tests\Support\BdusTestCase;

/**
 * Regression tests for record_ctrl::uploadFile().
 *
 * Bug guarded against:
 *
 *   "Call to undefined method DB\DB::lastId()"
 *
 *   After inserting the file record, the code called $this->db->lastId() which
 *   does not exist on DB\DB.  The correct idiom is to pass 'id' as the $type
 *   argument to DB::query(), which returns PDO::lastInsertId().  Because no
 *   test exercised uploadFile at all, this crash went undetected.
 *
 * What we can test without a real HTTP multipart upload:
 *
 *   • Parameter validation (tb / id missing) — purely PHP, no file I/O.
 *   • $_FILES absence / error flag — PHP, no file I/O.
 *   • The DB insert-then-get-id path: we simulate a valid $_FILES entry that
 *     passes the UPLOAD_ERR_OK guard, let the code reach the INSERT statement
 *     and the DB::query(...,'id') call, then hit the move_uploaded_file()
 *     failure (expected in CLI).  The resulting error must be the file-move
 *     failure, NOT "Call to undefined method DB\DB::lastId()".  We also verify
 *     the rollback DELETE ran, so no orphan row remains in bdus_files.
 */
class RecordCtrlFileUploadTest extends BdusTestCase
{
    private const TB = 'items';
    private const ID = 1;

    // ── parameter validation ──────────────────────────────────────────────

    public function testUploadFileMissingTbReturnsError(): void
    {
        $ctrl = $this->makeController('Bdus\\Controllers\\Record', ['id' => self::ID]);
        $res  = $this->callController($ctrl, 'uploadFile');

        $this->assertSame('error',             $res['status']);
        $this->assertSame('parameter_missing', $res['code']);
    }

    public function testUploadFileMissingIdReturnsError(): void
    {
        $ctrl = $this->makeController('Bdus\\Controllers\\Record', ['tb' => self::TB]);
        $res  = $this->callController($ctrl, 'uploadFile');

        $this->assertSame('error',             $res['status']);
        $this->assertSame('parameter_missing', $res['code']);
    }

    // ── $_FILES guard ─────────────────────────────────────────────────────

    public function testUploadFileWithNoFilesGlobalReturnsError(): void
    {
        // Ensure $_FILES is empty
        $_FILES = [];

        $ctrl = $this->makeController('Bdus\\Controllers\\Record', ['tb' => self::TB, 'id' => self::ID]);
        $res  = $this->callController($ctrl, 'uploadFile');

        $this->assertSame('error',                $res['status']);
        $this->assertSame('error_uploading_file', $res['code']);
    }

    public function testUploadFileWithFileErrorFlagReturnsError(): void
    {
        $_FILES = [
            'file' => [
                'name'     => 'test.png',
                'type'     => 'image/png',
                'tmp_name' => '/tmp/phpXXXXXX',
                'error'    => UPLOAD_ERR_PARTIAL,   // not UPLOAD_ERR_OK
                'size'     => 1024,
            ],
        ];

        $ctrl = $this->makeController('Bdus\\Controllers\\Record', ['tb' => self::TB, 'id' => self::ID]);
        $res  = $this->callController($ctrl, 'uploadFile');

        $this->assertSame('error',                $res['status']);
        $this->assertSame('error_uploading_file', $res['code']);

        $_FILES = [];
    }

    // ── DB insert + id path (regression: DB::lastId() does not exist) ─────

    /**
     * Simulate a file that passes the UPLOAD_ERR_OK guard but cannot be moved
     * (move_uploaded_file always returns false in CLI — it requires a real HTTP
     * upload).  The code path is:
     *
     *   INSERT INTO bdus_files → DB::query(..., 'id')   ← regression point
     *   move_uploaded_file()  → false
     *   DELETE FROM bdus_files WHERE id = ?              ← rollback
     *   throw RuntimeException('move_uploaded_file failed')
     *
     * Assertions:
     *   1. The error detail must NOT contain "Call to undefined method" — that
     *      would mean DB::lastId() was called instead of the fixed DB::query('id').
     *   2. The error code must be 'error_uploading_file' (the expected failure mode).
     *   3. No orphan row must remain in bdus_files (rollback ran).
     */
    public function testUploadFileDbInsertPathDoesNotCallUndefinedLastId(): void
    {
        // Create a real temporary file so is_uploaded_file() at least gets a path.
        // move_uploaded_file() will still return false (not a real HTTP upload).
        $tmpPath = tempnam(sys_get_temp_dir(), 'bdus_test_');
        file_put_contents($tmpPath, 'fake png content');

        $_FILES = [
            'file' => [
                'name'     => 'regression.png',
                'type'     => 'image/png',
                'tmp_name' => $tmpPath,
                'error'    => UPLOAD_ERR_OK,
                'size'     => filesize($tmpPath),
            ],
        ];

        // Record the highest bdus_files id before the call
        $before = static::$db->query(
            'SELECT COALESCE(MAX(id), 0) AS max_id FROM bdus_files',
            [],
            'read'
        );
        $maxIdBefore = (int)$before[0]['max_id'];

        $ctrl = $this->makeController('Bdus\\Controllers\\Record', ['tb' => self::TB, 'id' => self::ID]);
        $res  = $this->callController($ctrl, 'uploadFile');

        // Clean up temp file
        @unlink($tmpPath);
        $_FILES = [];

        // 1. Must be an error (move_uploaded_file fails in CLI) — but the *right* error.
        $this->assertSame('error',                $res['status']);
        $this->assertSame('error_uploading_file', $res['code']);

        // 2. The error detail must not mention lastId() — that would be the old bug.
        $detail = $res['detail'] ?? '';
        $this->assertStringNotContainsStringIgnoringCase(
            'lastId',
            $detail,
            'Error must not originate from a missing DB::lastId() method.'
        );
        $this->assertStringNotContainsStringIgnoringCase(
            'undefined method',
            $detail,
            'Error must not be a fatal "undefined method" crash.'
        );

        // 3. Rollback must have removed any inserted row — no orphan in bdus_files.
        $after = static::$db->query(
            'SELECT COALESCE(MAX(id), 0) AS max_id FROM bdus_files',
            [],
            'read'
        );
        $this->assertSame(
            $maxIdBefore,
            (int)$after[0]['max_id'],
            'Rollback DELETE must have removed the inserted bdus_files row.'
        );
    }
}
