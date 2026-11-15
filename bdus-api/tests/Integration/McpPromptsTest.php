<?php

namespace Tests\Integration;

use Tests\Support\BdusTestCase;
use Mcp\Server;
use Mcp\Context;
use Mcp\Prompts;
use Mcp\PromptError;

/**
 * MCP prompts (M054): the store `Mcp\Prompts` (validation, order, rendering) and
 * `prompts/list` / `prompts/get` for both generations of clients.
 */
class McpPromptsTest extends BdusTestCase
{
    private const META = [
        'io.modelcontextprotocol/protocolVersion'    => '2026-07-28',
        'io.modelcontextprotocol/clientCapabilities' => [],
    ];

    protected function setUp(): void
    {
        parent::setUp();
        $this->setPrivilege(1);
        static::$db->query('DELETE FROM bdus_mcp_prompts', [], 'boolean');
    }

    // ── Helpers ───────────────────────────────────────────────────────────────

    private function store(): Prompts
    {
        return new Prompts(static::$db);
    }

    private function report(array $over = []): array
    {
        return $over + [
            'name'        => 'season_report',
            'title'       => 'End-of-season report',
            'description' => 'Summarises a season',
            'arguments'   => [
                ['name' => 'site', 'description' => 'Site name', 'required' => true],
                ['name' => 'year', 'description' => 'Year', 'required' => false],
            ],
            'template'    => "Write the {{year}} report for {{ site }}.\nUse aggregate first.",
        ];
    }

    private function server(): Server
    {
        \Auth\Authorization::setAccessProvider(fn() => \Auth\Access::forCurrentUser(static::$db, static::$cfg));
        return new Server(new Context(
            static::$db,
            static::$cfg,
            \Auth\Access::forCurrentUser(static::$db, static::$cfg),
            ['enabled' => true, 'write' => false, 'delete' => false, 'guide' => ''],
            static::$log
        ));
    }

    private function legacy(string $method, array $params = []): array
    {
        return $this->server()->handle(json_encode(['jsonrpc' => '2.0', 'id' => 1, 'method' => $method, 'params' => $params]));
    }

    private function modern(string $method, array $params = [], array $headers = []): array
    {
        $params['_meta'] = self::META;
        $headers += ['mcp-protocol-version' => '2026-07-28', 'mcp-method' => $method];
        if ($method === 'prompts/get') {
            $headers += ['mcp-name' => $params['name'] ?? ''];
        }
        return $this->server()->handle(
            json_encode(['jsonrpc' => '2.0', 'id' => 'a', 'method' => $method, 'params' => $params]),
            $headers
        );
    }

    private function assertRefused(string $code, callable $fn): void
    {
        try {
            $fn();
            $this->fail("expected PromptError {$code}");
        } catch (PromptError $e) {
            $this->assertSame($code, $e->errorCode);
        }
    }

    // ── Store: write ──────────────────────────────────────────────────────────

    public function testCreateAndReadBack(): void
    {
        $p = $this->store()->create($this->report());
        $this->assertSame('season_report', $p['name']);
        $this->assertSame('site', $p['arguments'][0]['name']);
        $this->assertTrue($p['arguments'][0]['required']);
        $this->assertFalse($p['arguments'][1]['required']);
        $this->assertSame($p, $this->store()->find('season_report'));
        $this->assertNull($this->store()->find('nope'));
    }

    public function testNamesAreUnique(): void
    {
        $this->store()->create($this->report());
        $this->assertRefused('prompt_name_taken', fn() => $this->store()->create($this->report()));

        $other = $this->store()->create($this->report(['name' => 'other']));
        $this->assertRefused('prompt_name_taken', fn() => $this->store()->update($other['id'], $this->report()));
        // keeping its own name is not a clash
        $this->assertSame('Changed', $this->store()->update($other['id'], $this->report(['name' => 'other', 'title' => 'Changed']))['title']);
    }

