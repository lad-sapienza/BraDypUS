<?php

declare(strict_types=1);

namespace Tests\Unit;

use Auth\ApiKeyAuth;
use PHPUnit\Framework\TestCase;

/** The key is read from the Authorization header only, never from the URL. */
class ApiKeyAuthTest extends TestCase
{
    protected function tearDown(): void
    {
        unset($_SERVER['HTTP_AUTHORIZATION'], $_SERVER['REDIRECT_HTTP_AUTHORIZATION'], $_GET['api_key']);
    }

    private function extract(): ?string
    {
        $m = new \ReflectionMethod(ApiKeyAuth::class, 'extractKey');
        return $m->invoke(null);
    }

    public function testBearerHeaderIsAccepted(): void
    {
        $_SERVER['HTTP_AUTHORIZATION'] = 'Bearer abc123';
        $this->assertSame('abc123', $this->extract());
    }

    public function testQueryStringKeyIsIgnored(): void
    {
        $_GET['api_key'] = 'abc123';
        $this->assertNull($this->extract());
    }
}
