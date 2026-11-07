<?php

namespace Tests\Unit;

use PHPUnit\Framework\TestCase;

/**
 * Structural hygiene of openapi.yaml, so that `redocly lint` stays clean (issue #49).
 *
 * OpenApiParityTest guarantees which operations exist; this one guarantees that
 * each of them is well-formed for client generators and linters: a unique
 * `operationId`, at least one 2xx and one 4xx response, and no OpenAPI 3.0-only
 * syntax (`nullable`) in a 3.1 document. Like the parity test it reads the file
 * as text (no YAML parser is shipped) and relies on the layout already in use:
 * paths at two-space, HTTP methods at four-space, response codes at eight-space
 * indentation. The sanity test fails loudly if that layout changes.
 *
 * Scope: structure only. It does not check that schemas match the controllers.
 */
class OpenApiHygieneTest extends TestCase
{
    private const SPEC = __DIR__ . '/../../openapi.yaml';

    /** Operations that legitimately have no 2xx response (reason in .redocly.lint-ignore.yaml). */
    private const NO_2XX = ['getAuthOauthCallbackByProvider'];

    /** @var array<string, array{id: ?string, codes: list<string>}>|null */
    private ?array $ops = null;

    public function testEveryOperationHasAUniqueOperationId(): void
    {
        $missing = [];
        $seen    = [];
        $dups    = [];
        foreach ($this->operations() as $key => $op) {
            if ($op['id'] === null) {
                $missing[] = $key;
                continue;
            }
            if (isset($seen[$op['id']])) {
                $dups[] = "{$op['id']}: {$seen[$op['id']]} and {$key}";
            }
            $seen[$op['id']] = $key;
        }

        $this->assertSame([], $missing, "Operations without operationId:\n  " . implode("\n  ", $missing));
        $this->assertSame([], $dups, "Duplicate operationId:\n  " . implode("\n  ", $dups));
    }

    public function testOperationIdsAreCamelCaseIdentifiers(): void
    {
        $bad = [];
        foreach ($this->operations() as $key => $op) {
            if ($op['id'] !== null && !preg_match('/^(get|post|put|patch|delete)[A-Z][A-Za-z0-9]*$/', $op['id'])) {
                $bad[] = "{$key}: {$op['id']}";
            }
        }
        $this->assertSame([], $bad, "operationId must be <method><CamelCasePath>:\n  " . implode("\n  ", $bad));
    }

    public function testEveryOperationDocumentsA2xxAnd4xxResponse(): void
    {
        $no4xx = [];
        $no2xx = [];
        foreach ($this->operations() as $key => $op) {
            $has = static fn (string $digit) => count(array_filter(
                $op['codes'],
                static fn ($c) => $c[0] === $digit
            )) > 0;
            if (!$has('4')) {
                $no4xx[] = $key;
            }
            if (!$has('2') && !in_array($op['id'], self::NO_2XX, true)) {
                $no2xx[] = $key;
            }
        }
        $this->assertSame([], $no4xx, "Operations without a 4xx response:\n  " . implode("\n  ", $no4xx));
        $this->assertSame([], $no2xx, "Operations without a 2xx response:\n  " . implode("\n  ", $no2xx));
    }

    public function testNoOpenApi30NullableInA31Document(): void
    {
        $spec = file_get_contents(self::SPEC);
        $this->assertMatchesRegularExpression('/^openapi:\s*3\.1\./m', $spec, 'This test assumes OpenAPI 3.1.');
        $this->assertDoesNotMatchRegularExpression(
            '/\bnullable\s*:/',
            $spec,
            'Use `type: [string, "null"]` (OpenAPI 3.1), not `nullable: true`.'
        );
    }

    public function testServersDoNotPointAtLocalhostOrExampleCom(): void
    {
        preg_match_all('/^\s*-\s*url:\s*(\S+)/m', explode("\npaths:", file_get_contents(self::SPEC))[0], $m);
        $bad = array_values(array_filter($m[1], static fn ($u) => preg_match('/localhost|example\.com/', $u)));
        $this->assertSame([], $bad, 'Describe a local server in a description, not as a `servers` entry.');
    }

    /** Guards the text parser: it must have seen a plausible spec. */
    public function testParserSeesTheWholeSpec(): void
    {
        $ops = $this->operations();
        $this->assertGreaterThan(100, count($ops), 'Suspiciously few operations parsed from openapi.yaml.');
        $withCodes = count(array_filter($ops, static fn ($o) => $o['codes'] !== []));
        $this->assertSame(count($ops), $withCodes, 'Some operation has no parsed response code: the file layout changed.');
    }

    /** @return array<string, array{id: ?string, codes: list<string>}> keyed by "METHOD /path" */
    private function operations(): array
    {
        if ($this->ops !== null) {
            return $this->ops;
        }
        $ops     = [];
        $inPaths = false;
        $path    = null;
        $key     = null;

        foreach (file(self::SPEC, FILE_IGNORE_NEW_LINES) as $line) {
            if (preg_match('/^paths:\s*$/', $line)) {
                $inPaths = true;
                continue;
            }
            if ($inPaths && preg_match('/^[A-Za-z]/', $line)) {
                break;
            }
            if (!$inPaths) {
                continue;
            }
            if (preg_match('/^  (\/\S*):\s*$/', $line, $m)) {
                $path = $m[1];
                $key  = null;
                continue;
            }
            if ($path !== null && preg_match('/^    (get|post|put|delete|patch):\s*$/', $line, $m)) {
                $key       = strtoupper($m[1]) . ' ' . $path;
                $ops[$key] = ['id' => null, 'codes' => []];
                continue;
            }
            if ($key === null) {
                continue;
            }
            if (preg_match('/^      operationId:\s*(\S+)\s*$/', $line, $m)) {
                $ops[$key]['id'] = $m[1];
            } elseif (preg_match('/^        [\'"]?(\d(?:\d\d|XX))[\'"]?:/', $line, $m)) {
                $ops[$key]['codes'][] = $m[1];
            }
        }

        return $this->ops = $ops;
    }
}
