<?php

namespace Tests\Integration;

use Tests\Support\BdusTestCase;
use Image\Derivative;

/**
 * Derived images die with their parent: the write paths that delete a file
 * must remove its cached versions (and only its own).
 *
 * BdusTestCase seeds bdus_files ids 1 and 2, both linked to items record 1.
 */
class DerivedPurgeTest extends BdusTestCase
{
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
}
