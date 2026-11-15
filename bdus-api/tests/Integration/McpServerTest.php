<?php

namespace Tests\Integration;

use Tests\Support\BdusTestCase;
use Mcp\Server;
use Mcp\Context;
use Mcp\Tool;

/**
 * MCP server: the protocol layer (both generations of clients) and the
 * read tools — guide, schema, vocabulary, records_read, resolve.
 *
 * Fixture tables: items (5 rows), tags (plugin of items), reviews,
 * categories (lookup of items.cat_ref, key field `name`).
 */
class McpServerTest extends BdusTestCase
{
    private const META = [
        'io.modelcontextprotocol/protocolVersion'    => '2026-07-28',
        'io.modelcontextprotocol/clientCapabilities' => [],
    ];

    protected function setUp(): void
    {
        parent::setUp();
        $this->setPrivilege(1);
    }

    // ── Helpers ───────────────────────────────────────────────────────────────

    private function server(array $settings = [], ?array $tools = null): Server
    {
        \Auth\Authorization::setAccessProvider(
            fn() => \Auth\Access::forCurrentUser(static::$db, static::$cfg)
        );
        $settings += ['enabled' => true, 'write' => false, 'delete' => false, 'guide' => ''];
        return new Server(
            new Context(static::$db, static::$cfg, \Auth\Access::forCurrentUser(static::$db, static::$cfg), $settings, static::$log),
            $tools
        );
    }

    /** A request of a client that opened with `initialize` (no per-request metadata). */
    private function legacy(string $method, array $params = [], ?Server $server = null): array
    {
        return ($server ?? $this->server())->handle(
            json_encode(['jsonrpc' => '2.0', 'id' => 1, 'method' => $method, 'params' => $params])
        );
    }

    /** A request of revision 2026-07-28, with the headers that mirror the body. */
    private function modern(string $method, array $params = [], array $headers = []): array
    {
        $params['_meta'] = self::META;
        $headers += ['mcp-protocol-version' => '2026-07-28', 'mcp-method' => $method];
        if ($method === 'tools/call' || $method === 'prompts/get') {
            $headers += ['mcp-name' => $params['name'] ?? ''];
        }
        return $this->server()->handle(
            json_encode(['jsonrpc' => '2.0', 'id' => 'a', 'method' => $method, 'params' => $params]),
            $headers
        );
    }

    /** Structured result of a tool call. */
    private function tool(string $name, array $args = [], ?Server $server = null): array
    {
        [$status, $body] = $this->legacy('tools/call', ['name' => $name, 'arguments' => $args], $server);
        $this->assertSame(200, $status);
        $this->assertArrayHasKey('result', $body, json_encode($body));
        $result = $body['result'];
        $this->assertSame(json_encode($result['structuredContent'], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES), $result['content'][0]['text']);
        return $result['structuredContent'] + ['__isError' => $result['isError']];
    }

    // ── Protocol: envelope ────────────────────────────────────────────────────

    public function testUnparsableBodyIsAParseError(): void
    {
        [$status, $body] = $this->server()->handle('{not json');
        $this->assertSame(400, $status);
        $this->assertSame(-32700, $body['error']['code']);
    }

    public function testAnOversizedBodyIsRefusedBeforeItIsParsed(): void
    {
        $big = json_encode(['jsonrpc' => '2.0', 'id' => 1, 'method' => 'tools/call',
            'params' => ['name' => 'guide', 'arguments' => ['pad' => str_repeat('x', Server::MAX_BODY)]]]);

        [$status, $body] = $this->server()->handle($big);
        $this->assertSame(413, $status);
        $this->assertSame(-32600, $body['error']['code']);
        $this->assertFalse(Server::isWriteCall($big));

        // just under the limit is served
        $ok = json_encode(['jsonrpc' => '2.0', 'id' => 1, 'method' => 'ping', 'params' => ['pad' => str_repeat('x', Server::MAX_BODY - 200)]]);
        $this->assertSame(200, $this->server()->handle($ok)[0]);
    }

