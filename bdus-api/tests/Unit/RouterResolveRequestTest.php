<?php

namespace Tests\Unit;

use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use Bdus\Router;

/**
 * Router::resolveRequest() — splits the request path into an optional leading
 * /{app} segment and the bare /api/... path that the route table matches.
 *
 * Pure function of $_SERVER['REQUEST_URI'] + $_SERVER['SCRIPT_NAME']; the memo
 * inside resolveRequest() is keyed on both so cases here never collide.
 */
class RouterResolveRequestTest extends TestCase
{
    #[DataProvider('cases')]
    public function testResolveRequest(string $uri, string $scriptName, ?string $app, string $path): void
    {
        $_SERVER['REQUEST_URI'] = $uri;
        $_SERVER['SCRIPT_NAME'] = $scriptName;

        $this->assertSame([$app, $path], Router::resolveRequest());
    }

    public static function cases(): array
    {
        return [
            // label => [REQUEST_URI, SCRIPT_NAME, expected app, expected path]
            'app-scoped, nested' => ['/paths/api/records/siti', '/index.php', 'paths', '/api/records/siti'],
            'app-scoped, bare'   => ['/paths/api', '/index.php', 'paths', '/api'],
            'app-scoped, trailing slash' => ['/paths/api/', '/index.php', 'paths', '/api/'],
            'app-scoped, query string ignored' => ['/paths/api/records/siti?q=1&x=2', '/index.php', 'paths', '/api/records/siti'],
            'app-independent login' => ['/api/auth/login', '/index.php', null, '/api/auth/login'],
            'app-independent, bare' => ['/api', '/index.php', null, '/api'],
            'reserved segment: cache' => ['/cache/api/x', '/index.php', null, '/cache/api/x'],
            'reserved segment: projects' => ['/projects/paths/files/1.jpg', '/index.php', null, '/projects/paths/files/1.jpg'],
            'SPA route, not an API path' => ['/paths/data', '/index.php', null, '/paths/data'],
            'SPA deep route' => ['/paths/record/us/42', '/index.php', null, '/paths/record/us/42'],
            'segment that only starts with api' => ['/paths/apiary/x', '/index.php', null, '/paths/apiary/x'],
            'sub-directory install, app-scoped' => ['/sub/paths/api/records/x', '/sub/index.php', 'paths', '/api/records/x'],
            'sub-directory install, app-independent' => ['/sub/api/auth/login', '/sub/index.php', null, '/api/auth/login'],
            'root path' => ['/', '/index.php', null, '/'],
            'case-insensitive reserved segment' => ['/API/api/records/x', '/index.php', null, '/API/api/records/x'],
        ];
    }
}
