<?php

namespace Tests\Unit;

use File\Store;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

/**
 * File\Store: the naming convention of the binaries (files/{uuid}.{ext}).
 * Pure functions and the filesystem only; the DB side is covered by the controller tests.
 */
class FileStoreTest extends TestCase
{
    private string $proj;

    protected function setUp(): void
    {
        $this->proj = sys_get_temp_dir() . '/bdus_store_' . uniqid() . '/';
        mkdir($this->proj . 'files', 0755, true);
    }

    protected function tearDown(): void
    {
        foreach (glob($this->proj . 'files/*') ?: [] as $f) {
            @unlink($f);
        }
        foreach (glob($this->proj . 'cache/*/*') ?: [] as $f) {
            @unlink($f);
        }
        foreach (glob($this->proj . 'cache/*') ?: [] as $d) {
            @rmdir($d);
        }
        @rmdir($this->proj . 'cache');
        @rmdir($this->proj . 'files');
        @rmdir($this->proj);
    }

    public function testNewUuidIsACanonicalRandomV4(): void
    {
        $seen = [];
        for ($i = 0; $i < 200; $i++) {
            $u = Store::newUuid();
            $this->assertTrue(Store::isUuid($u), $u);
            $this->assertSame('4', $u[14], 'version 4');
            $this->assertContains($u[19], ['8', '9', 'a', 'b'], 'RFC 4122 variant');
            $seen[$u] = true;
        }
        $this->assertCount(200, $seen, 'no repeats');
    }

    #[DataProvider('notUuids')]
    public function testIsUuidRefusesAnythingElse(mixed $value): void
    {
        $this->assertFalse(Store::isUuid($value));
    }

    public static function notUuids(): array
    {
        return [
            'integer id'        => [42],
            'numeric string'    => ['42'],
            'empty'             => [''],
            'null'              => [null],
            'uppercase'         => ['00000000-0000-4000-8000-00000000000A'],
            'no dashes'         => ['00000000000040008000000000000001'],
            'too long'          => ['00000000-0000-4000-8000-0000000000011'],
            'path trick'        => ['../00000000-0000-4000-8000-000000000001'],
            'trailing newline'  => ["00000000-0000-4000-8000-000000000001\n"],
        ];
    }

    public function testPathIsBuiltFromTheUuidAndTheExtension(): void
    {
        $u = '00000000-0000-4000-8000-000000000001';
        $this->assertSame($this->proj . "files/{$u}.jpg", Store::path($this->proj, $u, 'jpg'));
        $this->assertSame($this->proj . "files/{$u}.jpg", Store::path($this->proj, $u, 'JPG'), 'extension lower-cased');
        $this->assertSame($this->proj . "files/{$u}", Store::path($this->proj, $u, ''), 'no extension, no dot');
    }

    #[DataProvider('badPaths')]
    public function testPathRefusesWhatCouldEscapeTheFolder(string $uuid, string $ext): void
    {
        $this->expectException(\InvalidArgumentException::class);
        Store::path($this->proj, $uuid, $ext);
    }

    public static function badPaths(): array
    {
        $u = '00000000-0000-4000-8000-000000000001';
        return [
            'integer id as name' => ['7', 'jpg'],
            'traversal in uuid'  => ['../../etc/passwd', 'jpg'],
            'traversal in ext'   => [$u, '../x'],
            'dot in ext'         => [$u, 'tar.gz'],
            'space in ext'       => [$u, 'jp g'],
        ];
    }

    public function testRemoveDeletesTheBinaryAndItsDerivedVersionsOnly(): void
    {
        $a = '00000000-0000-4000-8000-00000000000a';
        $b = '00000000-0000-4000-8000-00000000000b';
        mkdir($this->proj . 'cache/thumb', 0775, true);
        foreach ([$a, $b] as $u) {
            file_put_contents($this->proj . "files/{$u}.png", 'x');
            file_put_contents($this->proj . "cache/thumb/{$u}.webp", 'x');
        }

        Store::remove($this->proj, $a, 'png');

        $this->assertFileDoesNotExist($this->proj . "files/{$a}.png");
        $this->assertFileDoesNotExist($this->proj . "cache/thumb/{$a}.webp");
        $this->assertFileExists($this->proj . "files/{$b}.png", 'another file is untouched');
        $this->assertFileExists($this->proj . "cache/thumb/{$b}.webp");
    }

    public function testRemoveToleratesLegacyRowsAndMissingFiles(): void
    {
        Store::remove($this->proj, '', 'png');                                   // a row with no uuid
        Store::remove($this->proj, '00000000-0000-4000-8000-000000000001', 'p/g'); // an unusable extension
        Store::remove($this->proj, '00000000-0000-4000-8000-000000000001', 'png'); // nothing on disk
        $this->addToAssertionCount(1); // reaching here without an exception is the point
    }
}