    public function testBatchIsRefused(): void
    {
        [$status, $body] = $this->server()->handle('[{"jsonrpc":"2.0","id":1,"method":"ping"}]');
        $this->assertSame(400, $status);
        $this->assertSame(-32600, $body['error']['code']);
    }

    public function testNotificationGetsNoBody(): void
    {
        [$status, $body] = $this->server()->handle('{"jsonrpc":"2.0","method":"notifications/initialized"}');
        $this->assertSame(202, $status);
        $this->assertNull($body);
    }

    // ── Protocol: handshake clients ───────────────────────────────────────────

    public function testInitializeAnswersWithTheVersionAsked(): void
    {
        [$status, $body] = $this->legacy('initialize', ['protocolVersion' => '2025-06-18', 'capabilities' => []]);
        $this->assertSame(200, $status);
        $this->assertSame('2025-06-18', $body['result']['protocolVersion']);
        $this->assertSame('bradypus', $body['result']['serverInfo']['name']);
        $this->assertArrayHasKey('tools', $body['result']['capabilities']);
    }

    public function testInitializeWithUnknownVersionOffersTheLatestHandshakeRevision(): void
    {
        [, $body] = $this->legacy('initialize', ['protocolVersion' => '1999-01-01']);
        $this->assertSame(Server::LEGACY[0], $body['result']['protocolVersion']);
    }

    public function testLegacyUnknownMethod(): void
    {
        [$status, $body] = $this->legacy('resources/list');
        $this->assertSame(200, $status);
        $this->assertSame(-32601, $body['error']['code']);
    }

    public function testToolsListNamesTheReadTools(): void
    {
        [, $body] = $this->legacy('tools/list');
        $tools = $body['result']['tools'];
        $this->assertSame(['guide', 'schema', 'vocabulary', 'records_read', 'aggregate', 'resolve', 'history', 'rs_read', 'records_validate', 'records_write', 'rs_validate', 'rs_write', 'records_delete'], array_column($tools, 'name'));
        foreach ($tools as $tool) {
            $this->assertSame('object', $tool['inputSchema']['type'], $tool['name']);
            $this->assertSame(
                !in_array($tool['name'], ['records_write', 'rs_write', 'records_delete'], true),
                $tool['annotations']['readOnlyHint'],
                $tool['name']
            );
            $this->assertNotEmpty($tool['description'], $tool['name']);
        }
    }

    public function testUnknownToolIsAProtocolError(): void
    {
        [, $body] = $this->legacy('tools/call', ['name' => 'drop_everything', 'arguments' => []]);
        $this->assertSame(-32602, $body['error']['code']);
    }

    // ── Protocol: revision 2026-07-28 ─────────────────────────────────────────

    public function testDiscover(): void
    {
        [$status, $body] = $this->modern('server/discover');
        $this->assertSame(200, $status);
        $r = $body['result'];
        $this->assertSame('complete', $r['resultType']);
        $this->assertSame(['2026-07-28'], $r['supportedVersions']);
        $this->assertSame('bradypus', $r['_meta']['io.modelcontextprotocol/serverInfo']['name']);
        $this->assertArrayHasKey('ttlMs', $r);
    }

    public function testModernToolsListAndCall(): void
    {
        [, $list] = $this->modern('tools/list');
        $this->assertCount(13, $list['result']['tools']);
        $this->assertSame('complete', $list['result']['resultType']);

        [$status, $call] = $this->modern('tools/call', ['name' => 'schema', 'arguments' => []]);
        $this->assertSame(200, $status);
        $this->assertFalse($call['result']['isError']);
        $this->assertSame('complete', $call['result']['resultType']);
    }

