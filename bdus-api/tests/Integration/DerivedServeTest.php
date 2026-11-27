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
    private function serve(string $uuid): array
    {
        $ctrl = $this->makeController('Bdus\\Controllers\\File', ['preset' => 'thumb', 'uuid' => $uuid, 'ext' => 'webp'], []);
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
        $id   = 201;
        $uuid = static::insertFile($id, 'png', 'bad');
        $src    = PROJ_DIR . "files/{$uuid}.png";
        $marker = Derivative::cachePath(PROJ_DIR, 'thumb', $uuid, 'webp') . '.fail';
        file_put_contents($src, 'not an image at all');
        touch($src, time() - 100);

        $this->assertSame(404, $this->serve($uuid)['code']);
        $this->assertFileExists($marker, 'the failure is remembered');

        // The file becomes valid but keeps its old mtime: the marker still wins (no retry)
        $this->realPng($src);
        touch($src, time() - 100);
        $this->assertSame(404, $this->serve($uuid)['code'], 'not retried while the source is unchanged');

        // A newer source is tried again, succeeds and clears the marker
        touch($src, time() + 5);   // mtimes have 1 s granularity: be clearly newer than the marker
        clearstatcache();
        $res = $this->serve($uuid);
        $this->assertNotSame('Not found', $res['body'], 'the image is served');
        $this->assertStringStartsWith('RIFF', $res['body']);
        $this->assertFileDoesNotExist($marker);
        $this->assertFileExists(Derivative::cachePath(PROJ_DIR, 'thumb', $uuid, 'webp'));

        Derivative::purgeFile(PROJ_DIR, $uuid);
        @unlink($src);
    }

    public function testOnlyACanonicalUuidIsServed(): void
    {
        $id   = 203;
        $uuid = static::insertFile($id, 'png', 'ok');
        $src  = PROJ_DIR . "files/{$uuid}.png";
        $this->realPng($src);

        $this->assertNotSame('Not found', $this->serve($uuid)['body'], 'the uuid works');
        $this->assertSame(404, $this->serve((string) $id)['code'], 'the integer id is not an address');
        $this->assertSame(404, $this->serve('../' . $uuid)['code'], 'no path tricks');
        $this->assertSame(404, $this->serve(static::fileUuid(999999))['code'], 'an unknown uuid');

        Derivative::purgeFile(PROJ_DIR, $uuid);
        @unlink($src);
    }

    public function testPurgeFileAlsoRemovesTheFailureMarker(): void
    {
        $dir = Derivative::cacheDir(PROJ_DIR, 'thumb');
        @mkdir($dir, 0775, true);
        $uuid = static::fileUuid(202);
        file_put_contents($dir . "/{$uuid}.webp.fail", '');
        file_put_contents($dir . "/{$uuid}x.webp.fail", '');

        Derivative::purgeFile(PROJ_DIR, $uuid);

        $this->assertFileDoesNotExist($dir . "/{$uuid}.webp.fail");
        $this->assertFileExists($dir . "/{$uuid}x.webp.fail", 'names sharing a prefix are untouched');
        @unlink($dir . "/{$uuid}x.webp.fail");
    }
}
