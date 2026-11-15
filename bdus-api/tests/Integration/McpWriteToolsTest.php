<?php

namespace Tests\Integration;

use Tests\Support\BdusTestCase;
use Mcp\Server;
use Mcp\Context;
use Mcp\Tools\RecordsValidate;

/**
 * MCP tools that change data: records_validate (dry run + token),
 * records_write (all or nothing, through Record\Save), records_delete;
 * and what they leave in the history (operation, channel).
 */
class McpWriteToolsTest extends BdusTestCase
{
    protected static function seedData(): void
    {
        parent::seedData();
        $hash = password_hash('Test_1234!', PASSWORD_DEFAULT);
        static::$db->execInTransaction(
            "INSERT INTO bdus_users (id, name, email, password, privilege) VALUES (1, 'Test Admin', 'test@example.com', '{$hash}', 1)"
        );
    }

    protected function setUp(): void
    {
        parent::setUp();
        $this->setPrivilege(1);
        \Auth\Channel::set('mcp');
    }

    protected function tearDown(): void
    {
        \Auth\Channel::set(null);
        parent::tearDown();
    }

    private function context(array $settings = []): Context
    {
        \Auth\Authorization::setAccessProvider(
            fn() => \Auth\Access::forCurrentUser(static::$db, static::$cfg)
        );
        return new Context(
            static::$db,
            static::$cfg,
            \Auth\Access::forCurrentUser(static::$db, static::$cfg),
            $settings + ['enabled' => true, 'write' => true, 'delete' => true, 'guide' => ''],
            static::$log,
            'test-signing-key'
        );
    }

    private function tool(string $name, array $args, array $settings = []): array
    {
        [$status, $body] = (new Server($this->context($settings)))->handle(json_encode([
            'jsonrpc' => '2.0', 'id' => 1, 'method' => 'tools/call',
            'params'  => ['name' => $name, 'arguments' => $args],
        ]));
        $this->assertSame(200, $status);
        $this->assertArrayHasKey('result', $body, json_encode($body));
        return $body['result']['structuredContent'] + ['__isError' => $body['result']['isError']];
    }

    /** Validates, then writes with the token: what a well-behaved client does. */
    private function validateAndWrite(array $records): array
    {
        $check = $this->tool('records_validate', ['table' => 'items', 'records' => $records]);
        $this->assertTrue($check['valid'], json_encode($check));
        return $this->tool('records_write', [
            'table' => 'items', 'records' => $records, 'validation_token' => $check['validation_token'],
        ]);
    }

    private function nameExists(string $name): bool
    {
        return (bool) static::$db->query('SELECT 1 FROM items WHERE name = ?', [$name], 'read');
    }

    private function versions(int $id): array
    {
        return static::$db->query('SELECT * FROM bdus_versions WHERE tb = ? AND rowid = ? ORDER BY id', ['items', $id], 'read') ?: [];
    }

    // ── records_validate ──────────────────────────────────────────────────────

    public function testValidateAcceptsAndWritesNothing(): void
    {
        $res = $this->tool('records_validate', ['table' => 'items', 'records' => [
            ['core' => ['name' => 'Dry run', 'status' => 'active']],
        ]]);
        $this->assertFalse($res['__isError']);
        $this->assertTrue($res['valid']);
        $this->assertSame('create', $res['results'][0]['action']);
        $this->assertSame('Dry run', $res['results'][0]['core']['name']);
        $this->assertNull($res['results'][0]['current']);
        $this->assertMatchesRegularExpression('/^\d+\.[a-f0-9]{64}$/', $res['validation_token']);
        $this->assertFalse($this->nameExists('Dry run'));
    }

    public function testValidateReportsErrorsPerRecordAndGivesNoToken(): void
    {
        $res = $this->tool('records_validate', ['table' => 'items', 'records' => [
            ['core' => ['name' => 'Fine record']],
            ['core' => ['status' => 'active']],                       // name is required
            ['core' => ['name' => 'Bad lookup', 'cat_ref' => 99999]], // no such category
            ['core' => ['name' => 'Bad field', 'nope' => 1]],
            ['id' => 99999, 'core' => ['status' => 'x']],
        ]]);
        $this->assertFalse($res['valid']);
        $this->assertNull($res['validation_token']);
        $this->assertTrue($res['results'][0]['ok']);
        $this->assertSame('required', $res['results'][1]['errors'][0]['rule']);
        $this->assertSame('fk_not_found', $res['results'][2]['errors'][0]['rule']);
        $this->assertSame('unknown_field', $res['results'][3]['errors'][0]['rule']);
        $this->assertSame('record_not_found', $res['results'][4]['code']);
    }

