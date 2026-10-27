<?php

namespace Tests\Integration;

use Tests\Support\BdusTestCase;

/**
 * Endpoints that describe a table (its search configuration, templates, import
 * fields) or list shared work (charts, saved queries) answer only to someone who
 * can read a table — and, for the table ones, that table (#78). Some of them
 * checked nothing before.
 */
class AccessMetadataTest extends BdusTestCase
{
    protected function setUp(): void
    {
        $g = (int) static::$db->query("INSERT INTO bdus_groups (name) VALUES ('G')", [], 'id');
        static::$db->exec("INSERT INTO bdus_group_rules (group_id, table_name, action) VALUES ({$g}, 'items', 'read')");
        static::$db->exec("INSERT INTO bdus_users (id, name, email, password, privilege) VALUES (54, 'u', 'u54@example.com', 'x', 39)");
        static::$db->exec("INSERT INTO bdus_user_groups (user_id, group_id) VALUES (54, {$g})");
        $this->actAs(39);
    }

    protected function tearDown(): void
    {
        static::$db->exec('DELETE FROM bdus_user_groups');
        static::$db->exec('DELETE FROM bdus_group_rules');
        static::$db->exec('DELETE FROM bdus_groups');
        static::$db->exec('DELETE FROM bdus_users WHERE id = 54');
        $this->setPrivilege(1);
    }

    private function actAs(int $tier): void
    {
        \Auth\CurrentUser::set(['id' => 54, 'name' => 'u', 'email' => 'u54@example.com', 'privilege' => $tier, 'app' => 'test']);
    }

    private function call(string $class, string $method, array $get = []): array
    {
        return $this->callController($this->makeController("Bdus\\Controllers\\{$class}", $get), $method);
    }

    public function testTableMetadataNeedsReadAccessToThatTable(): void
    {
        foreach ([['Search', 'getAdvancedConfig'], ['Record', 'getTemplates'], ['Import', 'getTableFields']] as [$class, $method]) {
            $this->assertSame('success', $this->call($class, $method, ['tb' => 'items'])['status'], "$class::$method on a readable table");
            $this->assertSame('not_enough_privilege', $this->call($class, $method, ['tb' => 'tags'])['code'], "$class::$method on another table");
        }
    }

    public function testNobodyWithoutAnyAccessGetsTableMetadata(): void
    {
        $this->actAs(40);

        foreach ([['Search', 'getAdvancedConfig'], ['Record', 'getTemplates'], ['Import', 'getTableFields']] as [$class, $method]) {
            $this->assertSame('not_enough_privilege', $this->call($class, $method, ['tb' => 'items'])['code'], "$class::$method");
        }
    }

    public function testListsOfSharedWorkNeedReadAccessSomewhere(): void
    {
        $this->assertSame('success', $this->call('Chart', 'listCharts')['status']);
        $this->assertSame('success', $this->call('SavedQueries', 'listQueries')['status']);

        $this->actAs(40);
        $this->assertSame('not_enough_privilege', $this->call('Chart', 'listCharts')['code']);
        $this->assertSame('not_enough_privilege', $this->call('SavedQueries', 'listQueries')['code']);
    }

    public function testNothingChangesForPeopleWhoReadEverything(): void
    {
        foreach ([1, 10, 20, 30] as $tier) {
            $this->actAs($tier);
            $this->assertSame('success', $this->call('Search', 'getAdvancedConfig', ['tb' => 'tags'])['status'], "tier $tier");
            $this->assertSame('success', $this->call('Chart', 'listCharts')['status'], "tier $tier");
        }
    }
}
