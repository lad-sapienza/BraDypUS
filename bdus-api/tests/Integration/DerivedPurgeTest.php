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

    /** A cached derived version of the file with $uuid. */
    private function cache(string $uuid, string $preset = 'thumb'): string
    {
        $dir = Derivative::cacheDir(PROJ_DIR, $preset);
        @mkdir($dir, 0775, true);
        $path = $dir . "/{$uuid}.webp";
        file_put_contents($path, 'x');
        return $path;
    }

    public function testDeletingAFilePurgesItsDerivedVersions(): void
    {
        $uuid  = static::insertFile(100, 'jpg', 'own');
        $mine  = $this->cache($uuid);
        $other = $this->cache(static::fileUuid(2));
        $big   = $this->cache($uuid, 'large');
        $bin   = PROJ_DIR . "files/{$uuid}.jpg";
        file_put_contents($bin, 'x');

        $ctrl = $this->makeController('Bdus\\Controllers\\Record', [], ['fileId' => 100]);
        $res  = $this->callController($ctrl, 'deleteFile');

        $this->assertSame('ok_file_deleted', $res['code']);
        $this->assertFileDoesNotExist($mine);
        $this->assertFileDoesNotExist($big, 'every preset of the file');
        $this->assertFileDoesNotExist($bin, 'the binary named by the uuid goes with the file');
        $this->assertFileExists($other, 'other files are untouched');
        @unlink($other);
    }

    public function testErasingARecordAndItsFilesPurgesTheirVersions(): void
    {
        $b = $this->cache(static::fileUuid(2));

        $ctrl = $this->makeController('Bdus\\Controllers\\Record', ['tb' => 'items', 'id' => 1], ['files' => 'delete']);
        $res  = $this->callController($ctrl, 'erase');

        $this->assertSame('success', $res['status'], json_encode($res));
        $this->assertFileDoesNotExist($b);
        $this->assertContains(2, $res['deleted_files']);
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
        $uuid = static::insertFile(101, 'png', 'rep');
        $this->png(PROJ_DIR . "files/{$uuid}.png");
        $stale = $this->cache($uuid);
        $this->upload($this->png(sys_get_temp_dir() . '/bdus_rep_' . uniqid() . '.png', 80, 20), 'new.png');

        $ctrl = $this->makeController('Bdus\\Controllers\\File', ['fileId' => 101], []);
        $res  = $this->callController($ctrl, 'replaceFile');
        unset($_FILES);

        $this->assertSame('ok_file_replaced', $res['code'], json_encode($res));
        $this->assertFileDoesNotExist($stale);
        $this->assertSame($uuid, \File\Store::uuidOf(static::$db, 101), 'replacing the binary keeps the identity');
        $this->assertFileExists(PROJ_DIR . "files/{$uuid}.png");
        @unlink(PROJ_DIR . "files/{$uuid}.png");
    }

    public function testUploadStoresTheBinaryUnderTheUuidNotTheId(): void
    {
        $id = $this->nextFileId();
        $this->upload($this->png(sys_get_temp_dir() . '/bdus_up_' . uniqid() . '.png'), 'up.png');

        $ctrl = $this->makeController('Bdus\\Controllers\\Record', ['tb' => 'items', 'id' => 1], []);
        $res  = $this->callController($ctrl, 'uploadFile');
        unset($_FILES);

        $this->assertSame('ok_file_uploaded', $res['code'], json_encode($res));
        $this->assertSame($id, (int) $res['file']['id']);
        $uuid = $res['file']['uuid'];
        $this->assertTrue(\File\Store::isUuid($uuid), 'the response carries a canonical uuid');
        $this->assertSame($uuid, \File\Store::uuidOf(static::$db, $id), 'and it is the one stored');
        $this->assertFileExists(PROJ_DIR . "files/{$uuid}.png");
        $this->assertFileDoesNotExist(PROJ_DIR . "files/{$id}.png", 'the integer id is never a file name');
        @unlink(PROJ_DIR . "files/{$uuid}.png");
    }

    public function testPhotoImportStoresTheBinaryUnderTheUuidNotTheId(): void
    {
        // items 1 may be gone (the record-erase test shares this database): use an own record
        $rec    = (int) static::$db->query(
            "INSERT INTO items (creator, name, description, status) VALUES ('test', 'imp', 'd', 'draft')", [], 'id'
        );
        $id     = $this->nextFileId();
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
        $uuid = \File\Store::uuidOf(static::$db, $id);
        $this->assertNotNull($uuid);
        $this->assertFileExists(PROJ_DIR . "files/{$uuid}.png");
        $this->assertFileDoesNotExist(PROJ_DIR . "files/{$id}.png");
        @unlink(PROJ_DIR . "files/{$uuid}.png");
        @unlink($png);
        @unlink(sys_get_temp_dir() . "/bdus_import_{$tempId}.zip");
        @unlink(sys_get_temp_dir() . "/bdus_import_{$tempId}.csv");
    }
}
