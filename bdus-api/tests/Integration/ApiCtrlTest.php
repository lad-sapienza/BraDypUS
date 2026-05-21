<?php

namespace Tests\Integration;

use Tests\Support\BdusTestCase;

/**
 * Integration tests for api_ctrl v5 endpoints:
 *   listKeys(), createKey(), revokeKey(), deleteKey()
 *
 * The api_keys table is created in createSchema().
 * All v5 methods use POST bodies; they are placed in $post.
 *
 * NOTE: Auth.php and Router.php (the public REST API) depend on HTTP headers
 * and URI parsing, so they cannot be exercised here.  Only the controller
 * methods (admin dashboard for key management) are tested.
 */
class ApiCtrlTest extends BdusTestCase
{
    // ── Schema extension ──────────────────────────────────────────────────────

    protected static function createSchema(): void
    {
        parent::createSchema();

        static::$db->execInTransaction('
            CREATE TABLE bdus_api_keys (
                id           INTEGER PRIMARY KEY AUTOINCREMENT,
                key_hash     TEXT    NOT NULL,
                label        TEXT    NOT NULL,
                created_by   INTEGER,
                created_at   INTEGER NOT NULL,
                last_used_at INTEGER,
                revoked_at   INTEGER,
                privilege    INTEGER DEFAULT 30
            )
        ');
    }

    // ── listKeys ──────────────────────────────────────────────────────────────

    public function testListKeysSuccess(): void
    {
        $ctrl = $this->makeController('api_ctrl');
        $res  = $this->callController($ctrl, 'listKeys');

        $this->assertSame('success', $res['status']);
        $this->assertArrayHasKey('keys', $res);
        $this->assertIsArray($res['keys']);
    }

    public function testListKeysDoesNotExposeKeyHash(): void
    {
        // Create a key first so there is at least one row
        $create = $this->makeController('api_ctrl', [], ['label' => 'HashTest']);
        $this->callController($create, 'createKey');

        $ctrl = $this->makeController('api_ctrl');
        $res  = $this->callController($ctrl, 'listKeys');

        foreach ($res['keys'] as $row) {
            $this->assertArrayNotHasKey('key_hash', $row, 'key_hash must not be exposed');
            $this->assertArrayHasKey('is_active', $row);
        }
    }

    // ── createKey ─────────────────────────────────────────────────────────────

    public function testCreateKeySuccess(): void
    {
        $ctrl = $this->makeController('api_ctrl', [], ['label' => 'My test key']);
        $res  = $this->callController($ctrl, 'createKey');

        $this->assertSame('success', $res['status']);
        $this->assertSame('ok_api_key_created', $res['code']);
        $this->assertArrayHasKey('id', $res);
        $this->assertArrayHasKey('label', $res);
        $this->assertArrayHasKey('key', $res);
        $this->assertSame('My test key', $res['label']);
    }

    public function testCreateKeyHasPlainKey(): void
    {
        $ctrl = $this->makeController('api_ctrl', [], ['label' => 'Hex key check']);
        $res  = $this->callController($ctrl, 'createKey');

        $this->assertSame('success', $res['status']);
        // The returned key must be a 64-char lowercase hex string (bin2hex(random_bytes(32)))
        $this->assertMatchesRegularExpression('/^[0-9a-f]{64}$/', $res['key']);
    }

    public function testCreateKeyMissingLabel(): void
    {
        $ctrl = $this->makeController('api_ctrl', [], []);
        $res  = $this->callController($ctrl, 'createKey');

        $this->assertSame('error', $res['status']);
        $this->assertSame('parameter_missing', $res['code']);
        $this->assertSame('label', $res['detail']);
    }

    // ── revokeKey ─────────────────────────────────────────────────────────────

    public function testRevokeKeySuccess(): void
    {
        // Create a key to revoke
        $create = $this->makeController('api_ctrl', [], ['label' => 'To revoke']);
        $created = $this->callController($create, 'createKey');
        $id = $created['id'];

        $ctrl = $this->makeController('api_ctrl', [], ['id' => $id]);
        $res  = $this->callController($ctrl, 'revokeKey');

        $this->assertSame('success', $res['status']);
        $this->assertSame('ok_api_key_revoked', $res['code']);

        // Verify the key shows as inactive in the list
        $list = $this->callController($this->makeController('api_ctrl'), 'listKeys');
        $row  = array_values(array_filter($list['keys'], fn($k) => (int)$k['id'] === (int)$id))[0] ?? null;
        $this->assertNotNull($row);
        $this->assertFalse($row['is_active']);
    }

    public function testRevokeKeyMissingId(): void
    {
        $ctrl = $this->makeController('api_ctrl', [], []);
        $res  = $this->callController($ctrl, 'revokeKey');

        $this->assertSame('error', $res['status']);
        $this->assertSame('parameter_missing', $res['code']);
        $this->assertSame('id', $res['detail']);
    }

    // ── deleteKey ─────────────────────────────────────────────────────────────

    public function testDeleteKeySuccess(): void
    {
        // Create a key to delete
        $create  = $this->makeController('api_ctrl', [], ['label' => 'To delete']);
        $created = $this->callController($create, 'createKey');
        $id      = $created['id'];

        $ctrl = $this->makeController('api_ctrl', [], ['id' => $id]);
        $res  = $this->callController($ctrl, 'deleteKey');

        $this->assertSame('success', $res['status']);
        $this->assertSame('ok_api_key_deleted', $res['code']);

        // Verify the key is gone from the list
        $list = $this->callController($this->makeController('api_ctrl'), 'listKeys');
        $ids  = array_column($list['keys'], 'id');
        $this->assertNotContains($id, $ids);
    }

    public function testDeleteKeyMissingId(): void
    {
        $ctrl = $this->makeController('api_ctrl', [], []);
        $res  = $this->callController($ctrl, 'deleteKey');

        $this->assertSame('error', $res['status']);
        $this->assertSame('parameter_missing', $res['code']);
        $this->assertSame('id', $res['detail']);
    }
}
