<?php

namespace Tests\Unit;

use File\Delivery;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

/** File\Delivery: a stored file must never run as a page of the application. */
class FileDeliveryTest extends TestCase
{
    #[DataProvider('shownInTheBrowser')]
    public function testPlainMediaIsShownInline(string $ext, string $type): void
    {
        $this->assertTrue(Delivery::isInline($ext));
        $this->assertSame($type, Delivery::contentType($ext));
        $this->assertStringStartsWith('inline;', Delivery::disposition('name', $ext));
    }

    public static function shownInTheBrowser(): array
    {
        return [
            'jpg' => ['jpg', 'image/jpeg'], 'png' => ['png', 'image/png'], 'webp' => ['webp', 'image/webp'],
            'svg' => ['svg', 'image/svg+xml'], 'pdf' => ['pdf', 'application/pdf'],
            'txt' => ['txt', 'text/plain; charset=utf-8'], 'mp4' => ['mp4', 'video/mp4'], 'upper' => ['JPG', 'image/jpeg'],
        ];
    }

    #[DataProvider('onlyDownloaded')]
    public function testAnythingThatCouldRunIsOnlyDownloaded(string $ext): void
    {
        $this->assertFalse(Delivery::isInline($ext), $ext);
        $this->assertStringStartsWith('attachment;', Delivery::disposition('name', $ext), $ext);
        $this->assertNotSame('text/html', Delivery::contentType($ext));
    }

    public static function onlyDownloaded(): array
    {
        return array_map(fn($e) => [$e], ['html', 'htm', 'xhtml', 'js', 'mjs', 'xml', 'xsl', 'php', 'phtml', 'swf', 'hta', 'csv', 'zip', 'docx', '', 'unknownthing']);
    }

    public function testHtmlIsNeverGivenAnHtmlContentType(): void
    {
        foreach (['html', 'htm', 'xhtml'] as $ext) {
            $this->assertSame('application/octet-stream', Delivery::contentType($ext));
        }
    }

    public function testTheNameCannotInjectHeadersOrQuotes(): void
    {
        $d = Delivery::disposition("a\"b\r\nX-Evil: 1/../c\\d", 'png');
        $this->assertStringNotContainsString("\r", $d);
        $this->assertStringNotContainsString("\n", $d);
        $this->assertStringNotContainsString('../', $d);
        $this->assertSame(1, preg_match('/^inline; filename="[^"\\\\]*"; filename\*=UTF-8\'\'[A-Za-z0-9%._~-]*$/', $d), $d);
    }

    public function testAnEmptyNameGetsADefaultAndUnicodeIsPreserved(): void
    {
        $this->assertStringContainsString('filename="file.png"', Delivery::disposition('', 'png'));
        $d = Delivery::disposition('Σερῆνε', 'jpg');
        $this->assertStringContainsString('filename="______.jpg"', $d, 'ASCII fallback');
        $this->assertStringContainsString("filename*=UTF-8''" . rawurlencode('Σερῆνε.jpg'), $d, 'real name kept for browsers that read it');
    }

    public function testHeadersForbidSniffingAndSandboxAndRevalidate(): void
    {
        $h = Delivery::headers('x', 'svg');
        $this->assertSame('nosniff', $h['X-Content-Type-Options']);
        $this->assertStringContainsString('sandbox', $h['Content-Security-Policy']);
        $this->assertStringContainsString("default-src 'none'", $h['Content-Security-Policy']);
        $this->assertSame('private, no-cache', $h['Cache-Control']);
        $this->assertStringContainsString('Cookie', $h['Vary']);
    }
}
