<?php

namespace Tests\Integration;

use Tests\Support\BdusTestCase;
use Mcp\Server;
use Mcp\Context;
use PHPUnit\Framework\Attributes\DataProvider;

/**
 * The manual links of a record (bdus_userlinks) through MCP: read with
 * records_read links:true, written in the `links` section of records_validate /
 * records_write. Fixture: seeded link item 1 ↔ item 2; items 1-5, categories 1-3.
 */
class McpLinksTest extends BdusTestCase
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
        static::$db->execInTransaction('DELETE FROM bdus_userlinks');
        static::$db->execInTransaction("INSERT INTO bdus_userlinks (id, tb_one, id_one, tb_two, id_two, sort) VALUES (1, 'items', 1, 'items', 2, 1)");
    }

    protected function tearDown(): void
    {
        \Auth\Channel::set(null);
        static::$db->execInTransaction('UPDATE items SET creator = 1');
        static::$db->execInTransaction('DELETE FROM bdus_userlinks');
        static::$db->execInTransaction("INSERT INTO bdus_userlinks (id, tb_one, id_one, tb_two, id_two, sort) VALUES (1, 'items', 1, 'items', 2, 1)");
        parent::tearDown();
    }

    private function tool(string $name, array $args, array $settings = []): array
    {
        \Auth\Authorization::setAccessProvider(fn() => \Auth\Access::forCurrentUser(static::$db, static::$cfg));
        $ctx = new Context(
            static::$db, static::$cfg, \Auth\Access::forCurrentUser(static::$db, static::$cfg),
            $settings + ['enabled' => true, 'write' => true, 'delete' => true, 'guide' => ''], static::$log, 'test-signing-key'
        );
        [$status, $body] = (new Server($ctx))->handle(json_encode([
            'jsonrpc' => '2.0', 'id' => 1, 'method' => 'tools/call', 'params' => ['name' => $name, 'arguments' => $args],
        ]));
        $this->assertSame(200, $status);
        return $body['result']['structuredContent'] + ['__isError' => $body['result']['isError']];
    }

    private function validateAndWrite(string $tb, array $records): array
    {
        $check = $this->tool('records_validate', ['table' => $tb, 'records' => $records]);
        $this->assertTrue($check['valid'], json_encode($check));
        return $this->tool('records_write', ['table' => $tb, 'records' => $records, 'validation_token' => $check['validation_token']]);
    }

    /** @return array[] every stored link, as [tb_one, id_one, tb_two, id_two] */
    private function stored(): array
    {
        return array_map(
            fn($r) => [$r['tb_one'], (int) $r['id_one'], $r['tb_two'], (int) $r['id_two']],
            static::$db->query('SELECT * FROM bdus_userlinks ORDER BY id', [], 'read') ?: []
        );
    }

    private function item(int $id, array $links): array
    {
        return ['id' => $id, 'core' => [], 'links' => $links];
    }

    // ── read ──────────────────────────────────────────────────────────────────

    public function testRecordsReadReturnsLinksOnRequestOnlyAndFromBothSides(): void
    {
        $plain = $this->tool('records_read', ['table' => 'items', 'filter' => ['id' => ['_eq' => 1]]]);
        $this->assertArrayNotHasKey('links', $plain['rows'][0]);

        $res  = $this->tool('records_read', ['table' => 'items', 'links' => true, 'limit' => 5]);
        $byId = array_column($res['rows'], null, 'id');
        $this->assertCount(1, $byId[1]['links']);
        $this->assertSame(1, $byId[1]['links'][0]['id']);
        $this->assertSame('items', $byId[1]['links'][0]['table']);
        $this->assertSame(2, $byId[1]['links'][0]['record_id']);
        // the same link seen from the other record
        $this->assertSame(1, $byId[2]['links'][0]['record_id']);
        $this->assertSame([], $byId[3]['links']);
    }

    public function testRecordsReadLinksMustBeABoolean(): void
    {
        $res = $this->tool('records_read', ['table' => 'items', 'links' => 'yes']);
        $this->assertSame('invalid_parameters', $res['code']);
    }

    public function testFilesAreNotLinks(): void
    {
        static::$db->execInTransaction("INSERT INTO bdus_userlinks (tb_one, id_one, tb_two, id_two) VALUES ('items', 3, 'bdus_files', 9)");
        $res = $this->tool('records_read', ['table' => 'items', 'links' => true, 'filter' => ['id' => ['_eq' => 3]]]);
        $this->assertSame([], $res['rows'][0]['links']);
    }

    // ── write ─────────────────────────────────────────────────────────────────

    public function testWriteAddsALinkWithTheRecordAndItShowsOnBothSides(): void
    {
        $res = $this->validateAndWrite('items', [[
            'id' => 1, 'core' => ['status' => 'archived'],
            'links' => ['add' => [['table' => 'items', 'id' => 3, 'label' => 'same hoard', 'sort' => 5]]],
        ]]);
        $this->assertFalse($res['__isError'], json_encode($res));
        $this->assertContains(['items', 1, 'items', 3], $this->stored());
        $this->assertSame('archived', static::$db->query('SELECT status FROM items WHERE id = 1', [], 'read')[0]['status']);

        $read = $this->tool('records_read', ['table' => 'items', 'links' => true, 'filter' => ['id' => ['_eq' => 3]]]);
        $this->assertSame(1, $read['rows'][0]['links'][0]['record_id']);
        $this->assertSame('same hoard', $read['rows'][0]['links'][0]['label']);
        $this->assertSame(5, $read['rows'][0]['links'][0]['sort']);
    }

    public function testLinkToARecordOfAnotherTable(): void
    {
        $res = $this->validateAndWrite('items', [$this->item(1, ['add' => [['table' => 'categories', 'id' => 2]]])]);
        $this->assertFalse($res['__isError'], json_encode($res));
        $this->assertContains(['items', 1, 'categories', 2], $this->stored());
    }

    public function testRemoveALinkAndAddItAgainInOneCall(): void
    {
        $res = $this->validateAndWrite('items', [$this->item(2, ['remove' => [1], 'add' => [['table' => 'items', 'id' => 1]]])]);
        $this->assertFalse($res['__isError'], json_encode($res));
        $this->assertSame([['items', 2, 'items', 1]], $this->stored());

        $res = $this->validateAndWrite('items', [$this->item(1, ['remove' => [(int) static::$db->query('SELECT id FROM bdus_userlinks', [], 'read')[0]['id']]])]);
        $this->assertFalse($res['__isError'], json_encode($res));
        $this->assertSame([], $this->stored());
    }

    public function testNewRecordWithLinks(): void
    {
        $res = $this->validateAndWrite('items', [['core' => ['name' => 'Linked'], 'links' => ['add' => [['table' => 'items', 'id' => 4]]]]]);
        $this->assertFalse($res['__isError'], json_encode($res));
        $this->assertContains(['items', $res['results'][0]['id'], 'items', 4], $this->stored());
    }

    public function testValidateShowsTheSectionAndWritesNothing(): void
    {
        $res = $this->tool('records_validate', ['table' => 'items', 'records' => [$this->item(1, ['add' => [['table' => 'items', 'id' => 3]]])]]);
        $this->assertTrue($res['valid']);
        $this->assertSame(3, $res['results'][0]['links']['add'][0]['id']);
        $this->assertCount(1, $this->stored());
    }

    public function testTokenCoversTheLinks(): void
    {
        $recs  = [$this->item(1, ['add' => [['table' => 'items', 'id' => 3]]])];
        $check = $this->tool('records_validate', ['table' => 'items', 'records' => $recs]);
        $recs[0]['links']['add'][0]['id'] = 4;
        $res = $this->tool('records_write', ['table' => 'items', 'records' => $recs, 'validation_token' => $check['validation_token']]);
        $this->assertSame('validation_token_invalid', $res['code']);
        $this->assertCount(1, $this->stored());
    }

    #[DataProvider('refusals')]
    public function testRefusalsCarryACode(array $links, string $code): void
    {
        $res = $this->tool('records_validate', ['table' => 'items', 'records' => [$this->item(1, $links)]]);
        if ($res['__isError']) {
            $this->assertSame($code, $res['code']);
        } else {
            $this->assertFalse($res['valid']);
            $this->assertSame($code, $res['results'][0]['code']);
        }
    }

    public static function refusals(): array
    {
        return [
            'already linked'            => [['add' => [['table' => 'items', 'id' => 2]]], 'link_already_exists'],
            'same target twice'         => [['add' => [['table' => 'items', 'id' => 3], ['table' => 'items', 'id' => 3]]], 'link_already_exists'],
            'with itself'               => [['add' => [['table' => 'items', 'id' => 1]]], 'link_with_itself'],
            'unknown table'             => [['add' => [['table' => 'nope', 'id' => 1]]], 'unknown_table'],
            'system table'              => [['add' => [['table' => 'bdus_files', 'id' => 1]]], 'unknown_table'],
            'unknown record'            => [['add' => [['table' => 'items', 'id' => 987654]]], 'record_not_found'],
            'unknown link'              => [['remove' => [424242]], 'link_not_found'],
            'missing table'             => [['add' => [['id' => 3]]], 'parameter_missing'],
            'missing id'                => [['add' => [['table' => 'items']]], 'parameter_missing'],
            'bad id'                    => [['add' => [['table' => 'items', 'id' => 'x']]], 'invalid_parameters'],
            'unknown prop'              => [['add' => [['table' => 'items', 'id' => 3, 'tb_one' => 'forged']]], 'invalid_parameters'],
            'unknown part'              => [['edit' => []], 'invalid_parameters'],
            'bad remove'                => [['remove' => ['a']], 'invalid_parameters'],
        ];
    }

    public function testRestEndpointRefusesALinkWithItself(): void
    {
        $links = new \Record\ManualLinks(static::$db, static::$cfg, \Auth\Access::forCurrentUser(static::$db, static::$cfg));
        $this->assertSame(['status' => 'error', 'code' => 'link_with_itself'], $links->add('items', 1, 'items', 1));
        $this->assertCount(1, $this->stored());
        // same id in another table is a different record
        $this->assertSame('success', $links->add('items', 1, 'categories', 1)['status']);
    }

    public function testLinkOfAnotherRecordCannotBeRemoved(): void
    {
        $res = $this->tool('records_validate', ['table' => 'items', 'records' => [$this->item(3, ['remove' => [1]])]]);
        $this->assertFalse($res['valid']);
        $this->assertSame('link_not_found', $res['results'][0]['code']);
    }

    public function testNothingIsWrittenWhenALaterRecordFails(): void
    {
        static::$db->execInTransaction("INSERT INTO bdus_userlinks (id, tb_one, id_one, tb_two, id_two) VALUES (2, 'items', 4, 'items', 5)");
        $recs = [
            ['id' => 1, 'core' => ['name' => 'Rollback probe'], 'links' => ['add' => [['table' => 'items', 'id' => 3]]]],
            $this->item(4, ['remove' => [2]]),
        ];
        $check = $this->tool('records_validate', ['table' => 'items', 'records' => $recs]);
        $this->assertTrue($check['valid']);
        // the link to remove disappears between the dry run and the write
        static::$db->execInTransaction('DELETE FROM bdus_userlinks WHERE id = 2');
        $res = $this->tool('records_write', ['table' => 'items', 'records' => $recs, 'validation_token' => $check['validation_token']]);
        $this->assertTrue($res['__isError']);
        $this->assertSame('link_not_found', $res['detail']['code']);
        $this->assertSame(1, $res['detail']['index']);
        $this->assertSame([['items', 1, 'items', 2]], $this->stored());
        $this->assertNotSame('Rollback probe', static::$db->query('SELECT name FROM items WHERE id = 1', [], 'read')[0]['name']);
    }

    public function testWritingSwitchedOffRefusesTheSection(): void
    {
        $res = $this->tool('records_write', [
            'table' => 'items', 'validation_token' => 'x', 'records' => [$this->item(1, ['add' => [['table' => 'items', 'id' => 3]]])],
        ], ['write' => false]);
        $this->assertTrue($res['__isError']);
        $this->assertCount(1, $this->stored());
    }

    // ── privileges: both records ──────────────────────────────────────────────

    public function testReaderCannotChangeLinks(): void
    {
        $this->setPrivilege(30);
        $res = $this->tool('records_validate', ['table' => 'items', 'records' => [$this->item(1, ['add' => [['table' => 'items', 'id' => 3]]])]]);
        $this->assertFalse($res['valid']);
        $this->assertSame('not_enough_privilege', $res['results'][0]['code']);
    }

    public function testSelfWriterNeedsToOwnBothRecords(): void
    {
        $this->setPrivilege(25);
        static::$db->execInTransaction('UPDATE items SET creator = 99');
        static::$db->execInTransaction('UPDATE items SET creator = 1 WHERE id IN (1, 3)');

        // both own
        $res = $this->validateAndWrite('items', [$this->item(1, ['add' => [['table' => 'items', 'id' => 3]]])]);
        $this->assertFalse($res['__isError'], json_encode($res));

        // the other record belongs to someone else: refused at the dry run
        $res = $this->tool('records_validate', ['table' => 'items', 'records' => [$this->item(1, ['add' => [['table' => 'items', 'id' => 4]]])]]);
        $this->assertFalse($res['valid']);
        $this->assertSame('not_enough_privilege', $res['results'][0]['code']);

        // and the link to it cannot be removed either (seeded link 1↔2: 2 is not theirs)
        $res = $this->tool('records_validate', ['table' => 'items', 'records' => [$this->item(1, ['remove' => [1]])]]);
        $this->assertFalse($res['valid']);
        $this->assertSame('not_enough_privilege', $res['results'][0]['code']);
    }
}
