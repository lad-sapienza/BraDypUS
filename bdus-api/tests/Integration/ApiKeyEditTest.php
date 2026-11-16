<?php

namespace Tests\Integration;

use Auth\ApiKeyAuth;
use Auth\CurrentUser;
use DB\System\Manage;
use DB\System\Migrations\M055_AddDisabledAtToApiKeys;
use Tests\Support\BdusTestCase;

/**
 * An API key can be edited (label, level, group) and switched off and on without being
 * recreated (M055 `disabled_at`); revoking stays final. Rights are read from the table at
 * every request, so every change is immediate.
 */
class ApiKeyEditTest extends BdusTestCase
{
    private int $groupA;
    private int $groupB;

    /** What the key was authenticated as in the last authenticate(): the CurrentUser, before the admin is put back. */
    private array $seen = [];

    protected function setUp(): void
    {
        $this->groupA = (int) static::$db->query("INSERT INTO bdus_groups (name) VALUES ('A')", [], 'id');
        $this->groupB = (int) static::$db->query("INSERT INTO bdus_groups (name) VALUES ('B')", [], 'id');
        $this->setPrivilege(1);
    }

    protected function tearDown(): void
    {
        static::$db->exec('DELETE FROM bdus_api_keys');
        static::$db->exec('DELETE FROM bdus_groups');
        unset($_SERVER['HTTP_AUTHORIZATION']);
        $this->setPrivilege(1);
    }

    // ── Helpers ───────────────────────────────────────────────────────────────

    private function api(string $method, array $get = [], array $post = []): array
    {
        return $this->callController($this->makeController('Bdus\\Controllers\\Api', $get, $post), $method);
    }

    /** Creates a key; returns [id, plain key]. */
    private function create(array $post = ['label' => 'k', 'privilege' => 39]): array
    {
        $post += ['group_id' => $this->groupA];
        if (($post['privilege'] ?? 0) === 10) {
            unset($post['group_id']);
        }
        $res = $this->api('createKey', [], $post);
        $this->assertSame('success', $res['status'], json_encode($res));
        return [(int) $res['id'], $res['key']];
    }

    private function edit(int $id, array $post): array
    {
        return $this->api('editKey', ['id' => $id], $post);
    }

    /** Presents the key like a request would; the administrator is back in charge afterwards. */
    private function authenticate(string $plain): bool
    {
        $_SERVER['HTTP_AUTHORIZATION'] = 'Bearer ' . $plain;
        CurrentUser::set([]);
        $ok         = ApiKeyAuth::attempt(static::$db);
        $this->seen = $ok ? CurrentUser::get() : [];
        $this->setPrivilege(1);
        return $ok;
    }

    private function row(int $id): array
    {
        return array_values(array_filter(
            $this->api('listKeys')['keys'],
            fn($k) => (int) $k['id'] === $id
        ))[0];
    }

    // ── Edit ──────────────────────────────────────────────────────────────────

    public function testEditChangesOnlyWhatIsSent(): void
    {
        [$id] = $this->create(['label' => 'before', 'privilege' => 39]);

        $res = $this->edit($id, ['label' => '  after  ']);
        $this->assertSame('ok_api_key_updated', $res['code'], json_encode($res));
        $this->assertSame('after', $res['label']);
        $this->assertSame(39, $res['privilege']);
        $this->assertSame($this->groupA, $res['group_id']);

        $row = $this->row($id);
        $this->assertSame('after', $row['label']);
        $this->assertSame(39, (int) $row['privilege']);
        $this->assertSame($this->groupA, $row['group_id']);

        $this->assertSame($this->groupB, $this->edit($id, ['group_id' => $this->groupB])['group_id']);
        $this->assertSame('after', $this->row($id)['label'], 'the label was not asked for, nor lost');
    }

    public function testEditFollowsTheRulesOfCreation(): void
    {
        [$id] = $this->create();

        $cases = [
            'group_required'    => ['group_id' => null],                                 // Enter needs a group
            'group_not_found'   => ['group_id' => 999999],
            'invalid_privilege' => ['privilege' => 12],                                  // not a level
        ];
        foreach ($cases as $code => $post) {
            $res = $this->edit($id, $post);
            $this->assertSame('error', $res['status'], $code);
            $this->assertSame($code, $res['code']);
        }
        // an admin key takes no group: moving to admin while keeping the group is refused...
        $this->assertSame('invalid_privilege', $this->edit($id, ['privilege' => 10])['code']);
        // ...and fine once the group goes with it
        $this->assertSame('ok_api_key_updated', $this->edit($id, ['privilege' => 10, 'group_id' => ''])['code']);
        $this->assertNull($this->row($id)['group_id']);
        $this->assertSame(10, (int) $this->row($id)['privilege']);

        // a reader may have a group or none
        $this->assertSame('ok_api_key_updated', $this->edit($id, ['privilege' => 30])['code']);
        $this->assertSame('ok_api_key_updated', $this->edit($id, ['group_id' => $this->groupB])['code']);
        $this->assertSame($this->groupB, $this->row($id)['group_id']);

        $this->assertSame('parameter_missing', $this->edit($id, ['label' => '  '])['code']);
        $this->assertSame('not_found', $this->edit(999999, ['label' => 'x'])['code']);
        $this->assertSame('parameter_missing', $this->api('editKey', [], ['label' => 'x'])['code']);
    }