    public function testValidation(): void
    {
        $s = $this->store();
        foreach (['', 'Upper', 'has space', '-lead', str_repeat('a', 65)] as $bad) {
            $this->assertRefused('invalid_prompt_name', fn() => $s->create($this->report(['name' => $bad])));
        }
        $this->assertRefused('invalid_prompt_template', fn() => $s->create($this->report(['template' => '  ', 'arguments' => []])));
        $this->assertRefused('invalid_prompt_template', fn() => $s->create($this->report(['template' => str_repeat('x', Prompts::MAX_TEMPLATE + 1), 'arguments' => []])));
        $this->assertRefused('invalid_prompt_title', fn() => $s->create($this->report(['title' => str_repeat('t', Prompts::MAX_TITLE + 1)])));
        $this->assertRefused('invalid_prompt_description', fn() => $s->create($this->report(['description' => str_repeat('d', Prompts::MAX_DESCRIPTION + 1)])));
        $this->assertRefused('invalid_prompt_arguments', fn() => $s->create($this->report(['arguments' => [['name' => 'Bad Name']]])));
        $this->assertRefused('invalid_prompt_arguments', fn() => $s->create($this->report(['arguments' => [['name' => 'a'], ['name' => 'a']], 'template' => '{{a}}'])));
        $this->assertRefused('invalid_prompt_arguments', fn() => $s->create($this->report([
            'arguments' => array_map(fn($i) => ['name' => "a{$i}"], range(1, Prompts::MAX_ARGUMENTS + 1)), 'template' => 'x',
        ])));
        $this->assertSame([], $s->all(), 'nothing was saved');
    }

    public function testPlaceholderMustBeDeclared(): void
    {
        $this->assertRefused('undeclared_prompt_placeholder', fn() => $this->store()->create($this->report(['template' => 'Hello {{who}}'])));
        // a declared argument that the template never uses is fine
        $this->assertNotNull($this->store()->create($this->report(['template' => 'No placeholders at all'])));
    }

    public function testNumberOfPromptsIsCapped(): void
    {
        for ($i = 0; $i < Prompts::MAX_PROMPTS; $i++) {
            $this->store()->create(['name' => "p{$i}", 'template' => 'x']);
        }
        $this->assertRefused('too_many_prompts', fn() => $this->store()->create(['name' => 'one-too-many', 'template' => 'x']));
    }

    public function testDeleteAndUnknownIds(): void
    {
        $p = $this->store()->create($this->report());
        $this->assertTrue($this->store()->delete($p['id']));
        $this->assertFalse($this->store()->delete($p['id']));
        $this->assertNull($this->store()->update(999999, $this->report()));
        $this->assertSame([], $this->store()->all());
    }

    public function testOrderFollowsCreationAndCanBeChanged(): void
    {
        $a = $this->store()->create(['name' => 'a', 'template' => 'x']);
        $b = $this->store()->create(['name' => 'b', 'template' => 'x']);
        $c = $this->store()->create(['name' => 'c', 'template' => 'x']);
        $this->assertSame(['a', 'b', 'c'], array_column($this->store()->all(), 'name'));

        $this->store()->reorder([$c['id'], 999999, $a['id']]);
        $this->assertSame(['c', 'a', 'b'], array_column($this->store()->all(), 'name'), 'unknown id ignored, the rest keeps its order');
    }

    // ── Store: render ─────────────────────────────────────────────────────────

    public function testRenderFillsPlaceholders(): void
    {
        $p = $this->store()->create($this->report());
        $this->assertSame(
            "Write the 2026 report for Castro.\nUse aggregate first.",
            $this->store()->render($p, ['site' => 'Castro', 'year' => 2026])
        );
        $this->assertSame("Write the  report for Castro.\nUse aggregate first.", $this->store()->render($p, ['site' => 'Castro']), 'optional argument left out');
    }

    public function testRenderIsASinglePass(): void
    {
        $p = $this->store()->create($this->report());
        $text = $this->store()->render($p, ['site' => '{{year}}', 'year' => '{{site}}']);
        $this->assertStringContainsString('the {{site}} report for {{year}}', $text, 'values are never expanded again');
    }

    public function testRenderRefusals(): void
    {
        $p = $this->store()->create($this->report());
        $this->assertRefused('missing_prompt_argument', fn() => $this->store()->render($p, []));
        $this->assertRefused('missing_prompt_argument', fn() => $this->store()->render($p, ['site' => '  ']));
        $this->assertRefused('unknown_prompt_argument', fn() => $this->store()->render($p, ['site' => 'x', 'typo' => 'y']));
        $this->assertRefused('invalid_prompt_argument', fn() => $this->store()->render($p, ['site' => ['x']]));
        $this->assertRefused('invalid_prompt_argument', fn() => $this->store()->render($p, ['site' => str_repeat('x', Prompts::MAX_ARG_VALUE + 1)]));
    }

    // ── Protocol: legacy clients ──────────────────────────────────────────────

    public function testInitializeAdvertisesPrompts(): void
    {
        [, $body] = $this->legacy('initialize', ['protocolVersion' => '2025-06-18', 'capabilities' => []]);
        $this->assertSame(['listChanged' => false], $body['result']['capabilities']['prompts']);
        $this->assertStringContainsString('"tools":{}', json_encode($body['result']['capabilities']), 'tools stays a JSON object');
    }