    public function testValidateOfAnUpdateShowsTheStoredValues(): void
    {
        $res = $this->tool('records_validate', ['table' => 'items', 'records' => [
            ['id' => 2, 'core' => ['status' => 'archived']],
        ]]);
        $this->assertTrue($res['valid']);
        $this->assertSame('update', $res['results'][0]['action']);
        $this->assertSame(['status' => 'inactive'], $res['results'][0]['current']);
    }

    public function testValidateIsAllowedWhileWritingIsOff(): void
    {
        $res = $this->tool('records_validate', ['table' => 'items', 'records' => [['core' => ['name' => 'Still a dry run']]]], ['write' => false]);
        $this->assertFalse($res['__isError']);
    }

    public function testReaderCannotValidateAWrite(): void
    {
        $this->setPrivilege(30);
        $res = $this->tool('records_validate', ['table' => 'items', 'records' => [['core' => ['name' => 'No way']]]]);
        $this->assertFalse($res['valid']);
        $this->assertSame('not_enough_privilege', $res['results'][0]['code']);
    }

    // ── records_write ─────────────────────────────────────────────────────────

    public function testWriteCreatesAndLeavesACreateEntryFromMcp(): void
    {
        $res = $this->validateAndWrite([['core' => ['name' => 'Written by MCP', 'status' => 'active']]]);
        $this->assertFalse($res['__isError'], json_encode($res));
        $this->assertSame('created', $res['results'][0]['action']);

        $id = $res['results'][0]['id'];
        $this->assertTrue($this->nameExists('Written by MCP'));

        $versions = $this->versions($id);
        $this->assertCount(1, $versions);
        $this->assertSame('create', $versions[0]['operation']);
        $this->assertSame('mcp', $versions[0]['channel']);
        $this->assertSame(1, (int) $versions[0]['userid']);
    }

    public function testWriteUpdatesAndTheHistoryShowsIt(): void
    {
        $res = $this->validateAndWrite([['id' => 4, 'core' => ['status' => 'done']]]);
        $this->assertSame('updated', $res['results'][0]['action']);
        $this->assertSame('done', static::$db->query('SELECT status FROM items WHERE id = 4')[0]['status']);

        $hist = $this->tool('history', ['table' => 'items', 'id' => 4]);
        $this->assertSame('update', $hist['versions'][0]['operation']);
        $this->assertSame('mcp', $hist['versions'][0]['channel']);
    }

    public function testWriteNeedsTheTokenOfExactlyThisPayload(): void
    {
        $records = [['core' => ['name' => 'Token test']]];

        $res = $this->tool('records_write', ['table' => 'items', 'records' => $records]);
        $this->assertSame('validation_token_invalid', $res['code']);

        $check = $this->tool('records_validate', ['table' => 'items', 'records' => $records]);

        // Another payload with the token of the first
        $res = $this->tool('records_write', [
            'table' => 'items', 'records' => [['core' => ['name' => 'Something else']]], 'validation_token' => $check['validation_token'],
        ]);
        $this->assertSame('validation_token_invalid', $res['code']);
        $this->assertFalse($this->nameExists('Something else'));

        // An expired token
        $old = RecordsValidate::token('items', RecordsValidate::records(['records' => $records]), $this->context(), time() - 1);
        $res = $this->tool('records_write', ['table' => 'items', 'records' => $records, 'validation_token' => $old]);
        $this->assertSame('validation_token_invalid', $res['code']);

        // Key order of the payload does not matter
        $res = $this->tool('records_write', [
            'validation_token' => $check['validation_token'], 'records' => $records, 'table' => 'items',
        ]);
        $this->assertFalse($res['__isError'], json_encode($res));
    }

    public function testTokenIsBoundToThePrincipal(): void
    {
        $records = [['core' => ['name' => 'Other principal']]];
        $check   = $this->tool('records_validate', ['table' => 'items', 'records' => $records]);

        \Auth\CurrentUser::set(['id' => 0, 'key_id' => 7, 'privilege' => 20, 'is_api_key' => true, 'name' => 'key']);
        $res = $this->tool('records_write', ['table' => 'items', 'records' => $records, 'validation_token' => $check['validation_token']]);
        $this->assertSame('validation_token_invalid', $res['code']);
    }

    public function testWriteIsRefusedWhileTheSwitchIsOff(): void
    {
        $res = $this->tool('records_write', ['table' => 'items', 'records' => [['core' => ['name' => 'Off']]]], ['write' => false]);
        $this->assertSame('mcp_write_disabled', $res['code']);
    }

    public function testABatchIsAllOrNothing(): void
    {
        // Each record is valid on its own; together they break `no_dupl` on name.
        $records = [
            ['core' => ['name' => 'Twin', 'status' => 'active']],
            ['core' => ['name' => 'Twin', 'status' => 'active']],
        ];
        $res = $this->validateAndWrite($records);
        $this->assertTrue($res['__isError']);
        $this->assertSame('write_refused', $res['code']);
        $this->assertSame(1, $res['detail']['index']);
        $this->assertSame('validation_failed', $res['detail']['code']);
        $this->assertFalse($this->nameExists('Twin'), 'the first record was rolled back');
        $this->assertFalse(static::$db->inTransaction());
    }

