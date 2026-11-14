<?php

declare(strict_types=1);

namespace Tests\Unit;

use Auth\ApiKeyAuth;
use Auth\ClientIp;
use Auth\RateLimiter;
use PHPUnit\Framework\TestCase;

class RateLimiterTest extends TestCase
{
    private string $dir;

    protected function setUp(): void
    {
        $this->dir = sys_get_temp_dir() . '/bdus_rl_' . bin2hex(random_bytes(4)) . '/';
        RateLimiter::setDir($this->dir);
        putenv('BRADYPUS_RATE_KEY_WRITE=3');
    }

    protected function tearDown(): void
    {
        putenv('BRADYPUS_RATE_KEY_WRITE');
        putenv('BRADYPUS_RATE_LOGIN_IP');
        foreach (glob($this->dir . '*') ?: [] as $f) {
            unlink($f);
        }
        @rmdir($this->dir);
        RateLimiter::setDir(null);
        unset($_SERVER['HTTP_AUTHORIZATION']);
        unset($_GET['api_key']);
    }

    public function testLimitThenRefuses(): void
    {
        for ($i = 0; $i < 3; $i++) {
            $this->assertSame(0, RateLimiter::hit('KEY_WRITE', 'k1'));
        }
        $this->assertGreaterThan(0, RateLimiter::hit('KEY_WRITE', 'k1'));
        $this->assertGreaterThan(0, RateLimiter::blocked('KEY_WRITE', 'k1'));
    }

    public function testBucketsAreIndependent(): void
    {
        for ($i = 0; $i < 4; $i++) {
            RateLimiter::hit('KEY_WRITE', 'k1');
        }
        $this->assertSame(0, RateLimiter::hit('KEY_WRITE', 'k2'));
    }

    public function testBlockedDoesNotCount(): void
    {
        for ($i = 0; $i < 10; $i++) {
            $this->assertSame(0, RateLimiter::blocked('KEY_WRITE', 'k1'));
        }
        $this->assertSame(0, RateLimiter::hit('KEY_WRITE', 'k1'));
    }

    public function testZeroSwitchesOff(): void
    {
        putenv('BRADYPUS_RATE_KEY_WRITE=0');
        for ($i = 0; $i < 20; $i++) {
            $this->assertSame(0, RateLimiter::hit('KEY_WRITE', 'k1'));
        }
    }

    public function testUnusableDirFailsOpen(): void
    {
        RateLimiter::setDir('/proc/nope/');
        for ($i = 0; $i < 10; $i++) {
            $this->assertSame(0, RateLimiter::hit('KEY_WRITE', 'k1'));
        }
    }

    public function testClientIpIgnoresForwardedHeaderWithoutTrustedProxy(): void
    {
        $s = ['REMOTE_ADDR' => '10.0.0.5', 'HTTP_X_FORWARDED_FOR' => '1.2.3.4'];
        $this->assertSame('10.0.0.5', ClientIp::get($s, ''));
        $this->assertSame('10.0.0.5', ClientIp::get($s, '192.168.0.1'));
    }

    public function testClientIpBehindTrustedProxy(): void
    {
        $s = ['REMOTE_ADDR' => '192.168.3.186', 'HTTP_X_FORWARDED_FOR' => '6.6.6.6, 9.9.9.9'];
        // the client forged 6.6.6.6; the proxy appended the address it really saw
        $this->assertSame('9.9.9.9', ClientIp::get($s, '192.168.3.186'));
        $this->assertSame('9.9.9.9', ClientIp::get($s, '192.168.3.0/24'));
        $s['HTTP_X_FORWARDED_FOR'] = '9.9.9.9, 192.168.3.1';
        $this->assertSame('9.9.9.9', ClientIp::get($s, '192.168.3.0/24'));
    }

    public function testHasCredentialOnlyWithBearerHeader(): void
    {
        $this->assertFalse(ApiKeyAuth::hasCredential());
        $_SERVER['HTTP_AUTHORIZATION'] = 'Bearer abc';
        $this->assertTrue(ApiKeyAuth::hasCredential());
    }
}