    public function testModernHeaderMustMatchTheBody(): void
    {
        [$status, $body] = $this->modern('tools/list', [], ['mcp-method' => 'tools/call']);
        $this->assertSame(400, $status);
        $this->assertSame(-32020, $body['error']['code']);

        [$status, $body] = $this->modern('tools/call', ['name' => 'schema'], ['mcp-name' => 'guide']);
        $this->assertSame(400, $status);
        $this->assertSame(-32020, $body['error']['code']);
    }

    public function testModernMissingHeaderIsRefused(): void
    {
        [$status, $body] = $this->server()->handle(json_encode([
            'jsonrpc' => '2.0', 'id' => 1, 'method' => 'tools/list', 'params' => ['_meta' => self::META],
        ]));
        $this->assertSame(400, $status);
        $this->assertSame(-32020, $body['error']['code']);
    }

    public function testModernUnsupportedVersion(): void
    {
        [$status, $body] = $this->server()->handle(json_encode([
            'jsonrpc' => '2.0', 'id' => 1, 'method' => 'tools/list',
            'params'  => ['_meta' => ['io.modelcontextprotocol/protocolVersion' => '2030-01-01']],
        ]));
        $this->assertSame(400, $status);
        $this->assertSame(-32022, $body['error']['code']);
        $this->assertSame(['2026-07-28'], $body['error']['data']['supported']);
    }

    public function testModernNeedsClientCapabilities(): void
    {
        [$status, $body] = $this->server()->handle(json_encode([
            'jsonrpc' => '2.0', 'id' => 1, 'method' => 'tools/list',
            'params'  => ['_meta' => ['io.modelcontextprotocol/protocolVersion' => '2026-07-28']],
        ]));
        $this->assertSame(400, $status);
        $this->assertSame(-32602, $body['error']['code']);
    }

    public function testModernUnknownMethodIs404(): void
    {
        [$status, $body] = $this->modern('ping');
        $this->assertSame(404, $status);
        $this->assertSame(-32601, $body['error']['code']);
    }

    // ── Write switch and rate-limit classification ────────────────────────────

    private function writeTool(): Tool
    {
        return new class implements Tool {
            public function name(): string { return 'fake_write'; }
            public function definition(): array { return ['title' => 'x', 'description' => 'x', 'inputSchema' => ['type' => 'object'], 'annotations' => []]; }
            public function needs(): ?string { return 'write'; }
            public function call(array $args, Context $ctx): array { return ['done' => true]; }
        };
    }

    public function testWriteToolIsRefusedWhileTheSwitchIsOff(): void
    {
        $res = $this->tool('fake_write', [], $this->server([], [$this->writeTool()]));
        $this->assertTrue($res['__isError']);
        $this->assertSame('mcp_write_disabled', $res['code']);

        $res = $this->tool('fake_write', [], $this->server(['write' => true], [$this->writeTool()]));
        $this->assertFalse($res['__isError']);
        $this->assertTrue($res['done']);
    }

    public function testReadToolsAreNotWriteCalls(): void
    {
        $this->assertTrue(Server::isWriteCall('{"jsonrpc":"2.0","id":1,"method":"tools/call","params":{"name":"records_write"}}'));
        $this->assertTrue(Server::isWriteCall('{"jsonrpc":"2.0","id":1,"method":"tools/call","params":{"name":"records_delete"}}'));
        $this->assertTrue(Server::isWriteCall('{"jsonrpc":"2.0","id":1,"method":"tools/call","params":{"name":"rs_write"}}'));
        $this->assertFalse(Server::isWriteCall('{"jsonrpc":"2.0","id":1,"method":"tools/call","params":{"name":"rs_validate"}}'));
        $this->assertFalse(Server::isWriteCall('{"jsonrpc":"2.0","id":1,"method":"tools/call","params":{"name":"rs_read"}}'));
        $this->assertFalse(Server::isWriteCall('{"jsonrpc":"2.0","id":1,"method":"tools/call","params":{"name":"records_validate"}}'));
        $this->assertFalse(Server::isWriteCall('{"jsonrpc":"2.0","id":1,"method":"tools/call","params":{"name":"records_read"}}'));
        $this->assertFalse(Server::isWriteCall('{"jsonrpc":"2.0","id":1,"method":"tools/list"}'));
        $this->assertFalse(Server::isWriteCall('garbage'));
    }

