<?php

namespace Tests\Integration;

use Tests\Support\BdusTestCase;

/**
 * saveRecord(): what a client may send. Covers #67-#71 and the shape of the
 * payload (field and plugin-table names come from the client).
 */
class RecordCtrlSaveValidationTest extends BdusTestCase
{
    private const TB = 'items';

    protected function setUp(): void
    {
        $this->setPrivilege(1);
    }

    private function save(array $post): array
    {
        $ctrl = $this->makeController('Bdus\\Controllers\\Record', [], $post);
        return $this->callController($ctrl, 'saveRecord');
    }

    private function rows(string $tb): int
    {
        return (int) static::$db->query("SELECT COUNT(*) AS c FROM {$tb}", [], 'read')[0]['c'];
    }

    public function testPluginKeyThatIsNotAPluginOfTheTableIsRefused(): void
    {
        $users = $this->rows('bdus_users');
        $items = $this->rows('items');
        $res = $this->save([
            'tb'      => self::TB,
            'core'    => ['name' => 'x', 'status' => 'active'],
            'plugins' => ['bdus_users' => [['fields' => ['name' => 'evil', 'email' => 'e@e.it', 'password' => 'x', 'privilege' => 1]]]],
        ]);
        $this->assertSame('error', $res['status']);
        $this->assertSame($users, $this->rows('bdus_users'), 'no row may be written to a foreign table');
        $this->assertSame($items, $this->rows('items'), 'nothing is written when the payload is refused');
    }

    public function testFieldNameThatIsNotConfiguredIsRefused(): void
    {
        $items = $this->rows('items');
        $res = $this->save([
            'tb'   => self::TB,
            'core' => ['name' => 'x', 'status) VALUES (1,2); --' => 'y'],
        ]);
        $this->assertSame('error', $res['status']);
        $this->assertSame($items, $this->rows('items'));

        $res = $this->save(['tb' => self::TB, 'core' => ['name' => 'x', 'no_such_field' => 'y']]);
        $this->assertSame('validation_failed', $res['code']);
        $this->assertSame($items, $this->rows('items'));
    }

    // #67 — a required field left out of a create payload
    public function testRequiredFieldMissingFromCreatePayloadIsRefused(): void
    {
        $items = $this->rows('items');
        $res = $this->save(['tb' => self::TB, 'core' => ['status' => 'active']]);
        $this->assertSame('validation_failed', $res['code']);
        $this->assertContains('required', array_column($res['errors'], 'rule'));
        $this->assertSame($items, $this->rows('items'));
    }

    public function testUpdateMayLeaveARequiredFieldOut(): void
    {
        $res = $this->save(['tb' => self::TB, 'core' => ['name' => 'Req upd ' . uniqid(), 'status' => 'active']]);
        $id  = (int) $res['id'];
        $res = $this->save(['tb' => self::TB, 'id' => $id, 'core' => ['status' => 'inactive']]);
        $this->assertSame('success', $res['status'], json_encode($res));
        static::$db->query('DELETE FROM items WHERE id = ?', [$id], 'boolean');
    }

    // #68 — nothing to write
    public function testEmptyCoreIsRefusedOnCreate(): void
    {
        $items = $this->rows('items');
        $res = $this->save(['tb' => self::TB, 'core' => []]);
        $this->assertSame('parameter_missing', $res['code']);
        $res = $this->save(['tb' => self::TB]);
        $this->assertSame('parameter_missing', $res['code']);
        $this->assertSame($items, $this->rows('items'));
    }

    public function testUnknownTableIsRefused(): void
    {
        $res = $this->save(['tb' => 'bdus_users', 'core' => ['name' => 'x']]);
        $this->assertSame('error', $res['status']);
    }

    // #69 — lookup ids must exist
    public function testLookupIdThatDoesNotExistIsRefused(): void
    {
        $items = $this->rows('items');
        foreach ([9999, 'abc'] as $bad) {
            $res = $this->save(['tb' => self::TB, 'core' => ['name' => 'Lk ' . uniqid(), 'status' => 'active', 'cat_ref' => $bad]]);
            $this->assertSame('validation_failed', $res['code'], (string) $bad);
            $this->assertContains('fk_not_found', array_column($res['errors'], 'rule'));
        }
        $this->assertSame($items, $this->rows('items'));
    }

    public function testLookupIdThatExistsIsAccepted(): void
    {
        $cat = (int) static::$db->query('SELECT id FROM categories LIMIT 1', [], 'read')[0]['id'];
        $res = $this->save(['tb' => self::TB, 'core' => ['name' => 'Lk ok ' . uniqid(), 'status' => 'active', 'cat_ref' => $cat]]);
        $this->assertSame('success', $res['status'], json_encode($res));
        static::$db->query('DELETE FROM items WHERE id = ?', [(int) $res['id']], 'boolean');
    }

    // #70 — plain values only
    public function testArrayValueIsRefused(): void
    {
        $items = $this->rows('items');
        $res = $this->save(['tb' => self::TB, 'core' => ['name' => ['x'], 'status' => 'active']]);
        $this->assertSame('validation_failed', $res['code']);
        $this->assertContains('invalid_type', array_column($res['errors'], 'rule'));
        $this->assertSame($items, $this->rows('items'));
    }

    // #71 — a failing later step leaves no record behind
    public function testFailedPluginInsertLeavesNoRecordBehind(): void
    {
        $items = $this->rows('items');
        $name  = 'Atomic ' . uniqid();
        // `tags.label` accepts a value; force the plugin INSERT to fail with an
        // over-long id_link-less NOT NULL violation on a column set to null
        static::$db->query('CREATE TRIGGER tags_boom BEFORE INSERT ON tags BEGIN SELECT RAISE(ABORT, \'boom\'); END', [], 'boolean');
        try {
            $res = $this->save([
                'tb'      => self::TB,
                'core'    => ['name' => $name, 'status' => 'active'],
                'plugins' => ['tags' => [['fields' => ['label' => 'x']]]],
            ]);
        } finally {
            static::$db->query('DROP TRIGGER tags_boom', [], 'boolean');
        }
        $this->assertSame('error', $res['status']);
        $this->assertSame($items, $this->rows('items'), 'the core row must not stay behind');
    }

    public function testNewPluginRowCannotChooseItsHostRecord(): void
    {
        $other = $this->save(['tb' => self::TB, 'core' => ['name' => 'Host ' . uniqid(), 'status' => 'active']]);
        $otherId = (int) $other['id'];
        $res = $this->save([
            'tb'      => self::TB,
            'core'    => ['name' => 'Mine ' . uniqid(), 'status' => 'active'],
            'plugins' => ['tags' => [['fields' => ['label' => 'sneaky', 'id_link' => $otherId]]]],
        ]);
        $this->assertSame('success', $res['status'], json_encode($res));
        $mine = (int) $res['id'];
        $this->assertSame(0, (int) static::$db->query('SELECT COUNT(*) c FROM tags WHERE id_link = ?', [$otherId], 'read')[0]['c']);
        $this->assertSame(1, (int) static::$db->query('SELECT COUNT(*) c FROM tags WHERE id_link = ?', [$mine], 'read')[0]['c']);
        static::$db->query('DELETE FROM tags WHERE id_link IN (?, ?)', [$mine, $otherId], 'boolean');
        static::$db->query('DELETE FROM items WHERE id IN (?, ?)', [$mine, $otherId], 'boolean');
    }
}
