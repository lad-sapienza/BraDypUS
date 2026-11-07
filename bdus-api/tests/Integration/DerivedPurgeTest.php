<?php

namespace Tests\Integration;

use Tests\Support\BdusTestCase;
use Image\Derivative;

require_once __DIR__ . '/../Support/UploadShim.php';

/**
 * Derived images die with their parent: the write paths that delete a file
 * must remove its cached versions (and only its own).
 *
 * BdusTestCase seeds bdus_files ids 1 and 2, both linked to items record 1.
 */
class DerivedPurgeTest extends BdusTestCase
{
    protected function setUp(): void
    {
        parent::setUp();
        $GLOBALS['bdus_test_move_uploaded'] = true;
    }

    protected function tearDown(): void
    {
        unset($GLOBALS['bdus_test_move_uploaded'], $_FILES);
        parent::tearDown();
    }

    private function cache(int $id, string $preset = 'thumb'): string
    {
        $dir = Derivative::cacheDir(PROJ_DIR, $preset);
        @mkdir($dir, 0775, true);
        $path = $dir . "/{$id}.webp";
        file_put_contents($path, 'x');
        return $path;
    }

    public function testDeletingAFilePurgesItsDerivedVersions(): void
    {
        static::$db->query(
            "INSERT INTO bdus_files (id, ext, filename, creator) VALUES (100, 'jpg', 'own', 'test')", [], 'boolean'
        );
        $mine  = $this->cache(100);
        $other = $this->cache(2);
        $big   = $this->cache(100, 'large');

        $ctrl = $this->makeController('Bdus\\Controllers\\Record', [], ['fileId' => 100]);
        $res  = $this->callController($ctrl, 'deleteFile');

        $this->assertSame('ok_file_deleted', $res['code']);
        $this->assertFileDoesNotExist($mine);
        $this->assertFileDoesNotExist($big, 'every preset of the file');
        $this->assertFileExists($other, 'other files are untouched');
        @unlink($other);
    }

    public function testErasingARecordPurgesVersionsOfItsExclusiveFiles(): void
    {
        $b = $this->cache(2);

        $ctrl = $this->makeController('Bdus\\Controllers\\Record', ['tb' => 'items', 'id' => 1], []);
        $res  = $this->callController($ctrl, 'erase');

        $this->assertSame('success', $res['status'], json_encode($res));
        $this->assertFileDoesNotExist($b);
    }

    /** A real PNG on disk (GD), returns its path. */
    private function png(string $path, int $w = 60, int $h = 40): string
    {
        $im = imagecreatetruecolor($w, $h);
        imagefill($im, 0, 0, imagecolorallocate($im, 10, 120, 200));
        imagepng($im, $path);
        imagedestroy($im);
        return $path;
    }

    private function nextFileId(): int
    {
        return (int) (static::$db->query('SELECT MAX(id) AS m FROM bdus_files', [], 'read')[0]['m'] ?? 0) + 1;
    }

    private function upload(string $tmp, string $name): void
    {
        $_FILES = ['file' => ['name' => $name, 'type' => 'image/png', 'tmp_name' => $tmp, 'error' => UPLOAD_ERR_OK, 'size' => filesize($tmp)]];
    }

    public function testReplacingTheBinaryPurgesDerivedVersions(): void
    {
        static::$db->query(
            "INSERT INTO bdus_files (id, ext, filename, creator) VALUES (101, 'png', 'rep', 'test')", [], 'boolean'
        );
        $this->png(PROJ_DIR . 'files/101.png');
        $stale = $this->cache(101);
        $this->upload($this->png(sys_get_temp_dir() . '/bdus_rep_' . uniqid() . '.png', 80, 20), 'new.png');

        $ctrl = $this->makeController('Bdus\\Controllers\\File', ['fileId' => 101], []);
        $res  = $this->callController($ctrl, 'replaceFile');
        unset($_FILES);

        $this->assertSame('ok_file_replaced', $res['code'], json_encode($res));
        $this->assertFileDoesNotExist($stale);
        @unlink(PROJ_DIR . 'files/101.png');
    }

    public function testUploadOverAReusedIdDropsStaleVersions(): void
    {
        $id    = $this->nextFileId();
        $stale = $this->cache($id);
        $this->upload($this->png(sys_get_temp_dir() . '/bdus_up_' . uniqid() . '.png'), 'up.png');

        $ctrl = $this->makeController('Bdus\\Controllers\\Record', ['tb' => 'items', 'id' => 1], []);
        $res  = $this->callController($ctrl, 'uploadFile');
        unset($_FILES);

        $this->assertSame('ok_file_uploaded', $res['code'], json_encode($res));
        $this->assertSame($id, (int) $res['file']['id']);
        $this->assertFileDoesNotExist($stale, 'a new file must not inherit a stale version');
        @unlink(PROJ_DIR . 'files/' . $id . '.png');
    }

    public function testPhotoImportOverAReusedIdDropsStaleVersions(): void
    {
        // items 1 may be gone (the record-erase test shares this database): use an own record
        $rec    = (int) static::$db->query(
            "INSERT INTO items (creator, name, description, status) VALUES ('test', 'imp', 'd', 'draft')", [], 'id'
        );
        $id     = $this->nextFileId();
        $stale  = $this->cache($id);
        $tempId = 'derivedtest' . uniqid();
        $png    = $this->png(sys_get_temp_dir() . '/bdus_imp_' . uniqid() . '.png');

        $zip = new \ZipArchive();
        $zip->open(sys_get_temp_dir() . "/bdus_import_{$tempId}.zip", \ZipArchive::CREATE);
        $zip->addFile($png, 'pic.png');
        $zip->close();
        file_put_contents(sys_get_temp_dir() . "/bdus_import_{$tempId}.csv", "filename,record_id\npic.png,{$rec}\n");

        $ctrl = $this->makeController('Bdus\\Controllers\\Import', [], ['temp_id' => $tempId, 'tb' => 'items']);
        $res  = $this->callController($ctrl, 'importPhotos');

        $this->assertSame('success', $res['status'], json_encode($res));
        $this->assertSame(1, (int) $res['linked'], json_encode($res));
        $this->assertFileDoesNotExist($stale);
        @unlink(PROJ_DIR . 'files/' . $id . '.png');
        @unlink($png);
        @unlink(sys_get_temp_dir() . "/bdus_import_{$tempId}.zip");
        @unlink(sys_get_temp_dir() . "/bdus_import_{$tempId}.csv");
    }
}