    public function testRouteIsOpenToReadersAndToGroupKeys(): void
    {
        $ctrl = 'Bdus\\Controllers\\Mcp';
        $this->assertSame('read', \Bdus\Router::requiredPrivilege($ctrl, 'handle'));
        $this->assertTrue(\Bdus\Router::apiKeyMayReach($ctrl, 'handle', 'read', 30, null));
        $this->assertTrue(\Bdus\Router::apiKeyMayReach($ctrl, 'handle', 'read', 39, 5), 'a level-39 key scoped by a group');
        $this->assertFalse(\Bdus\Router::apiKeyMayReach($ctrl, 'handle', 'read', 39, null));
    }

    // ── guide ─────────────────────────────────────────────────────────────────

    public function testGuideCarriesTheAdministratorNotesAndTheSwitches(): void
    {
        $res = $this->tool('guide', [], $this->server(['guide' => 'Sites are in Albania.']));
        $this->assertSame('Sites are in Albania.', $res['administrator_notes']);
        $this->assertFalse($res['may']['write']);
        $this->assertStringContainsString('_icontains', $res['conventions']);
    }

    // ── schema ────────────────────────────────────────────────────────────────

    public function testSchemaListsReadableNonPluginTables(): void
    {
        $names = array_column($this->tool('schema')['tables'], 'table');
        $this->assertContains('items', $names);
        $this->assertContains('categories', $names);
        $this->assertNotContains('tags', $names, 'plugin tables are described inside their table');
        foreach ($names as $name) {
            $this->assertStringStartsNotWith('bdus_', $name);
        }
    }

    public function testSchemaOfATable(): void
    {
        $res = $this->tool('schema', ['table' => 'items']);
        $this->assertSame('items', $res['table']);
        $this->assertSame('id', $res['key_field']);
        $fields = array_column($res['fields'], null, 'name');
        $this->assertTrue($fields['name']['required']);
        $this->assertContains('no_dupl', $fields['name']['check']);
        $this->assertSame(['type' => 'static', 'items' => ['en', 'fr', 'de']], $fields['lang_code']['options_source']);
        $this->assertArrayNotHasKey('widget', $fields['name']);
        $this->assertContains('stratigraphic_relations', $res['features']);
        $this->assertTrue($res['can']['read']);
    }

    public function testSchemaHidesWhatThePrincipalCannotRead(): void
    {
        $this->setPrivilege(40);
        $this->assertSame([], $this->tool('schema')['tables']);

        $res = $this->tool('schema', ['table' => 'items']);
        $this->assertTrue($res['__isError']);
        $this->assertSame('unknown_table', $res['code'], 'a table out of reach looks like a missing one');
    }

    public function testSchemaOfAnUnknownOrSystemTable(): void
    {
        foreach (['nope', 'bdus_users', 'items; DROP TABLE items'] as $tb) {
            $res = $this->tool('schema', ['table' => $tb]);
            $this->assertSame('unknown_table', $res['code'], $tb);
        }
    }

    // ── records_read ──────────────────────────────────────────────────────────

    public function testRecordsReadPagesAndCounts(): void
    {
        $res = $this->tool('records_read', ['table' => 'items', 'limit' => 2]);
        $this->assertFalse($res['__isError']);
        $this->assertSame(5, $res['total']);
        $this->assertSame(2, $res['returned']);
        $this->assertTrue($res['truncated']);

        $res = $this->tool('records_read', ['table' => 'items', 'limit' => 2, 'offset' => 4]);
        $this->assertSame(1, $res['returned']);
        $this->assertFalse($res['truncated']);
    }

