<?php

namespace Tests\Integration;

use Auth\ApiKeyAuth;
use Auth\CurrentUser;
use Tests\Support\BdusTestCase;

/**
 * API keys scoped by a group (#78): creation rules, authentication, and the
 * controllers applying the group's rules to a key like to any other principal.
 */
class ApiKeyGroupTest extends BdusTestCase
{
    private int $group;

    protected function setUp(): void
    {
        $this->group = (int) static::$db->query("INSERT INTO bdus_groups (name) VALUES ('Public')", [], 'id');
        static::$db->query(
            "INSERT INTO bdus_group_rules (group_id, table_name, action, filter) VALUES (?, 'items', 'read', ?)",
            [$this->group, json_encode(['status' => ['_eq' => 'active']])], 'boolean'
        );
    }

    protected function tearDown(): void
    {
        static::$db->exec('DELETE FROM bdus_api_keys');
        static::$db->exec('DELETE FROM bdus_group_rules');
        static::$db->exec('DELETE FROM bdus_groups');
        unset($_SERVER['HTTP_AUTHORIZATION']);
        $this->setPrivilege(1);
    }

    private function createKey(array $post): array
    {
        return $this->callController($this->makeController('Bdus\\Controllers\\Api', [], $post), 'createKey');
    }

    private function authenticate(string $plain): bool
    {
        $_SERVER['HTTP_AUTHORIZATION'] = 'Bearer ' . $plain;
        CurrentUser::set([]);
        return ApiKeyAuth::attempt(static::$db);
    }

    // ── Creation ──────────────────────────────────────────────────────────────

    public function testScopedKeyIsCreatedAndListedWithItsGroup(): void
    {
        $res = $this->createKey(['label' => 'site', 'privilege' => 39, 'group_id' => $this->group]);

        $this->assertSame('ok_api_key_created', $res['code']);
        $this->assertSame(39, $res['privilege']);
        $this->assertSame($this->group, $res['group_id']);

        $list = $this->callController($this->makeController('Bdus\\Controllers\\Api'), 'listKeys');
        $this->assertSame($this->group, $list['keys'][0]['group_id']);
        $this->assertSame('Public', $list['keys'][0]['group_name']);
        $this->assertArrayNotHasKey('key_hash', $list['keys'][0]);
    }

    public function testEnterTierKeyNeedsAGroup(): void
    {
        $this->assertSame('group_required', $this->createKey(['label' => 'x', 'privilege' => 39])['code']);
    }

    public function testGroupMustExistAndAdminKeysTakeNone(): void
    {
        $this->assertSame('group_not_found', $this->createKey(['label' => 'x', 'privilege' => 39, 'group_id' => 9999])['code']);
        $this->assertSame('invalid_privilege', $this->createKey(['label' => 'x', 'privilege' => 10, 'group_id' => $this->group])['code']);
    }

    public function testPlainKeysAreCreatedAsBefore(): void
    {
        $res = $this->createKey(['label' => 'plain', 'privilege' => 30]);

        $this->assertSame('ok_api_key_created', $res['code']);
        $this->assertNull($res['group_id']);
        $this->assertSame('invalid_privilege', $this->createKey(['label' => 'x', 'privilege' => 20])['code']);
    }

    // ── Authentication ────────────────────────────────────────────────────────

    public function testAuthenticatedKeyCarriesItsGroup(): void
    {
        $key = $this->createKey(['label' => 'site', 'privilege' => 39, 'group_id' => $this->group])['key'];

        $this->assertTrue($this->authenticate($key));
        $this->assertSame($this->group, CurrentUser::get('group_id'));
        $this->assertSame(39, CurrentUser::privilege());
        $this->assertTrue(CurrentUser::isApiKey());
    }

    public function testKeyWithoutGroupHasNoGroup(): void
    {
        $key = $this->createKey(['label' => 'plain', 'privilege' => 30])['key'];

        $this->assertTrue($this->authenticate($key));
        $this->assertNull(CurrentUser::get('group_id'));
    }

    // ── The controllers treat the key like any principal ──────────────────────

    private function actAsScopedKey(): void
    {
        $key = $this->createKey(['label' => 'site', 'privilege' => 39, 'group_id' => $this->group])['key'];
        $this->authenticate($key);
    }

    public function testScopedKeyReadsOnlyItsRowsAndTables(): void
    {
        $this->actAsScopedKey();

        $list = $this->callController($this->makeController('Bdus\\Controllers\\Record', ['tb' => 'items']), 'getRecords');
        $ids  = array_map('intval', array_column($list['data'], 'id'));
        sort($ids);
        $this->assertSame([1, 3, 5], $ids);

        $other = $this->callController($this->makeController('Bdus\\Controllers\\Record', ['tb' => 'tags']), 'getRecords');
        $this->assertSame('not_enough_privilege', $other['code']);

        $hidden = $this->callController($this->makeController('Bdus\\Controllers\\Record', ['tb' => 'items', 'id' => 2]), 'getRecord');
        $this->assertSame('not_enough_privilege', $hidden['code']);
    }

    public function testScopedKeyCannotWriteWithoutAWriteRule(): void
    {
        $this->actAsScopedKey();

        $save = $this->callController(
            $this->makeController('Bdus\\Controllers\\Record', [], ['tb' => 'items', 'id' => 1, 'core' => ['description' => 'x']]),
            'saveRecord'
        );
        $this->assertSame('not_enough_privilege', $save['code']);
        $this->assertNotSame('x', static::$db->query('SELECT description AS d FROM items WHERE id = 1', [], 'read')[0]['d']);
    }

    public function testRefusalsToAKeyAreAHttp403(): void
    {
        $this->actAsScopedKey();
        http_response_code(200);

        $this->callController($this->makeController('Bdus\\Controllers\\Record', ['tb' => 'tags']), 'getRecords');

        $this->assertSame(403, http_response_code());
        http_response_code(200);
    }

    public function testPeopleKeepTheHttp200Envelope(): void
    {
        CurrentUser::set(['id' => 9, 'privilege' => 39, 'name' => 'u', 'app' => 'test']);
        http_response_code(200);

        $this->callController($this->makeController('Bdus\\Controllers\\Record', ['tb' => 'tags']), 'getRecords');

        $this->assertSame(200, http_response_code());
    }
}
