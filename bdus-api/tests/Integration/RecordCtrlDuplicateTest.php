<?php

namespace Tests\Integration;

use Tests\Support\BdusTestCase;

/**
 * Integration tests for Record::duplicateRecord().
 */
class RecordCtrlDuplicateTest extends BdusTestCase
{
    private const TB = 'items';

    public function testDuplicateRecordHappyPath(): void
    {
        $ctrl = $this->makeController(
            'Bdus\\Controllers\\Record',
            ['tb' => self::TB, 'id' => 1],
            []
        );
        $res = $this->callController($ctrl, 'duplicateRecord');

        $this->assertSame('success', $res['status']);
        $this->assertSame('success_duplicated', $res['code']);
        $this->assertNotEmpty($res['id']);
        $newId = (int)$res['id'];
        $this->assertGreaterThan(1, $newId);

        // Verify the new row has the same name as the source
        $source = static::$db->query('SELECT name, description FROM items WHERE id = 1', [], 'read');
        $copy   = static::$db->query('SELECT name, description FROM items WHERE id = ?', [$newId], 'read');
        $this->assertSame($source[0]['name'],        $copy[0]['name']);
        $this->assertSame($source[0]['description'], $copy[0]['description']);

        // creator must be the test user (id=1 → cast to string for TEXT column)
        $creatorRow = static::$db->query('SELECT creator FROM items WHERE id = ?', [$newId], 'read');
        $this->assertSame('1', (string)$creatorRow[0]['creator']);

        // Clean up
        static::$db->query('DELETE FROM items WHERE id = ?', [$newId], 'boolean');
    }

    public function testDuplicateRecordViaApiKeyStoresNullCreatorNotZero(): void
    {
        // Regression test for issue #56 — see the equivalent saveRecord()
        // test in RecordCtrlSaveEraseTest for why this fixture can't
        // reproduce the actual FK-violation crash (real tables only,
        // covered end-to-end in 25_api_keys.hurl 25e-bis), only the value.
        \Auth\CurrentUser::set([
            'id' => 0, 'name' => 'API Key: CI key', 'privilege' => 25, 'is_api_key' => true,
        ]);

        $ctrl = $this->makeController('Bdus\\Controllers\\Record', ['tb' => self::TB, 'id' => 1], []);
        $res  = $this->callController($ctrl, 'duplicateRecord');
        $this->assertSame('success', $res['status'], $res['code'] ?? '');
        $newId = (int) $res['id'];

        $this->setPrivilege(1);

        $row = static::$db->query('SELECT creator FROM items WHERE id = ?', [$newId], 'read');
        $this->assertNull($row[0]['creator'], 'creator must be null, not 0, for an API-key duplicate');

        static::$db->query('DELETE FROM items WHERE id = ?', [$newId], 'boolean');
    }

    public function testDuplicateMissingTbReturnsError(): void
    {
        $ctrl = $this->makeController('Bdus\\Controllers\\Record', ['id' => 1], []);
        $res  = $this->callController($ctrl, 'duplicateRecord');
        $this->assertSame('error', $res['status']);
        $this->assertSame('parameter_missing', $res['code']);
    }

    public function testDuplicateMissingIdReturnsError(): void
    {
        $ctrl = $this->makeController('Bdus\\Controllers\\Record', ['tb' => self::TB], []);
        $res  = $this->callController($ctrl, 'duplicateRecord');
        $this->assertSame('error', $res['status']);
        $this->assertSame('parameter_missing', $res['code']);
    }

    public function testDuplicateUnknownTableReturnsError(): void
    {
        $ctrl = $this->makeController('Bdus\\Controllers\\Record', ['tb' => 'nonexistent_table', 'id' => 1], []);
        $res  = $this->callController($ctrl, 'duplicateRecord');
        $this->assertSame('error', $res['status']);
        $this->assertSame('unknown_table', $res['code']);
    }

    public function testDuplicateNonexistentRecordReturnsError(): void
    {
        $ctrl = $this->makeController('Bdus\\Controllers\\Record', ['tb' => self::TB, 'id' => 99999], []);
        $res  = $this->callController($ctrl, 'duplicateRecord');
        $this->assertSame('error', $res['status']);
        $this->assertSame('record_not_found', $res['code']);
    }

    public function testDuplicatePrivilegeGuard(): void
    {
        // Set privilege to read-only (30 = read, anything above add_new threshold fails add_new)
        $this->setPrivilege(30);

        $ctrl = $this->makeController('Bdus\\Controllers\\Record', ['tb' => self::TB, 'id' => 1], []);
        $res  = $this->callController($ctrl, 'duplicateRecord');
        $this->assertSame('error', $res['status']);
        $this->assertSame('not_enough_privilege', $res['code']);

        // Restore
        $this->setPrivilege(1);
    }

    public function testDuplicateRecordOnPluginTableSucceeds(): void
    {
        // Regression test for issue #64 (duplicate path): `tags` (is_plugin=1,
        // plugin_of='items') has no `creator` column by design — the
        // unconditional `$source['creator'] = ...` used to add one anyway,
        // breaking the INSERT with "no such column".
        $ctrl = $this->makeController('Bdus\\Controllers\\Record', ['tb' => 'tags', 'id' => 1], []);
        $res  = $this->callController($ctrl, 'duplicateRecord');

        $this->assertSame('success', $res['status']);
        $this->assertSame('success_duplicated', $res['code']);
        $newId = (int) $res['id'];
        $this->assertGreaterThan(0, $newId);

        $copy = static::$db->query('SELECT label, id_link FROM tags WHERE id = ?', [$newId], 'read');
        $this->assertSame('tag-a', $copy[0]['label']);

        static::$db->query('DELETE FROM tags WHERE id = ?', [$newId], 'boolean');
    }
}