    public function testRecordsReadFilterSearchFieldsAndSort(): void
    {
        $res = $this->tool('records_read', [
            'table'  => 'items',
            'filter' => ['status' => ['_eq' => 'active']],
            'fields' => ['name'],
            'sort'   => ['-name'],
        ]);
        $this->assertSame(3, $res['total']);
        $this->assertSame(['Gamma item', 'Epsilon thing', 'Alpha item'], array_column($res['rows'], 'name'));
        $this->assertArrayNotHasKey('status', $res['rows'][0]);

        $res = $this->tool('records_read', ['table' => 'items', 'search' => 'thing']);
        $this->assertSame(2, $res['total']);
    }

    public function testRecordsReadSameTotalAsTheRestList(): void
    {
        $ctrl = $this->makeController('Bdus\\Controllers\\Record', ['tb' => 'items', 'filter' => ['status' => ['_eq' => 'active']]]);
        $rest = $this->callController($ctrl, 'getRecords');

        $mcp = $this->tool('records_read', ['table' => 'items', 'filter' => ['status' => ['_eq' => 'active']]]);
        $this->assertSame($rest['total'], $mcp['total']);
        $this->assertSame(array_column($rest['data'], 'id'), array_column($mcp['rows'], 'id'));
    }

    public function testRecordsReadRefusals(): void
    {
        $cases = [
            'unknown_table'      => ['table' => 'nope'],
            'parameter_missing'  => [],
            'unknown_field'      => ['table' => 'items', 'sort' => ['nope']],
            'invalid_parameters' => ['table' => 'items', 'limit' => 'many'],
        ];
        foreach ($cases as $code => $args) {
            $res = $this->tool('records_read', $args);
            $this->assertTrue($res['__isError'], $code);
            $this->assertSame($code, $res['code']);
        }

        // A plugin table is read through its record, never on its own.
        $this->assertSame('unknown_table', $this->tool('records_read', ['table' => 'tags'])['code']);

        $this->setPrivilege(40);
        $this->assertSame('unknown_table', $this->tool('records_read', ['table' => 'items'])['code']);
    }

    public function testRecordsReadRefusesABadFilter(): void
    {
        foreach ([
            ['nope' => ['_eq' => 1]],                 // a field the table does not have
            ['status' => ['_bogus' => 'x']],          // an operator that does not exist
        ] as $filter) {
            $res = $this->tool('records_read', ['table' => 'items', 'filter' => $filter]);
            $this->assertTrue($res['__isError'], json_encode($filter));
            $this->assertSame('invalid_filter', $res['code'], json_encode($res));
        }
        $this->assertSame('invalid_parameters', $this->tool('records_read', ['table' => 'items', 'filter' => 'status'])['code']);
    }

    public function testRecordsReadRefusesToNestTooManyRows(): void
    {
        // More plugin rows than one response may carry: refused, never truncated silently.
        $max = \Record\Expand::MAX_NESTED_ROWS;
        static::$db->exec(
            'INSERT INTO tags (label, id_link) WITH RECURSIVE c(x) AS (SELECT 1 UNION ALL SELECT x + 1 FROM c WHERE x < ' . ($max + 1) . ") SELECT 'bulk', 1 FROM c"
        );
        try {
            $res = $this->tool('records_read', ['table' => 'items', 'filter' => ['id' => ['_eq' => 1]], 'fields' => ['name', 'tags.*']]);
            $this->assertTrue($res['__isError']);
            $this->assertSame('too_many_nested_rows', $res['code'], json_encode($res));
        } finally {
            static::$db->exec("DELETE FROM tags WHERE label = 'bulk'");
        }
    }

    public function testRecordsReadLimitIsCapped(): void
    {
        $res = $this->tool('records_read', ['table' => 'items', 'limit' => 100000]);
        $this->assertFalse($res['__isError']);
        $this->assertSame(5, $res['returned']);
    }

    // ── aggregate ─────────────────────────────────────────────────────────────

    public function testAggregateCountsByDefault(): void
    {
        $res = $this->tool('aggregate', ['table' => 'items']);
        $this->assertFalse($res['__isError'], json_encode($res));
        $this->assertSame([['count' => 5]], $res['groups']);
    }

