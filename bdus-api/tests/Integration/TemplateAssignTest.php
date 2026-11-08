<?php

namespace Tests\Integration;

use Template\Assign;
use Template\Loader;
use Tests\Support\BdusTestCase;

/**
 * Record layouts per group (M051, Template\Assign): the templates a group's
 * members must use on a table. Interface convenience, not a permission.
 *
 * Fixture table `items` (fields include `name`, `status`); the principal is
 * user 53 at tier 30 (read).
 */
class TemplateAssignTest extends BdusTestCase
{
    private const TPL = '{"sections":[{"label":"A","content":[{"field":"name","width":"1/1"}]}]}';

    protected function setUp(): void
    {
        static::$db->exec("INSERT INTO bdus_users (id, name, email, password, privilege) VALUES (53, 'r', 'r@example.com', 'x', 30)");
        Loader::setDb(static::$db);
        foreach (['compact', 'review', 'readonly'] as $name) {
            static::$db->query(
                'INSERT INTO bdus_cfg_templates (table_name, name, content) VALUES (?, ?, ?)',
                ['items', $name, self::TPL], 'boolean'
            );
        }
        $this->actAs(30);
    }

    protected function tearDown(): void
    {
        static::$db->exec('DELETE FROM bdus_user_groups');
        static::$db->exec('DELETE FROM bdus_groups');
        static::$db->exec('DELETE FROM bdus_cfg_templates');
        static::$db->exec('DELETE FROM bdus_users WHERE id = 53');
        Loader::setDb(null);
        $this->setPrivilege(1);
    }

    private function actAs(int $tier): void
    {
        \Auth\CurrentUser::set(['id' => 53, 'name' => 'r', 'email' => 'r@example.com', 'privilege' => $tier, 'app' => 'test']);
    }

    /** A group (principal is a member) with layouts {table: [templates]}. */
    private function group(?array $layouts, bool $member = true): int
    {
        static $n = 0;
        $id = (int) static::$db->query(
            'INSERT INTO bdus_groups (name, layouts) VALUES (?, ?)',
            ['L' . (++$n), $layouts === null ? null : json_encode($layouts)], 'id'
        );
        if ($member) {
            static::$db->exec("INSERT INTO bdus_user_groups (user_id, group_id) VALUES (53, {$id})");
        }
        return $id;
    }

    private function templates(): array
    {
        return $this->callController($this->makeController('Bdus\\Controllers\\Record', ['tb' => 'items']), 'getTemplates');
    }

    private function record(?string $template): array
    {
        $get = ['tb' => 'items', 'id' => 1] + ($template ? ['template' => $template] : []);
        return $this->callController($this->makeController('Bdus\\Controllers\\Record', $get), 'getRecord');
    }

    private function groupsCtl(string $method, array $get, array $post = []): array
    {
        return $this->callController($this->makeController('Bdus\\Controllers\\Groups', $get, $post), $method);
    }

    // ── Resolution ────────────────────────────────────────────────────────────

    public function testNoAssignmentChangesNothing(): void
    {
        $this->group(null);
        $res = $this->templates();
        $this->assertSame(['compact', 'readonly', 'review'], $res['templates']);
        $this->assertFalse($res['assigned']);
        $this->assertFalse($res['locked']);
    }

    public function testSingleLayoutIsLocked(): void
    {
        $this->group(['items' => ['review']]);
        $res = $this->templates();
        $this->assertSame(['review'], $res['templates']);
        $this->assertTrue($res['assigned']);
        $this->assertTrue($res['locked']);
    }

    public function testSeveralLayoutsAreUnitedInGroupOrderWithoutDuplicates(): void
    {
        $this->group(['items' => ['review', 'compact']]);
        $this->group(['items' => ['compact', 'readonly']]);
        $res = $this->templates();
        $this->assertSame(['review', 'compact', 'readonly'], $res['templates']);
        $this->assertFalse($res['locked']);
    }

    public function testOtherTablesAndGroupsWithoutMembershipAreIgnored(): void
    {
        $this->group(['tags' => ['review']]);
        $this->group(['items' => ['compact']], false);
        $this->assertFalse($this->templates()['assigned']);
    }

    public function testStaleNamesAreIgnoredAndAllStaleMeansNoRestriction(): void
    {
        $this->group(['items' => ['gone', 'review']]);
        $this->assertSame(['review'], $this->templates()['templates']);

        static::$db->exec("UPDATE bdus_groups SET layouts = '{\"items\":[\"gone\"]}'");
        $this->assertFalse($this->templates()['assigned']);
    }

    public function testBrokenJsonIsIgnored(): void
    {
        static::$db->exec("INSERT INTO bdus_groups (id, name, layouts) VALUES (90, 'bad', '{nope')");
        static::$db->exec('INSERT INTO bdus_user_groups (user_id, group_id) VALUES (53, 90)');
        $this->assertFalse($this->templates()['assigned']);
    }

    public function testAdminsAreNotRestricted(): void
    {
        $this->group(['items' => ['review']]);
        $this->actAs(10);
        $this->assertFalse($this->templates()['assigned']);
        $this->assertCount(3, $this->templates()['templates']);
    }

