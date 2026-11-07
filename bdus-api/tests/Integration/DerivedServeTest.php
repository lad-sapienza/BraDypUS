<?php

namespace Tests\Integration;

use Tests\Support\BdusTestCase;
use Image\Derivative;

/**
 * File::serveDerived(): a source that cannot be processed is remembered (marker
 * next to the cache path) and not retried until it changes or is purged.
 */
class DerivedServeTest extends BdusTestCase
{
    private function serve(int $id): array
    {
        $ctrl = $this->makeController('Bdus\\Controllers\\File', ['preset' => 'thumb', 'fileId' => $id, 'ext' => 'webp'], []);
        ob_start();
        $ctrl->serveDerived();
        $body = ob_get_clean();
        return ['code' => http_response_code(), 'body' => $body];
    }

    private function realPng(string $path): void
    {
        $im = imagecreatetruecolor(300, 200);
        imagefill($im, 0, 0, imagecolorallocate($im, 200, 20, 20));
        imagepng($im, $path);
        imagedestroy($im);
    }

    public function testFailedGenerationIsRememberedUntilTheSourceChanges(): void
    {
        $id = 201;
        static::$db->query("INSERT INTO bdus_files (id, ext, filename, creator) VALUES ({$id}, 'png', 'bad', 'test')", [], 'boolean');
        $src    = PROJ_DIR . "files/{$id}.png";
        $marker = Derivative::cachePath(PROJ_DIR, 'thumb', $id, 'webp') . '.fail';
        file_put_contents($src, 'not an image at all');
        touch($src, time() - 100);

        $this->assertSame(404, $this->serve($id)['code']);
        $this->assertFileExists($marker, 'the failure is remembered');

        // The file becomes valid but keeps its old mtime: the marker still wins (no retry)
        $this->realPng($src);
        touch($src, time() - 100);
        $this->assertSame(404, $this->serve($id)['code'], 'not retried while the source is unchanged');

        // A newer source is tried again, succeeds and clears the marker
        touch($src, time() + 5);   // mtimes have 1 s granularity: be clearly newer than the marker
        clearstatcache();
        $res = $this->serve($id);
        $this->assertNotSame('Not found', $res['body'], 'the image is served');
        $this->assertStringStartsWith('RIFF', $res['body']);
        $this->assertFileDoesNotExist($marker);
        $this->assertFileExists(Derivative::cachePath(PROJ_DIR, 'thumb', $id, 'webp'));

        Derivative::purgeFile(PROJ_DIR, $id);
        @unlink($src);
    }

    public function testPurgeFileAlsoRemovesTheFailureMarker(): void
    {
        $dir = Derivative::cacheDir(PROJ_DIR, 'thumb');
        @mkdir($dir, 0775, true);
        file_put_contents($dir . '/202.webp.fail', '');
        file_put_contents($dir . '/2020.webp.fail', '');

        Derivative::purgeFile(PROJ_DIR, 202);

        $this->assertFileDoesNotExist($dir . '/202.webp.fail');
        $this->assertFileExists($dir . '/2020.webp.fail', 'ids sharing a prefix are untouched');
        @unlink($dir . '/2020.webp.fail');
    }
}