    public function testAggregateGroupsAndFilters(): void
    {
        $res = $this->tool('aggregate', ['table' => 'items', 'group_by' => ['status']]);
        $this->assertSame(
            ['active' => 3, 'inactive' => 1, 'pending' => 1],
            array_column($res['groups'], 'count', 'status')
        );

        $res = $this->tool('aggregate', [
            'table'   => 'items',
            'filter'  => ['status' => ['_eq' => 'active']],
            'metrics' => [['fn' => 'count'], ['fn' => 'min', 'field' => 'name'], ['fn' => 'count_distinct', 'field' => 'status']],
        ]);
        $this->assertSame(3, $res['groups'][0]['count']);
        $this->assertSame('Alpha item', $res['groups'][0]['min_name']);
        $this->assertSame(1, $res['groups'][0]['count_distinct_status']);
    }

    public function testAggregateLabelsALookupGroup(): void
    {
        $res    = $this->tool('aggregate', ['table' => 'items', 'group_by' => ['cat_ref']]);
        $labels = array_column($res['groups'], '@cat_ref', 'cat_ref');
        $this->assertSame('Ceramics', $labels[1]);
        $this->assertSame('Metal', $labels[2]);
        $noCategory = array_values(array_filter($res['groups'], fn($g) => $g['cat_ref'] === null));
        $this->assertSame(3, $noCategory[0]['count']);
        $this->assertNull($noCategory[0]['@cat_ref']);
    }

    public function testAggregateSameCountAsRecordsRead(): void
    {
        $filter = ['name' => ['_icontains' => 'item']];
        $this->assertSame(
            $this->tool('records_read', ['table' => 'items', 'filter' => $filter])['total'],
            $this->tool('aggregate', ['table' => 'items', 'filter' => $filter])['groups'][0]['count']
        );
    }

    public function testAggregateIsCappedAndSaysSo(): void
    {
        $res = $this->tool('aggregate', ['table' => 'items', 'group_by' => ['name'], 'limit' => 2]);
        $this->assertCount(2, $res['groups']);
        $this->assertTrue($res['truncated']);
    }

    public function testAggregateRefusals(): void
    {
        $cases = [
            [['table' => 'items', 'group_by' => ['nope']], 'invalid_parameters'],
            [['table' => 'items', 'group_by' => ['id); DROP TABLE items; --']], 'invalid_parameters'],
            [['table' => 'items', 'group_by' => ['name', 'status', 'id']], 'invalid_parameters'],
            [['table' => 'items', 'metrics' => [['fn' => 'median', 'field' => 'score']]], 'invalid_parameters'],
            [['table' => 'items', 'metrics' => [['fn' => 'sum']]], 'invalid_parameters'],
            [['table' => 'items', 'metrics' => [['fn' => 'sum', 'field' => 'nope']]], 'invalid_parameters'],
            [['table' => 'tags'], 'unknown_table'],
        ];
        foreach ($cases as [$args, $code]) {
            $res = $this->tool('aggregate', $args);
            $this->assertTrue($res['__isError'], json_encode($args));
            $this->assertSame($code, $res['code'], json_encode($args));
        }
    }

    // ── history ───────────────────────────────────────────────────────────────

    public function testHistoryListsVersionsAndShowsWhatChanged(): void
    {
        $this->assertSame([], $this->tool('history', ['table' => 'items', 'id' => 3])['versions']);

        $ctrl = $this->makeController('Bdus\\Controllers\\Record', [], [
            'tb' => 'items', 'id' => 3, 'core' => ['status' => 'archived'],
        ]);
        $this->assertSame('success', $this->callController($ctrl, 'saveRecord')['status']);

        $list = $this->tool('history', ['table' => 'items', 'id' => 3]);
        $this->assertCount(1, $list['versions']);
        $this->assertSame('update', $list['versions'][0]['operation']);

        $diff = $this->tool('history', ['table' => 'items', 'id' => 3, 'version' => $list['versions'][0]['version']]);
        $this->assertFalse($diff['deleted']);
        $this->assertSame(['status' => ['then' => 'active', 'now' => 'archived']], $diff['changed']);
        $this->assertSame('Gamma item', $diff['then']['name']);
    }