    public function testRecordUsesFirstAssignedWhenNoneOrForeignRequested(): void
    {
        $this->group(['items' => ['review', 'compact']]);
        foreach ([null, 'readonly', 'nope'] as $asked) {
            $this->assertSame('A', $this->record($asked)['schema']['template']['sections'][0]['label'], (string) $asked);
        }
        // An allowed one is honoured.
        $this->assertNotNull($this->record('compact')['schema']['template']);
    }

    public function testRecordWithoutAssignmentKeepsTemplateOptional(): void
    {
        $this->assertArrayNotHasKey('template', $this->record(null)['schema']);
        $this->assertNotNull($this->record('compact')['schema']['template']);
    }

    // ── setLayouts ────────────────────────────────────────────────────────────

    public function testSetLayoutsSavesReturnsAndClears(): void
    {
        $this->setPrivilege(10);
        $g = $this->group(null, false);

        $res = $this->groupsCtl('setLayouts', ['id' => $g], ['layouts' => ['items' => ['review', 'review', 'compact']]]);
        $this->assertSame('layouts_saved', $res['code']);
        $this->assertSame(['items' => ['review', 'compact']], $this->groupsCtl('getGroup', ['id' => $g])['group']['layouts']);

        $this->groupsCtl('setLayouts', ['id' => $g], ['layouts' => []]);
        $this->assertNull($this->groupsCtl('getGroup', ['id' => $g])['group']['layouts']);
    }

    public function testSetLayoutsValidates(): void
    {
        $this->setPrivilege(10);
        $g = $this->group(null, false);
        $cases = [
            'unknown_table'      => ['nope' => ['review']],
            'template_not_found' => ['items' => ['ghost']],
            'invalid_layouts'    => ['items' => 'review'],
        ];
        foreach ($cases as $code => $layouts) {
            $this->assertSame($code, $this->groupsCtl('setLayouts', ['id' => $g], ['layouts' => $layouts])['code'], $code);
        }
        $this->assertSame('invalid_layouts', $this->groupsCtl('setLayouts', ['id' => $g], ['layouts' => ['a']])['code']);
        $this->assertSame('parameter_missing', $this->groupsCtl('setLayouts', ['id' => $g], [])['code']);
        $this->assertSame('group_not_found', $this->groupsCtl('setLayouts', ['id' => 99999], ['layouts' => []])['code']);
        $this->assertNull($this->groupsCtl('getGroup', ['id' => $g])['group']['layouts']);
    }

    public function testSetLayoutsNeedsAdminAndRefusesSystemGroups(): void
    {
        $g = $this->group(null, false);
        $this->assertSame('not_enough_privilege', $this->groupsCtl('setLayouts', ['id' => $g], ['layouts' => []])['code']);

        $this->setPrivilege(1);
        static::$db->exec("UPDATE bdus_groups SET is_system = 1 WHERE id = {$g}");
        $this->assertSame('system_group', $this->groupsCtl('setLayouts', ['id' => $g], ['layouts' => []])['code']);
    }

    // ── Follow renames and deletions ──────────────────────────────────────────

    public function testRenameAndDeleteFollowInGroups(): void
    {
        $this->setPrivilege(1);
        $g = $this->group(['items' => ['review', 'compact']]);

        Assign::renamed(static::$db, 'items', 'review', 'audit');
        $this->assertSame(['audit', 'compact'], json_decode(static::$db->query("SELECT layouts FROM bdus_groups WHERE id = {$g}", [], 'read')[0]['layouts'], true)['items']);

        Assign::removed(static::$db, 'items', 'audit');
        Assign::removed(static::$db, 'items', 'compact');
        $this->assertNull(static::$db->query("SELECT layouts FROM bdus_groups WHERE id = {$g}", [], 'read')[0]['layouts']);
    }

    public function testTemplatesControllerKeepsGroupsInSync(): void
    {
        $this->setPrivilege(1);
        $g  = $this->group(['items' => ['review', 'compact']]);
        $ctl = fn(string $m, array $get, array $post = []) => $this->callController(
            $this->makeController('Bdus\\Controllers\\Templates', $get, $post), $m
        );
        $layouts = fn() => json_decode(static::$db->query("SELECT layouts FROM bdus_groups WHERE id = {$g}", [], 'read')[0]['layouts'], true)['items'] ?? null;

        $this->assertSame('template_renamed', $ctl('renameTemplate', ['tb' => 'items', 'name' => 'review'], ['new' => 'audit'])['code']);
        $this->assertSame(['audit', 'compact'], $layouts());

        $this->assertSame('template_deleted', $ctl('deleteTemplate', ['tb' => 'items', 'name' => 'audit'])['code']);
        $this->assertSame(['compact'], $layouts());
    }

    public function testMigrationAddsTheColumnIdempotently(): void
    {
        $manage = new \DB\System\Manage(static::$db);
        \DB\System\Migrations\M051_AddLayoutsToGroups::run($manage);
        \DB\System\Migrations\M051_AddLayoutsToGroups::run($manage);
        $this->assertTrue($manage->columnExists('bdus_groups', 'layouts'));
    }
}
