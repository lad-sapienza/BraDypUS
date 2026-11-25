<?php

namespace Tests\Integration;

use Tests\Support\BdusTestCase;
use Mcp\Server;
use Mcp\Context;
use PHPUnit\Framework\Attributes\DataProvider;

/**
 * The Zotero links of a record through MCP: read with records_read, find with
 * bibliography_search, write inside records_validate / records_write. Citations
 * are not fetched (no network in tests): links are stored without a cache.
 */
class McpBibliographyTest extends BdusTestCase
{
    private int $lib;

    protected static function createSchema(): void
    {
        parent::createSchema();

        static::$db->execInTransaction('
            CREATE TABLE bdus_zotero_libs (
                id             INTEGER PRIMARY KEY AUTOINCREMENT,
                type           TEXT    NOT NULL,
                zotero_id      TEXT    NOT NULL,
                name           TEXT    NOT NULL,
                api_key        TEXT,
                citation_style TEXT,
                created_at     INTEGER
            )
        ');

        static::$db->execInTransaction('
            CREATE UNIQUE INDEX zl_type_id_idx ON bdus_zotero_libs (type, zotero_id)
        ');

        static::$db->execInTransaction('
            CREATE TABLE bdus_zotero_links (
                id             INTEGER PRIMARY KEY AUTOINCREMENT,
                tb             TEXT    NOT NULL,
                record_id      INTEGER NOT NULL,
                lib_id         INTEGER NOT NULL,
                zotero_key     TEXT    NOT NULL,
                pages          TEXT,
                notes          TEXT,
                sort           INTEGER,
                author_year    TEXT,
                full_citation  TEXT,
                zotero_version INTEGER,
                synced_at      INTEGER,
                detached       INTEGER NOT NULL DEFAULT 0,
                created_at     INTEGER
            )
        ');

        static::$db->execInTransaction('
            CREATE INDEX zln_record_idx ON bdus_zotero_links (tb, record_id)
        ');

        static::$db->execInTransaction('
            CREATE INDEX zln_item_idx ON bdus_zotero_links (lib_id, zotero_key)
        ');
    }

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
        static::$db->execInTransaction('DELETE FROM bdus_zotero_links');
        static::$db->execInTransaction('DELETE FROM bdus_zotero_libs');
        static::$db->execInTransaction("INSERT INTO bdus_zotero_libs (id, type, zotero_id, name) VALUES (7, 'group', '99', 'Group lib')");
        $this->lib = 7;
    }

    protected function tearDown(): void
    {
        \Auth\Channel::set(null);
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

    private function links(int $rec = 1): array
    {
        return static::$db->query('SELECT * FROM bdus_zotero_links WHERE tb = ? AND record_id = ? ORDER BY id', ['items', $rec], 'read') ?: [];
    }

    private function link(int $rec, string $key, string $pages = ''): int
    {
        static::$db->execInTransaction("INSERT INTO bdus_zotero_links (tb, record_id, lib_id, zotero_key, pages, sort) VALUES ('items', {$rec}, {$this->lib}, '{$key}', '{$pages}', 0)");
        return (int) static::$db->query('SELECT MAX(id) AS id FROM bdus_zotero_links', [], 'read')[0]['id'];
    }

    // ── read ──────────────────────────────────────────────────────────────────

    public function testRecordsReadReturnsTheLinksOnRequestOnly(): void
    {
        $id = $this->link(1, 'AAAA1111', '12-14');
        $this->link(2, 'BBBB2222');

        $plain = $this->tool('records_read', ['table' => 'items', 'filter' => ['id' => ['_eq' => 1]]]);
        $this->assertArrayNotHasKey('bibliography', $plain['rows'][0]);

        $res = $this->tool('records_read', ['table' => 'items', 'bibliography' => true, 'limit' => 5]);
        $byId = array_column($res['rows'], null, 'id');
        $this->assertCount(1, $byId[1]['bibliography']);
        $this->assertSame($id, $byId[1]['bibliography'][0]['id']);
        $this->assertSame('AAAA1111', $byId[1]['bibliography'][0]['zotero_key']);
        $this->assertSame('12-14', $byId[1]['bibliography'][0]['pages']);
        $this->assertSame('Group lib', $byId[1]['bibliography'][0]['lib_name']);
        $this->assertSame([], $byId[3]['bibliography'] ?? []);
    }

    public function testRecordsReadBibliographyRefusedWithoutZotero(): void
    {
        $res = $this->tool('records_read', ['table' => 'reviews', 'bibliography' => true]);
        $this->assertTrue($res['__isError']);
        $this->assertSame('zotero_not_configured', $res['code']);
    }

    // ── search ────────────────────────────────────────────────────────────────

    public function testSearchWithoutQueryListsLibraries(): void
    {
        $res = $this->tool('bibliography_search', []);
        $this->assertFalse($res['__isError']);
        $this->assertSame(7, $res['libraries'][0]['lib_id']);
        $this->assertArrayNotHasKey('api_key', $res['libraries'][0]);
    }

    public function testSearchNeedsTheRightToEditAndAKnownLibrary(): void
    {
        $res = $this->tool('bibliography_search', ['query' => 'x', 'lib_id' => 999]);
        $this->assertSame('lib_not_found', $res['code']);

        $this->setPrivilege(30);
        $res = $this->tool('bibliography_search', []);
        $this->assertSame('not_enough_privilege', $res['code']);
    }

    // ── write ─────────────────────────────────────────────────────────────────

    public function testWriteAddsEditsAndRemovesLinksWithTheRecord(): void
    {
        $keep = $this->link(1, 'KEEP0001', '1');
        $gone = $this->link(1, 'GONE0001');

        $res = $this->validateAndWrite('items', [[
            'id' => 1, 'core' => ['status' => 'archived'],
            'bibliography' => [
                'add'    => [['lib_id' => $this->lib, 'zotero_key' => 'NEW00001', 'pages' => '5', 'sort' => 3]],
                'edit'   => [['id' => $keep, 'pages' => '99', 'notes' => 'see also']],
                'remove' => [$gone],
            ],
        ]]);
        $this->assertFalse($res['__isError'], json_encode($res));

        $rows = array_column($this->links(), null, 'zotero_key');
        $this->assertSame(['KEEP0001', 'NEW00001'], array_keys($rows));
        $this->assertSame('99', $rows['KEEP0001']['pages']);
        $this->assertSame('see also', $rows['KEEP0001']['notes']);
        $this->assertSame('5', $rows['NEW00001']['pages']);
        $this->assertSame('archived', static::$db->query('SELECT status FROM items WHERE id = 1', [], 'read')[0]['status']);
    }

    public function testBibliographyOnlyUpdateAndCreateWithLinks(): void
    {
        $res = $this->validateAndWrite('items', [['id' => 2, 'core' => [], 'bibliography' => ['add' => [['lib_id' => 7, 'zotero_key' => 'ONLY0001']]]]]);
        $this->assertFalse($res['__isError'], json_encode($res));
        $this->assertCount(1, $this->links(2));

        $res = $this->validateAndWrite('items', [['core' => ['name' => 'With a source'], 'bibliography' => ['add' => [['lib_id' => 7, 'zotero_key' => 'NEWREC01']]]]]);
        $this->assertFalse($res['__isError'], json_encode($res));
        $this->assertCount(1, $this->links($res['results'][0]['id']));
    }

    public function testValidateShowsTheSectionAndWritesNothing(): void
    {
        $res = $this->tool('records_validate', ['table' => 'items', 'records' => [
            ['id' => 1, 'core' => [], 'bibliography' => ['add' => [['lib_id' => 7, 'zotero_key' => 'DRY00001']]]],
        ]]);
        $this->assertTrue($res['valid']);
        $this->assertSame('DRY00001', $res['results'][0]['bibliography']['add'][0]['zotero_key']);
        $this->assertSame([], $this->links());
    }

    public function testTokenCoversTheBibliography(): void
    {
        $recs  = [['id' => 1, 'core' => [], 'bibliography' => ['add' => [['lib_id' => 7, 'zotero_key' => 'TOKEN001']]]]];
        $check = $this->tool('records_validate', ['table' => 'items', 'records' => $recs]);
        $recs[0]['bibliography']['add'][0]['zotero_key'] = 'OTHER001';
        $res = $this->tool('records_write', ['table' => 'items', 'records' => $recs, 'validation_token' => $check['validation_token']]);
        $this->assertSame('validation_token_invalid', $res['code']);
        $this->assertSame([], $this->links());
    }

    public function testRefusedForATableWithoutZotero(): void
    {
        $res = $this->tool('records_validate', ['table' => 'reviews', 'records' => [
            ['core' => ['reviewer' => 'x'], 'bibliography' => ['add' => [['lib_id' => 7, 'zotero_key' => 'NOPE0001']]]],
        ]]);
        $this->assertFalse($res['valid']);
        $this->assertSame('zotero_not_configured', $res['results'][0]['code']);
    }

    #[DataProvider('badSections')]
    public function testBadSectionsAreRefusedWithACode(array $section, string $code): void
    {
        $link = $this->link(1, 'EXIST001');
        $section = json_decode(str_replace('"@LINK"', (string) $link, json_encode($section)), true);
        $res = $this->tool('records_validate', ['table' => 'items', 'records' => [['id' => 1, 'core' => [], 'bibliography' => $section]]]);
        if ($res['__isError']) {
            $this->assertSame($code, $res['code']);
        } else {
            $this->assertFalse($res['valid']);
            $this->assertSame($code, $res['results'][0]['code']);
        }
    }

    public static function badSections(): array
    {
        return [
            'unknown library'   => [['add' => [['lib_id' => 55, 'zotero_key' => 'K']]], 'lib_not_found'],
            'duplicate link'    => [['add' => [['lib_id' => 7, 'zotero_key' => 'EXIST001']]], 'link_already_exists'],
            'same key twice'    => [['add' => [['lib_id' => 7, 'zotero_key' => 'T'], ['lib_id' => 7, 'zotero_key' => 'T']]], 'link_already_exists'],
            'foreign link'      => [['remove' => [424242]], 'link_not_found'],
            'edit foreign link' => [['edit' => [['id' => 424242, 'pages' => '1']]], 'link_not_found'],
            'edit and remove'   => [['edit' => [['id' => '@LINK', 'pages' => '1']], 'remove' => ['@LINK']], 'invalid_parameters'],
            'missing key'       => [['add' => [['lib_id' => 7]]], 'parameter_missing'],
            'unknown part'      => [['replace' => []], 'invalid_parameters'],
            'unknown prop'      => [['add' => [['lib_id' => 7, 'zotero_key' => 'K', 'full_citation' => 'forged']]], 'invalid_parameters'],
        ];
    }

    public function testLinkOfAnotherRecordCannotBeTouched(): void
    {
        $other = $this->link(2, 'OTHER002');
        $res = $this->tool('records_validate', ['table' => 'items', 'records' => [['id' => 1, 'core' => [], 'bibliography' => ['remove' => [$other]]]]]);
        $this->assertFalse($res['valid']);
        $this->assertSame('link_not_found', $res['results'][0]['code']);
    }

    public function testNothingIsWrittenWhenALaterRecordFails(): void
    {
        $check = $this->tool('records_validate', ['table' => 'items', 'records' => [
            ['id' => 1, 'core' => [], 'bibliography' => ['add' => [['lib_id' => 7, 'zotero_key' => 'ATOMIC01']]]],
        ]]);
        // the library disappears between the dry run and the write
        $recs = [['id' => 1, 'core' => [], 'bibliography' => ['add' => [['lib_id' => 7, 'zotero_key' => 'ATOMIC01']]]]];
        static::$db->execInTransaction('DELETE FROM bdus_zotero_libs');
        $res = $this->tool('records_write', ['table' => 'items', 'records' => $recs, 'validation_token' => $check['validation_token']]);
        $this->assertTrue($res['__isError']);
        $this->assertSame('lib_not_found', $res['detail']['code']);
        $this->assertSame([], $this->links());
    }

    public function testWritingSwitchedOffRefusesTheSection(): void
    {
        $res = $this->tool('records_write', [
            'table' => 'items', 'validation_token' => 'x',
            'records' => [['id' => 1, 'core' => [], 'bibliography' => ['add' => [['lib_id' => 7, 'zotero_key' => 'OFF00001']]]]],
        ], ['write' => false]);
        $this->assertTrue($res['__isError']);
        $this->assertSame([], $this->links());
    }

    public function testReaderCannotChangeLinks(): void
    {
        $this->setPrivilege(30);
        $res = $this->tool('records_validate', ['table' => 'items', 'records' => [
            ['id' => 1, 'core' => [], 'bibliography' => ['add' => [['lib_id' => 7, 'zotero_key' => 'READER01']]]],
        ]]);
        $this->assertFalse($res['valid']);
        $this->assertSame('not_enough_privilege', $res['results'][0]['code']);
    }

    public function testSelfWriterMayLinkTheirOwnNewRecordButNotAnotherOnes(): void
    {
        $this->setPrivilege(25);
        $res = $this->validateAndWrite('items', [['core' => ['name' => 'Mine'], 'bibliography' => ['add' => [['lib_id' => 7, 'zotero_key' => 'SELF0001']]]]]);
        $this->assertFalse($res['__isError'], json_encode($res));
        $this->assertCount(1, $this->links($res['results'][0]['id']));

        // record 1 was not created by this user
        static::$db->execInTransaction('UPDATE items SET creator = 99 WHERE id = 1');
        $res = $this->tool('records_validate', ['table' => 'items', 'records' => [
            ['id' => 1, 'core' => [], 'bibliography' => ['add' => [['lib_id' => 7, 'zotero_key' => 'SELF0002']]]],
        ]]);
        $this->assertFalse($res['valid']);
        $this->assertSame('not_enough_privilege', $res['results'][0]['code']);
    }
}