    public function testHistoryRefusals(): void
    {
        $this->assertSame('parameter_missing', $this->tool('history', ['table' => 'items'])['code']);
        $this->assertSame('version_not_found', $this->tool('history', ['table' => 'items', 'id' => 1, 'version' => 999999])['code']);
        $this->assertSame('unknown_table', $this->tool('history', ['table' => 'bdus_versions', 'id' => 1])['code']);

        $this->setPrivilege(40);
        $this->assertSame('unknown_table', $this->tool('history', ['table' => 'items', 'id' => 1])['code']);
    }

    // ── resolve ───────────────────────────────────────────────────────────────

    public function testResolveByKeyField(): void
    {
        $res = $this->tool('resolve', ['table' => 'categories', 'values' => ['Ceramics', 'Nothing']]);
        $this->assertSame('name', $res['key_field']);
        $this->assertSame(1, $res['results'][0]['count']);
        $this->assertSame(1, (int) $res['results'][0]['matches'][0]['id']);
        $this->assertSame(0, $res['results'][1]['count']);
        $this->assertSame([], $res['results'][1]['matches']);
    }

    public function testResolveNeedsValues(): void
    {
        $this->assertSame('invalid_parameters', $this->tool('resolve', ['table' => 'categories', 'values' => []])['code']);
        $this->assertSame('invalid_parameters', $this->tool('resolve', ['table' => 'categories', 'values' => [['x']]])['code']);
    }

    // ── vocabulary ────────────────────────────────────────────────────────────

    public function testVocabularyOfAStaticList(): void
    {
        $res = $this->tool('vocabulary', ['table' => 'items', 'field' => 'lang_code']);
        $this->assertSame(['en', 'fr', 'de'], array_column($res['values'], 'value'));
        $this->assertFalse($res['truncated']);

        $res = $this->tool('vocabulary', ['table' => 'items', 'field' => 'lang_code', 'search' => 'F', 'limit' => 1]);
        $this->assertSame(['fr'], array_column($res['values'], 'value'));
    }

    public function testVocabularyOfALookupInAPluginTable(): void
    {
        $res = $this->tool('vocabulary', ['table' => 'tags', 'field' => 'cat_ref']);
        $this->assertSame(['Ceramics', 'Charcoal', 'Metal'], array_column($res['values'], 'label'));
        $this->assertSame(3, $res['total']);
    }

    public function testVocabularyUnknownField(): void
    {
        $this->assertSame('unknown_field', $this->tool('vocabulary', ['table' => 'items', 'field' => 'nope'])['code']);
    }

    // ── Settings (M052) ───────────────────────────────────────────────────────

    public function testMcpSettingsAreOffByDefaultAndSaved(): void
    {
        \DB\System\Migrations\M052_AddMcpSettingsToCfgApp::run(new \DB\System\Manage(static::$db));
        if (!\Config\AppSettings::isAvailable(static::$db)) {
            static::$db->query("INSERT INTO bdus_cfg_app (id, status, max_image_size) VALUES (1, 'on', 0)", [], 'boolean');
        }

        $this->assertSame(
            ['enabled' => false, 'write' => false, 'delete' => false, 'guide' => ''],
            \Config\AppSettings::getMcp(static::$db)
        );

        $this->assertTrue(\Config\AppSettings::saveMcp(static::$db, ['enabled' => true, 'guide' => ' Notes ']));
        $this->assertSame(
            ['enabled' => true, 'write' => false, 'delete' => false, 'guide' => 'Notes'],
            \Config\AppSettings::getMcp(static::$db)
        );
    }
}
