<?php

declare(strict_types=1);

namespace Tests\Unit;

use PHPUnit\Framework\TestCase;

/**
 * Guards the PHP runtime limits shipped in the bdus-api image
 * (docker/php-uploads.ini → /usr/local/etc/php/conf.d/zz-bradypus.ini).
 *
 * The test container is built from the same Dockerfile, so ini_get() here
 * reflects what a deployed instance sees. Thresholds are deliberately loose:
 * they only fail if the image fell back to PHP's 2M/8M defaults.
 */
class PhpRuntimeConfigTest extends TestCase
{
    private const MIN_UPLOAD_BYTES = 32 * 1024 * 1024; // 32 MiB

    private static function toBytes(string $val): int
    {
        $val = trim($val);
        if ($val === '') {
            return 0;
        }
        $num  = (int) $val;
        return match (strtolower($val[strlen($val) - 1])) {
            'g'     => $num * 1024 * 1024 * 1024,
            'm'     => $num * 1024 * 1024,
            'k'     => $num * 1024,
            default => (int) $val,
        };
    }

    public function testUploadSizeIsUsableForPhotos(): void
    {
        $this->assertGreaterThanOrEqual(
            self::MIN_UPLOAD_BYTES,
            self::toBytes((string) ini_get('upload_max_filesize')),
            'upload_max_filesize below 32 MiB — is docker/php-uploads.ini shipped in the image?'
        );
    }

    public function testPostMaxSizeCoversTheUpload(): void
    {
        $upload = self::toBytes((string) ini_get('upload_max_filesize'));
        $post   = self::toBytes((string) ini_get('post_max_size'));

        $this->assertGreaterThanOrEqual(self::MIN_UPLOAD_BYTES, $post, 'post_max_size below 32 MiB');
        $this->assertGreaterThanOrEqual($upload, $post, 'post_max_size must be >= upload_max_filesize');
    }
}
