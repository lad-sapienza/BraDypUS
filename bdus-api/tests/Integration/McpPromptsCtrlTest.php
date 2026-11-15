<?php

namespace Tests\Integration;

use Tests\Support\BdusTestCase;

/**
 * Administration of MCP prompts (Bdus\Controllers\McpPrompts): super-admin only,
 * refusals as code strings, a prompt written here is what prompts/list serves.
 */
class McpPromptsCtrlTest extends BdusTestCase
{
    private const CTRL = 'Bdus\\Controllers\\McpPrompts';

    protected function setUp(): void
    {
        parent::setUp();
        $this->setPrivilege(1);
        static::$db->query('DELETE FROM bdus_mcp_prompts', [], 'boolean');
    }

    private function call(string $method, array $get = [], array $post = []): array
    {
        return $this->callController($this->makeController(self::CTRL, $get, $post), $method);
    }

    private function report(array $over = []): array
    {
        return $over + [
            'name'      => 'season_report',
            'title'     => 'Report',
            'arguments' => [['name' => 'site', 'required' => true]],
            'template'  => 'Report on {{site}}.',
        ];
    }

    public function testOnlyASuperAdminMayUseIt(): void
    {
        foreach ([10, 25, 30, 39] as $privilege) {
            $this->setPrivilege($privilege);
            foreach ([
                ['listPrompts', [], []],
                ['createPrompt', [], $this->report()],
                ['updatePrompt', ['id' => 1], $this->report()],
                ['deletePrompt', ['id' => 1], []],
                ['reorderPrompts', [], ['ids' => [1]]],
            ] as [$method, $get, $post]) {
                $res = $this->call($method, $get, $post);
                $this->assertSame('not_enough_privilege', $res['code'] ?? null, "{$method} at privilege {$privilege}");
            }
        }
        $this->setPrivilege(1);
        $this->assertSame([], $this->call('listPrompts')['prompts'], 'nothing was written by the refused calls');
    }

    public function testRouteTableListsEveryMethodAsSuperAdmin(): void
    {
        foreach (['listPrompts', 'createPrompt', 'updatePrompt', 'deletePrompt', 'reorderPrompts'] as $m) {
            $this->assertSame('super_admin', \Bdus\Router::ROUTE_PRIVILEGE[self::CTRL . '::' . $m] ?? null, $m);
        }
    }

    public function testCreateListUpdateDelete(): void
    {
        $res = $this->call('createPrompt', [], $this->report());
        $this->assertSame('success', $res['status']);
        $this->assertSame('ok_mcp_prompt_saved', $res['code']);
        $id = $res['prompt']['id'];

        $list = $this->call('listPrompts');
        $this->assertSame(['season_report'], array_column($list['prompts'], 'name'));

        $res = $this->call('updatePrompt', ['id' => $id], $this->report(['title' => 'Changed', 'template' => 'New {{site}}']));
        $this->assertSame('Changed', $res['prompt']['title']);
        $this->assertSame('New {{site}}', $res['prompt']['template']);

        $this->assertSame('ok_mcp_prompt_deleted', $this->call('deletePrompt', ['id' => $id])['code']);
        $this->assertSame([], $this->call('listPrompts')['prompts']);
    }

    public function testRefusalsAreCodes(): void
    {
        $this->call('createPrompt', [], $this->report());

        foreach ([
            ['prompt_name_taken', $this->report()],
            ['invalid_prompt_name', $this->report(['name' => 'Bad Name'])],
            ['invalid_prompt_template', $this->report(['template' => ''])],
            ['undeclared_prompt_placeholder', $this->report(['name' => 'other', 'template' => 'Hi {{who}}'])],
            ['invalid_prompt_arguments', $this->report(['name' => 'other', 'arguments' => 'x'])],
        ] as [$code, $post]) {
            $res = $this->call('createPrompt', [], $post);
            $this->assertSame('error', $res['status'], $code);
            $this->assertSame($code, $res['code']);
        }
        $this->assertCount(1, $this->call('listPrompts')['prompts']);
    }

    public function testUnknownIdIsPromptNotFound(): void
    {
        $this->assertSame('prompt_not_found', $this->call('updatePrompt', ['id' => 999999], $this->report())['code']);
        $this->assertSame('prompt_not_found', $this->call('deletePrompt', ['id' => 999999])['code']);
    }

    public function testReorder(): void
    {
        $a = $this->call('createPrompt', [], ['name' => 'a', 'template' => 'x'])['prompt']['id'];
        $b = $this->call('createPrompt', [], ['name' => 'b', 'template' => 'x'])['prompt']['id'];

        $this->assertSame('ok_mcp_prompts_reordered', $this->call('reorderPrompts', [], ['ids' => [$b, $a]])['code']);
        $this->assertSame(['b', 'a'], array_column($this->call('listPrompts')['prompts'], 'name'));

        foreach ([null, 'nope', ['k' => 1]] as $bad) {
            $this->assertSame('parameter_missing', $this->call('reorderPrompts', [], ['ids' => $bad])['code']);
        }
    }

    public function testWhatIsSavedIsWhatAClientSees(): void
    {
        $this->call('createPrompt', [], $this->report());
        \Auth\Authorization::setAccessProvider(fn() => \Auth\Access::forCurrentUser(static::$db, static::$cfg));
        $server = new \Mcp\Server(new \Mcp\Context(
            static::$db,
            static::$cfg,
            \Auth\Access::forCurrentUser(static::$db, static::$cfg),
            ['enabled' => true, 'write' => false, 'delete' => false, 'guide' => ''],
            static::$log
        ));
        [, $body] = $server->handle(json_encode(['jsonrpc' => '2.0', 'id' => 1, 'method' => 'prompts/get',
            'params' => ['name' => 'season_report', 'arguments' => ['site' => 'Castro']]]));
        $this->assertSame('Report on Castro.', $body['result']['messages'][0]['content']['text']);
    }
}