    public function testWriteByAnApiKeyIsRecordedWithItsKey(): void
    {
        \Auth\CurrentUser::set(['id' => 0, 'key_id' => 7, 'privilege' => 20, 'is_api_key' => true, 'name' => 'key']);
        $res = $this->validateAndWrite([['core' => ['name' => 'Written by a key']]]);
        $this->assertFalse($res['__isError'], json_encode($res));

        $ver = $this->versions($res['results'][0]['id'])[0];
        $this->assertSame('mcp', $ver['channel']);
        $this->assertSame(7, (int) $ver['key_id']);
    }

    // ── records_delete ────────────────────────────────────────────────────────

    public function testDeleteNeedsItsOwnSwitch(): void
    {
        $res = $this->tool('records_delete', ['table' => 'items', 'ids' => [5]], ['delete' => false]);
        $this->assertSame('mcp_delete_disabled', $res['code']);
        $this->assertNotEmpty(static::$db->query('SELECT 1 FROM items WHERE id = 5'));
    }

    public function testDeleteIsAllOrNothing(): void
    {
        $res = $this->tool('records_delete', ['table' => 'items', 'ids' => [5, 99999]]);
        $this->assertSame('delete_refused', $res['code']);
        $this->assertSame(99999, $res['detail']['id']);
        $this->assertSame('not_found', $res['detail']['code']);
        $this->assertNotEmpty(static::$db->query('SELECT 1 FROM items WHERE id = 5'), 'id 5 was rolled back');
        $this->assertFalse(static::$db->inTransaction());
    }

    public function testDeleteRemovesAndLeavesADeleteEntry(): void
    {
        $res = $this->tool('records_delete', ['table' => 'items', 'ids' => [5]]);
        $this->assertFalse($res['__isError'], json_encode($res));
        $this->assertSame([5], $res['ids']);
        $this->assertEmpty(static::$db->query('SELECT 1 FROM items WHERE id = 5'));

        $versions = $this->versions(5);
        $last     = end($versions);
        $this->assertSame('delete', $last['operation']);
        $this->assertSame('mcp', $last['channel']);
    }

    public function testReaderCannotDelete(): void
    {
        $this->setPrivilege(30);
        $res = $this->tool('records_delete', ['table' => 'items', 'ids' => [1]]);
        $this->assertSame('not_enough_privilege', $res['code']);
        $this->assertNotEmpty(static::$db->query('SELECT 1 FROM items WHERE id = 1'));
    }

    public function testDeleteRefusesBadIds(): void
    {
        foreach ([[], ['x'], [0], [-3]] as $ids) {
            $this->assertSame('invalid_parameters', $this->tool('records_delete', ['table' => 'items', 'ids' => $ids])['code']);
        }
    }

    // ── DB: nested transactions ───────────────────────────────────────────────

    public function testNestedRollbackDoomsTheOuterTransaction(): void
    {
        $db = static::$db;
        $db->beginTransaction();
        $db->query("INSERT INTO categories (name, macro) VALUES ('Doomed', 'x')", [], 'boolean');
        $db->beginTransaction();
        $db->rollBack();                       // inner
        $this->assertTrue($db->inTransaction(), 'the outer transaction stays open');

        try {
            $db->commit();
            $this->fail('commit of a doomed transaction must throw');
        } catch (\RuntimeException $e) {
        }
        $this->assertFalse($db->inTransaction());
        $this->assertEmpty($db->query("SELECT 1 FROM categories WHERE name = 'Doomed'"));
    }

    public function testNestedCommitIsDecidedByTheOuterTransaction(): void
    {
        $db = static::$db;
        $db->beginTransaction();
        $db->beginTransaction();
        $db->query("INSERT INTO categories (name, macro) VALUES ('Nested', 'x')", [], 'boolean');
        $db->commit();                         // inner: nothing final yet
        $this->assertTrue($db->inTransaction());
        $db->rollBack();                       // outer
        $this->assertEmpty($db->query("SELECT 1 FROM categories WHERE name = 'Nested'"));

        $db->beginTransaction();
        $db->beginTransaction();
        $db->query("INSERT INTO categories (name, macro) VALUES ('Nested', 'x')", [], 'boolean');
        $db->commit();
        $db->commit();
        $this->assertNotEmpty($db->query("SELECT 1 FROM categories WHERE name = 'Nested'"));
        $db->query("DELETE FROM categories WHERE name = 'Nested'", [], 'boolean');
    }
}