    public function testAChangeIsImmediate(): void
    {
        [$id, $plain] = $this->create(['label' => 'live', 'privilege' => 39]);

        $this->assertTrue($this->authenticate($plain));
        $this->assertSame($this->groupA, $this->seen['group_id']);
        $this->assertSame(39, $this->seen['privilege']);

        $this->edit($id, ['group_id' => $this->groupB]);
        $this->assertTrue($this->authenticate($plain), 'the same secret keeps working');
        $this->assertSame($this->groupB, $this->seen['group_id']);

        $this->edit($id, ['privilege' => 30, 'group_id' => null]);
        $this->assertTrue($this->authenticate($plain));
        $this->assertSame(30, $this->seen['privilege']);
        $this->assertNull($this->seen['group_id']);
        $this->assertSame($id, $this->seen['key_id']);
    }

    public function testOnlyAdministratorsManageKeys(): void
    {
        [$id] = $this->create();
        foreach ([25, 30, 39] as $tier) {
            $this->setPrivilege($tier);
            foreach ([
                ['editKey', ['id' => $id], ['label' => 'x']],
                ['disableKey', ['id' => $id], []],
                ['enableKey', ['id' => $id], []],
            ] as [$method, $get, $post]) {
                $this->assertSame('not_enough_privilege', $this->api($method, $get, $post)['code'] ?? null, "{$method} at {$tier}");
            }
        }
        $this->setPrivilege(1);
        $this->assertSame('key', $this->row($id)['label'] === 'k' ? 'key' : 'changed', 'nothing changed');

        foreach (['editKey', 'disableKey', 'enableKey'] as $m) {
            $this->assertSame('admin', \Bdus\Router::ROUTE_PRIVILEGE['Bdus\\Controllers\\Api::' . $m] ?? null, $m);
        }
    }

    // ── Disable, enable, revoke ───────────────────────────────────────────────

    public function testDisableRefusesTheKeyAndEnableBringsItBack(): void
    {
        [$id, $plain] = $this->create();
        $this->assertTrue($this->authenticate($plain));

        $this->assertSame('ok_api_key_disabled', $this->api('disableKey', ['id' => $id])['code']);
        $this->assertFalse($this->authenticate($plain), 'a disabled key is refused');
        $row = $this->row($id);
        $this->assertTrue($row['is_disabled']);
        $this->assertFalse($row['is_revoked']);
        $this->assertFalse($row['is_active']);

        $this->assertSame('ok_api_key_enabled', $this->api('enableKey', ['id' => $id])['code']);
        $this->assertTrue($this->authenticate($plain), 'the same secret works again');
        $row = $this->row($id);
        $this->assertTrue($row['is_active']);
        $this->assertFalse($row['is_disabled']);
        $this->assertNull($row['disabled_at']);
    }

    public function testDisablingTwiceKeepsTheFirstTimeAndEditingADisabledKeyIsAllowed(): void
    {
        [$id] = $this->create();
        $this->api('disableKey', ['id' => $id]);
        $first = $this->row($id)['disabled_at'];
        $this->assertNotNull($first);
        static::$db->query('UPDATE bdus_api_keys SET disabled_at = ? WHERE id = ?', [(int) $first - 100, $id], 'boolean');

        $this->api('disableKey', ['id' => $id]);
        $this->assertSame((int) $first - 100, (int) $this->row($id)['disabled_at']);

        $this->assertSame('ok_api_key_updated', $this->edit($id, ['label' => 'still off'])['code']);
        $this->assertTrue($this->row($id)['is_disabled'], 'editing does not switch it on');
    }

    public function testRevokingIsFinal(): void
    {
        [$id, $plain] = $this->create();
        $this->api('revokeKey', ['id' => $id]);
        $this->assertFalse($this->authenticate($plain));
        $this->assertTrue($this->row($id)['is_revoked']);

        foreach ([['enableKey', []], ['disableKey', []], ['editKey', ['label' => 'x']]] as [$method, $post]) {
            $res = $this->api($method, ['id' => $id], $post);
            $this->assertSame('api_key_revoked', $res['code'] ?? null, $method);
        }
        $this->assertFalse($this->authenticate($plain), 'enabling cannot bring a revoked key back');
        $this->assertSame('k', $this->row($id)['label']);
    }

    public function testDisableAndEnableRefusals(): void
    {
        $this->assertSame('not_found', $this->api('disableKey', ['id' => 999999])['code']);
        $this->assertSame('not_found', $this->api('enableKey', ['id' => 999999])['code']);
        $this->assertSame('parameter_missing', $this->api('disableKey')['code']);
    }

    // ── M055 ──────────────────────────────────────────────────────────────────

    public function testKeysWorkBeforeM055AndTheMigrationIsAdditiveAndIdempotent(): void
    {
        [$id, $plain] = $this->create();
        $manage = new Manage(static::$db);

        static::$db->exec('ALTER TABLE bdus_api_keys DROP COLUMN disabled_at');
        try {
            $this->assertFalse($manage->columnExists('bdus_api_keys', 'disabled_at'));
            $this->assertTrue($this->authenticate($plain), 'a key keeps working until the migration runs');
            $this->assertSame('error_saved', $this->api('disableKey', ['id' => $id])['code'], 'disabling needs the column');
            $this->assertSame('k', $this->row($id)['label']);
            $this->assertFalse($this->row($id)['is_disabled']);
        } finally {
            M055_AddDisabledAtToApiKeys::run($manage);
        }

        $this->assertTrue($manage->columnExists('bdus_api_keys', 'disabled_at'));
        M055_AddDisabledAtToApiKeys::run($manage);   // idempotent
        $this->assertTrue($this->authenticate($plain), 'existing keys stay enabled');
        $this->assertSame($id, $this->seen['key_id']);
    }
}
