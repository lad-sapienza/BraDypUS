<?php

namespace Tests\Unit;

use Bdus\Router;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

/**
 * Route gate for API keys, with and without a group (#78).
 */
class RouterGroupKeyTest extends TestCase
{
    private const CTRL = 'Bdus\\Controllers\\Record';

    public function testEveryGroupKeyRouteIsAReadOrEditRouteOfAnExistingMethod(): void
    {
        foreach (Router::GROUP_KEY_ROUTES as $route) {
            $this->assertArrayHasKey($route, Router::ROUTE_PRIVILEGE, "$route is not a known route");
            $this->assertContains(Router::ROUTE_PRIVILEGE[$route], ['read', 'edit'], "$route is not a read/edit route");
            [$class, $method] = explode('::', $route);
            $this->assertTrue(method_exists($class, $method), "$route does not exist");
        }
        $this->assertSame(count(Router::GROUP_KEY_ROUTES), count(array_unique(Router::GROUP_KEY_ROUTES)));
    }

    public function testNoAdminOrSystemRouteIsOpenToScopedKeys(): void
    {
        foreach (Router::GROUP_KEY_ROUTES as $route) {
            $this->assertStringNotContainsString('Api::', $route);
            $this->assertStringNotContainsString('User::', $route);
            $this->assertStringNotContainsString('Config', $route);
            $this->assertStringNotContainsString('SearchReplace', $route);
        }
    }

    #[DataProvider('tierCases')]
    public function testTierAloneDecidesLikeBefore(string $required, int $tier, bool $expected): void
    {
        $this->assertSame($expected, Router::apiKeyMayReach(self::CTRL, 'getRecords', $required, $tier, null));
    }

    public static function tierCases(): array
    {
        return [
            'read ok at 30'   => ['read', 30, true],
            'read no at 39'   => ['read', 39, false],
            'edit ok at 25'   => ['edit', 25, true],
            'edit no at 30'   => ['edit', 30, false],
            'admin ok at 10'  => ['admin', 10, true],
            'admin no at 25'  => ['admin', 25, false],
            'super only at 1' => ['super_admin', 10, false],
            'unknown level'   => ['other', 1, false],
        ];
    }

    public function testAGroupOpensDataRoutesBelowTheKeysTier(): void
    {
        $this->assertTrue(Router::apiKeyMayReach(self::CTRL, 'getRecords', 'read', 39, 5));
        $this->assertTrue(Router::apiKeyMayReach(self::CTRL, 'saveRecord', 'edit', 39, 5));
        $this->assertTrue(Router::apiKeyMayReach(self::CTRL, 'saveRecord', 'edit', 30, 5));
    }

    public function testAGroupDoesNotOpenOtherRoutes(): void
    {
        // saved queries, charts list, users, keys, config, restore: not data routes
        foreach ([
            ['Bdus\\Controllers\\SavedQueries', 'listQueries', 'read'],
            ['Bdus\\Controllers\\Chart', 'listCharts', 'read'],
            ['Bdus\\Controllers\\User', 'showList', 'admin'],
            ['Bdus\\Controllers\\Api', 'createKey', 'admin'],
            ['Bdus\\Controllers\\Record', 'restoreVersion', 'admin'],
            ['Bdus\\Controllers\\Unlisted', 'whatever', 'super_admin'],
        ] as [$ctrl, $method, $required]) {
            $this->assertFalse(Router::apiKeyMayReach($ctrl, $method, $required, 39, 5), "$ctrl::$method");
        }
    }

    public function testWithoutAGroupATier39KeyReachesNothing(): void
    {
        $this->assertFalse(Router::apiKeyMayReach(self::CTRL, 'getRecords', 'read', 39, null));
        $this->assertFalse(Router::apiKeyMayReach(self::CTRL, 'getRecords', 'read', 39, 0));
    }
}
