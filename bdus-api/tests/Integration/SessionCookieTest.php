<?php

namespace Tests\Integration;

use Auth\CurrentUser;
use Auth\SessionCookie;
use Bdus\Router;
use PHPUnit\Framework\TestCase;

/**
 * Auth\SessionCookie: the cookie that lets a browser tag load a protected file. What matters is
 * where it is accepted (GET, on the listed read-only routes only) and that it is only ever as
 * good as the login token it carries.
 */
class SessionCookieTest extends TestCase
{
    private const APP = 'test';

    protected function setUp(): void
    {
        CurrentUser::set([]);
        @mkdir(MAIN_DIR . 'projects/' . self::APP, 0777, true);
    }

    protected function tearDown(): void
    {
        CurrentUser::set([]);
    }

    private function token(array $over = [], string $app = self::APP): string
    {
        return \JWT\JwtManager::generate($over + ['id' => 5, 'privilege' => 39, 'token_version' => 3, 'name' => 'n', 'email' => 'e@x'], $app);
    }

    // ── name ──────────────────────────────────────────────────────────────────

    public function testNameIsPerApplicationAndCookieSafe(): void
    {
        $this->assertSame('bdus_s_demo', SessionCookie::name('demo'));
        $this->assertNotSame(SessionCookie::name('a'), SessionCookie::name('b'));
        $this->assertSame(1, preg_match('/^[A-Za-z0-9_]+$/', SessionCookie::name('we;ird name=1')), 'no separator can end up in the name');
    }

    // ── attributes ────────────────────────────────────────────────────────────

    public function testDefaultsAreLaxAndNotSecureOverPlainHttp(): void
    {
        $this->assertSame(['secure' => false, 'samesite' => 'Lax'], SessionCookie::attributes([], '', ''));
    }

    public function testHttpsIsDetectedDirectlyAndThroughATrustedProxyOnly(): void
    {
        $this->assertTrue(SessionCookie::isHttps(['HTTPS' => 'on'], ''));
        $this->assertFalse(SessionCookie::isHttps(['HTTPS' => 'off'], ''));
        $this->assertTrue(SessionCookie::isHttps(['SERVER_PORT' => '443'], ''));

        $proxied = ['REMOTE_ADDR' => '10.0.0.5', 'HTTP_X_FORWARDED_PROTO' => 'https'];
        $this->assertTrue(SessionCookie::isHttps($proxied, '10.0.0.5'), 'a trusted proxy says it terminated TLS');
        $this->assertFalse(SessionCookie::isHttps($proxied, ''), 'nobody trusted: a forged header is not believed');
        $this->assertFalse(SessionCookie::isHttps($proxied, '192.168.1.1'), 'another proxy is trusted, not this one');
    }

    public function testSameSiteFromTheEnvironmentWithSafeFallbacks(): void
    {
        $https = ['HTTPS' => 'on'];
        $this->assertSame('Strict', SessionCookie::attributes($https, 'strict', '')['samesite']);
        $this->assertSame('Lax',    SessionCookie::attributes($https, 'garbage', '')['samesite']);
        $this->assertSame('None',   SessionCookie::attributes($https, 'None', '')['samesite'], 'cross-site front-end, over HTTPS');
        $this->assertTrue(SessionCookie::attributes($https, 'None', '')['secure']);
    }

    public function testSameSiteNoneIsNeverUsedOverPlainHttp(): void
    {
        $a = SessionCookie::attributes([], 'None', '');
        $this->assertSame('Lax', $a['samesite'], 'browsers drop SameSite=None without Secure: do not issue a dead cookie');
        $this->assertFalse($a['secure']);
    }

    // ── authenticate ──────────────────────────────────────────────────────────

    public function testAGetWithAValidCookieIdentifiesTheUser(): void
    {
        $cookie = [SessionCookie::name(self::APP) => $this->token()];

        $this->assertTrue(SessionCookie::authenticate(self::APP, $cookie, 'GET'));
        $this->assertSame(5, CurrentUser::id());
        $this->assertSame(39, CurrentUser::privilege());
        $this->assertSame(3, CurrentUser::get('tkv'), 'the token version travels, so revocation still works');
        $this->assertSame(self::APP, CurrentUser::get('app'));
        $this->assertTrue(CurrentUser::isAuthenticated());
    }

    public function testAHeadWorksToo(): void
    {
        $this->assertTrue(SessionCookie::authenticate(self::APP, [SessionCookie::name(self::APP) => $this->token()], 'head'));
    }