    public function testLegacyListAndGet(): void
    {
        $this->store()->create($this->report());
        $this->store()->create(['name' => 'bare', 'template' => 'Just text']);

        [$status, $body] = $this->legacy('prompts/list');
        $this->assertSame(200, $status);
        $list = $body['result']['prompts'];
        $this->assertSame(['season_report', 'bare'], array_column($list, 'name'));
        $this->assertSame('End-of-season report', $list[0]['title']);
        $this->assertSame(['name' => 'site', 'description' => 'Site name', 'required' => true], $list[0]['arguments'][0]);
        $this->assertArrayNotHasKey('title', $list[1], 'empty fields are left out');
        $this->assertArrayNotHasKey('arguments', $list[1]);

        [$status, $body] = $this->legacy('prompts/get', ['name' => 'season_report', 'arguments' => ['site' => 'Castro', 'year' => '2026']]);
        $this->assertSame(200, $status);
        $msg = $body['result']['messages'][0];
        $this->assertSame('user', $msg['role']);
        $this->assertSame('text', $msg['content']['type']);
        $this->assertStringContainsString('Write the 2026 report for Castro.', $msg['content']['text']);
        $this->assertSame('Summarises a season', $body['result']['description']);
    }

    public function testLegacyErrorsAreInvalidParams(): void
    {
        $this->store()->create($this->report());
        foreach ([
            ['name' => 'nope'],
            ['name' => 'season_report'],                                         // required argument missing
            ['name' => 'season_report', 'arguments' => ['site' => 'x', 'z' => 1]], // undeclared argument
            ['name' => 'season_report', 'arguments' => ['site']],                  // a list, not an object
            [],
        ] as $params) {
            [$status, $body] = $this->legacy('prompts/get', $params);
            $this->assertSame(200, $status);
            $this->assertSame(-32602, $body['error']['code'], json_encode($params));
        }
    }

    public function testListIsEmptyBeforeTheMigration(): void
    {
        static::$db->exec('DROP TABLE bdus_mcp_prompts');
        try {
            [, $body] = $this->legacy('prompts/list');
            $this->assertSame([], $body['result']['prompts']);
        } finally {
            (new \DB\System\Manage(static::$db))->createTable('bdus_mcp_prompts');
        }
    }

    // ── Protocol: revision 2026-07-28 ─────────────────────────────────────────

    public function testModernDiscoverAdvertisesPrompts(): void
    {
        [, $body] = $this->modern('server/discover');
        $this->assertSame(['listChanged' => false], $body['result']['capabilities']['prompts']);
    }

    public function testModernListAndGet(): void
    {
        $this->store()->create($this->report());

        [$status, $body] = $this->modern('prompts/list');
        $this->assertSame(200, $status);
        $this->assertSame('complete', $body['result']['resultType']);
        $this->assertSame('season_report', $body['result']['prompts'][0]['name']);
        $this->assertSame(300000, $body['result']['ttlMs']);
        $this->assertSame('public', $body['result']['cacheScope']);
        $this->assertArrayNotHasKey('nextCursor', $body['result']);

        [$status, $body] = $this->modern('prompts/get', ['name' => 'season_report', 'arguments' => ['site' => 'Castro']]);
        $this->assertSame(200, $status);
        $this->assertSame('complete', $body['result']['resultType']);
        $this->assertStringContainsString('Castro', $body['result']['messages'][0]['content']['text']);
    }

    public function testModernGetNeedsItsNameHeader(): void
    {
        $this->store()->create($this->report());
        $params = ['name' => 'season_report', 'arguments' => ['site' => 'Castro']];

        [$status, $body] = $this->modern('prompts/get', $params, ['mcp-name' => 'something-else']);
        $this->assertSame(400, $status);
        $this->assertSame(-32020, $body['error']['code']);

        $params['_meta'] = self::META;
        [$status, $body] = $this->server()->handle(
            json_encode(['jsonrpc' => '2.0', 'id' => 1, 'method' => 'prompts/get', 'params' => $params]),
            ['mcp-protocol-version' => '2026-07-28', 'mcp-method' => 'prompts/get']
        );
        $this->assertSame(400, $status, 'no Mcp-Name header at all');
        $this->assertSame(-32020, $body['error']['code']);
    }

    public function testModernUnknownPromptIsInvalidParams(): void
    {
        [$status, $body] = $this->modern('prompts/get', ['name' => 'nope']);
        $this->assertSame(200, $status);
        $this->assertSame(-32602, $body['error']['code']);
    }

    public function testPromptsAreNotAWriteCall(): void
    {
        $this->assertFalse(Server::isWriteCall(json_encode(['jsonrpc' => '2.0', 'id' => 1, 'method' => 'prompts/get', 'params' => ['name' => 'x']])));
    }
}
