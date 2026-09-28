<?php

namespace Tests\Unit;

use PHPUnit\Framework\TestCase;

/**
 * openapi.yaml must list exactly the operations Router.php routes.
 *
 * The spec used to drift silently (issue #68): new routes were shipped
 * without being documented, twice within days of each reconciliation. This
 * test fails, with the offending method+path pairs, as soon as a route is
 * added to (or removed from) either side without the other.
 *
 * Both files are parsed as text — the route table lives inside
 * Router::dispatch() and the project ships no YAML parser — so the parsers
 * below rely on the layout both files already have: `addRoute()` calls with
 * literal method and path, and a `paths:` block with paths at two-space and
 * HTTP methods at four-space indentation. The sanity tests fail loudly if
 * that layout ever changes, instead of letting the comparison pass vacuously.
 *
 * Scope: which operations exist. It does not check request/response schemas.
 */
class OpenApiParityTest extends TestCase
{
    private const ROUTER = __DIR__ . '/../../lib/Bdus/Router.php';
    private const SPEC   = __DIR__ . '/../../openapi.yaml';

    private const HTTP_METHODS = ['get', 'post', 'put', 'delete', 'patch'];

    public function testEveryRouteIsDocumented(): void
    {
        $missing = array_values(array_diff($this->routerOperations(), $this->specOperations()));

        $this->assertSame(
            [],
            $missing,
            "Routed in Router.php but missing from openapi.yaml — document them:\n  " . implode("\n  ", $missing)
        );
    }

    public function testEveryDocumentedOperationIsRouted(): void
    {
        $stale = array_values(array_diff($this->specOperations(), $this->routerOperations()));

        $this->assertSame(
            [],
            $stale,
            "Documented in openapi.yaml but not routed in Router.php — remove or fix them:\n  " . implode("\n  ", $stale)
        );
    }

    /** Guards the text parsers: they must have seen every route and a plausible spec. */
    public function testParsersSeeTheWholeRouteTable(): void
    {
        $source = file_get_contents(self::ROUTER);
        $calls  = substr_count($source, '->addRoute(');

        $this->assertSame(
            $calls,
            $this->routerCallCount(),
            'Some addRoute() call in Router.php was not parsed — the route declaration format changed; update OpenApiParityTest.'
        );
        $this->assertGreaterThan(100, count($this->routerOperations()), 'Suspiciously few routes parsed from Router.php.');
        $this->assertGreaterThan(100, count($this->specOperations()), 'Suspiciously few operations parsed from openapi.yaml.');
    }

    // ── Router.php ───────────────────────────────────────────────────────────

    /** @return list<string> sorted "METHOD /path" pairs, path params normalised to {name} */
    private function routerOperations(): array
    {
        $ops = [];
        foreach ($this->routerCalls() as [$methods, $path]) {
            foreach ($methods as $method) {
                $ops[$method . ' ' . $path] = true;
            }
        }
        $ops = array_keys($ops);
        sort($ops);
        return $ops;
    }

    private function routerCallCount(): int
    {
        return count($this->routerCalls());
    }

    /** @return list<array{0: list<string>, 1: string}> [methods, normalised path] per addRoute() call */
    private function routerCalls(): array
    {
        $source = file_get_contents(self::ROUTER);
        preg_match_all(
            "/addRoute\(\s*(\[[^\]]*\]|'[A-Z]+')\s*,\s*'([^']+)'/",
            $source,
            $matches,
            PREG_SET_ORDER
        );

        $calls = [];
        foreach ($matches as $m) {
            preg_match_all('/[A-Z]+/', $m[1], $methods);
            // FastRoute `{id:\d+}` (optionally with nested braces) -> OpenAPI `{id}`
            $path = preg_replace('/\{([A-Za-z_]+):(?:[^{}]|\{[^{}]*\})+\}/', '{$1}', $m[2]);
            $calls[] = [$methods[0], $path];
        }
        return $calls;
    }

    // ── openapi.yaml ─────────────────────────────────────────────────────────

    /** @return list<string> sorted "METHOD /path" pairs */
    private function specOperations(): array
    {
        $ops     = [];
        $inPaths = false;
        $path    = null;

        foreach (file(self::SPEC, FILE_IGNORE_NEW_LINES) as $line) {
            if (preg_match('/^paths:\s*$/', $line)) {
                $inPaths = true;
                continue;
            }
            if ($inPaths && preg_match('/^[A-Za-z]/', $line)) {
                $inPaths = false; // next top-level key (components:, …)
            }
            if (!$inPaths) {
                continue;
            }
            if (preg_match('/^  (\/[^\s:]*):\s*$/', $line, $m)) {
                $path = $m[1];
                continue;
            }
            if ($path !== null && preg_match('/^    (' . implode('|', self::HTTP_METHODS) . '):\s*$/', $line, $m)) {
                $ops[strtoupper($m[1]) . ' ' . $path] = true;
            }
        }

        $ops = array_keys($ops);
        sort($ops);
        return $ops;
    }
}