    #[\PHPUnit\Framework\Attributes\DataProvider('writes')]
    public function testNothingThatChangesDataIsEverAuthenticatedByTheCookie(string $method): void
    {
        $cookie = [SessionCookie::name(self::APP) => $this->token()];

        $this->assertFalse(SessionCookie::authenticate(self::APP, $cookie, $method), $method);
        $this->assertFalse(CurrentUser::isAuthenticated(), $method);
    }

    public static function writes(): array
    {
        return array_map(fn($m) => [$m], ['POST', 'PUT', 'PATCH', 'DELETE', 'OPTIONS']);
    }

    public function testNoCookieOrAJunkOneAuthenticatesNobody(): void
    {
        $name = SessionCookie::name(self::APP);
        foreach ([[], [$name => ''], [$name => 'junk'], [$name => 'a.b.c'], [$name => ['array']]] as $cookies) {
            $this->assertFalse(SessionCookie::authenticate(self::APP, $cookies, 'GET'), json_encode($cookies));
        }
        $this->assertFalse(CurrentUser::isAuthenticated());
    }

    public function testATokenOfAnotherApplicationIsRefused(): void
    {
        // Signed with another application's secret: it cannot verify against this one
        @mkdir(MAIN_DIR . 'projects/cookie_other', 0777, true);
        $foreign = $this->token([], 'cookie_other');
        try {
            $this->assertFalse(SessionCookie::authenticate(self::APP, [SessionCookie::name(self::APP) => $foreign], 'GET'));
        } finally {
            @unlink(MAIN_DIR . 'projects/cookie_other/.jwt_secret');
            @rmdir(MAIN_DIR . 'projects/cookie_other');
        }
    }

    public function testAnExpiredTokenIsRefused(): void
    {
        $secret  = trim((string) file_get_contents(MAIN_DIR . 'projects/' . self::APP . '/.jwt_secret'));
        $expired = \Firebase\JWT\JWT::encode(
            ['iss' => 'bradypus', 'iat' => time() - 7200, 'exp' => time() - 3600, 'sub' => 5, 'prv' => 1, 'app' => self::APP],
            $secret,
            'HS256'
        );
        $this->token(); // makes sure the secret exists

        $this->assertFalse(SessionCookie::authenticate(self::APP, [SessionCookie::name(self::APP) => $expired], 'GET'));
    }

    public function testATokenWithoutASubjectIsRefused(): void
    {
        $this->token();
        $secret = trim((string) file_get_contents(MAIN_DIR . 'projects/' . self::APP . '/.jwt_secret'));
        $anon   = \Firebase\JWT\JWT::encode(['iss' => 'bradypus', 'iat' => time(), 'exp' => time() + 600, 'app' => self::APP], $secret, 'HS256');

        $this->assertFalse(SessionCookie::authenticate(self::APP, [SessionCookie::name(self::APP) => $anon], 'GET'));
        $this->assertFalse(CurrentUser::isAuthenticated());
    }

    // ── which routes ──────────────────────────────────────────────────────────

    public function testOnlyTheFileDownloadAcceptsTheCookie(): void
    {
        // A tripwire: adding a route here must be a conscious change of this test, because a
        // route that accepts the cookie must never change anything (see Router::COOKIE_ROUTES).
        $this->assertSame(
            ['Bdus\\Controllers\\File::download', 'Bdus\\Controllers\\File::serveDerived'],
            Router::COOKIE_ROUTES
        );
        $this->assertTrue(Router::acceptsCookie('Bdus\\Controllers\\File', 'download'));
        foreach ([['File', 'updateFile'], ['File', 'getFiles'], ['Record', 'saveRecord'], ['Record', 'deleteFile'], ['Login', 'refresh']] as [$c, $m]) {
            $this->assertFalse(Router::acceptsCookie("Bdus\\Controllers\\{$c}", $m), "$c::$m");
        }
    }

    public function testEveryCookieRouteOnlyReads(): void
    {
        // A read route, or a public one that merely wants to know who is asking: never a route that changes anything
        foreach (Router::COOKIE_ROUTES as $route) {
            [$ctrl, $method] = explode('::', $route);
            $level = Router::ROUTE_PRIVILEGE[$route] ?? null;
            $this->assertTrue(
                $level === 'read' || ($level === 'none' && Router::authIsOptional($ctrl, $method)),
                "$route is '$level'"
            );
        }
    }
}
