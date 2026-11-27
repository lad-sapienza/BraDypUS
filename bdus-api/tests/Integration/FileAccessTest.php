<?php

namespace Tests\Integration;

use Tests\Support\BdusTestCase;

/**
 * Auth\FileAccess: who may read and change which file. A file is reached directly (a rule on
 * `bdus_files`) or through the data (attached to records); reading needs either, changing
 * needs the direct right or ALL the attached records to be changeable. Orphans: direct only.
 *
 * Files (all created here):
 *   701  attached to items 1
 *   702  attached to items 1 and categories 1
 *   703  attached to categories 1 only        (keywords 'pub')
 *   704  orphan
 *   705  attached to items 2
 */
class FileAccessTest extends BdusTestCase
{
    private const ALL = [701, 702, 703, 704, 705];

    protected function setUp(): void
    {
        foreach (self::ALL as $id) {
            static::insertFile($id, 'png', "f{$id}");
        }
        static::$db->exec("UPDATE bdus_files SET keywords = 'pub' WHERE id = 703");
        static::$db->exec("INSERT INTO bdus_file_links (file_id, table_name, record_id, sort) VALUES
            (701, 'items', 1, 1), (702, 'items', 1, 2), (702, 'categories', 1, 1),
            (703, 'categories', 1, 2), (705, 'items', 2, 1)");
        static::$db->exec("INSERT INTO bdus_users (id, name, email, password, privilege) VALUES (71, 'fa', 'fa71@example.com', 'x', 39)");
    }

    protected function tearDown(): void
    {
        static::$db->exec('DELETE FROM bdus_file_links WHERE file_id >= 701');
        static::$db->exec('DELETE FROM bdus_files WHERE id >= 701');
        static::$db->exec('DELETE FROM bdus_table_locks');
        static::$db->exec('DELETE FROM bdus_user_groups');
        static::$db->exec('DELETE FROM bdus_group_rules');
        static::$db->exec('DELETE FROM bdus_groups');
        static::$db->exec('DELETE FROM bdus_users WHERE id = 71');
        $this->setPrivilege(1);
    }

    /** Acts as a user at $tier; $rules = [[table, action, filter|null], …] in one group. */
    private function as(int $tier, array $rules = []): void
    {
        if ($rules) {
            $g = (int) static::$db->query("INSERT INTO bdus_groups (name) VALUES ('G')", [], 'id');
            foreach ($rules as [$table, $action, $filter]) {
                static::$db->query(
                    'INSERT INTO bdus_group_rules (group_id, table_name, action, filter) VALUES (?, ?, ?, ?)',
                    [$g, $table, $action, $filter === null ? null : json_encode($filter)],
                    'boolean'
                );
            }
            static::$db->exec("INSERT INTO bdus_user_groups (user_id, group_id) VALUES (71, {$g})");
        }
        \Auth\CurrentUser::set(['id' => 71, 'name' => 'fa', 'email' => 'fa71@example.com', 'privilege' => $tier, 'app' => 'test']);
    }

    /** The FileAccess a controller of this request would use (same wiring as production). */
    private function fa(): \Auth\FileAccess
    {
        $ctrl = $this->makeController('Bdus\\Controllers\\File');
        return (new \ReflectionMethod($ctrl, 'fileAccess'))->invoke($ctrl);
    }

    private function readable(): array
    {
        $fa = $this->fa();
        return array_values(array_filter(self::ALL, fn($id) => $fa->mayRead($id)));
    }

    private function changeable(string $action): array
    {
        $fa = $this->fa();
        return array_values(array_filter(self::ALL, fn($id) => $fa->mayChange($action, $id)));
    }

    // ── tiers ─────────────────────────────────────────────────────────────────

    public function testAdminDoesEverything(): void
    {
        foreach ([1, 10] as $tier) {
            $this->as($tier);
            $this->assertSame(self::ALL, $this->readable(), "tier $tier read");
            $this->assertSame(self::ALL, $this->changeable('update'), "tier $tier update");
            $this->assertSame(self::ALL, $this->changeable('delete'), "tier $tier delete");
        }
    }

    public function testReaderReadsEverythingOrphansIncludedAndChangesNothing(): void
    {
        $this->as(30);
        $this->assertSame(self::ALL, $this->readable());
        $this->assertSame([], $this->changeable('update'));
        $this->assertSame([], $this->changeable('delete'));
        $this->assertFalse($this->fa()->mayCreate());
    }

    public function testWriterChangesEveryFileOrphansIncluded(): void
    {
        $this->as(20);
        $this->assertSame(self::ALL, $this->readable());
        $this->assertSame(self::ALL, $this->changeable('update'));
        $this->assertSame(self::ALL, $this->changeable('delete'));
        $this->assertTrue($this->fa()->mayCreate());
    }

    public function testEnterWithoutAGroupSeesAndChangesNothing(): void
    {
        $this->as(39);
        $this->assertSame([], $this->readable());
        $this->assertSame([], $this->changeable('update'));
        $this->assertSame([], $this->changeable('delete'));
        $this->assertFalse($this->fa()->mayCreate());
    }

    // ── through the data ──────────────────────────────────────────────────────

    public function testReadingARecordOpensItsFilesButNeverOrphans(): void
    {
        $this->as(39, [['items', 'read', null]]);
        $this->assertSame([701, 702, 705], $this->readable(), 'files attached to a readable record, not categories-only, not orphan');
        $this->assertSame([], $this->changeable('update'), 'reading opens nothing for writing');
    }

    public function testReadingMoreTablesOpensMoreFiles(): void
    {
        $this->as(39, [['items', 'read', null], ['categories', 'read', null]]);
        $this->assertSame([701, 702, 703, 705], $this->readable());
    }

    public function testRowScopeOfTheRecordLimitsItsFiles(): void
    {
        $this->as(39, [['items', 'read', ['id' => ['_eq' => 1]]]]);
        $this->assertSame([701, 702], $this->readable(), 'file 705 is on items 2, outside the row scope');
    }

    public function testInheritedWriteNeedsEveryAttachedRecordToBeChangeable(): void
    {
        $this->as(39, [['items', 'update', null]]);
        $this->assertSame([701, 705], $this->changeable('update'), '702 is also on categories, which this group cannot change');
        $this->assertSame([701, 705], $this->changeable('delete'), 'deleting is judged the same way');
    }

    public function testInheritedWriteNeverReachesOrphans(): void
    {
        $this->as(39, [['items', 'update', null], ['categories', 'update', null]]);
        $this->assertSame([701, 702, 703, 705], $this->changeable('update'));
        $this->assertNotContains(704, $this->changeable('delete'));
    }

    public function testReadableLinksHideWhatTheFileIsAttachedToBeyondWhatMayBeRead(): void
    {
        $this->as(39, [['items', 'read', ['id' => ['_eq' => 1]]]]);
        $links = [
            ['table_name' => 'items', 'record_id' => 1],
            ['table_name' => 'categories', 'record_id' => 1],
            ['table_name' => 'items', 'record_id' => 2],
        ];
        $this->assertSame([['table_name' => 'items', 'record_id' => 1]], $this->fa()->readableLinks($links));
    }

    // ── directly ──────────────────────────────────────────────────────────────

    public function testAReadRuleOnFilesOpensEveryFileOrphansIncluded(): void
    {
        $this->as(39, [['bdus_files', 'read', null]]);
        $this->assertSame(self::ALL, $this->readable());
        $this->assertSame([], $this->changeable('update'));
    }

    public function testAFilteredRuleOnFilesOpensOnlyThoseFiles(): void
    {
        $this->as(39, [['bdus_files', 'read', ['keywords' => ['_eq' => 'pub']]]]);
        $this->assertSame([703], $this->readable(), 'only the file matching the filter, wherever it is attached');
    }

    public function testDirectAndInheritedReadAddUp(): void
    {
        $this->as(39, [['bdus_files', 'read', ['keywords' => ['_eq' => 'pub']]], ['items', 'read', null]]);
        $this->assertSame([701, 702, 703, 705], $this->readable());
    }

    public function testAnUpdateRuleOnFilesChangesAnyFileButDoesNotDeleteNorRead(): void
    {
        $this->as(39, [['bdus_files', 'update', null]]);
        $this->assertSame(self::ALL, $this->changeable('update'), 'orphans too');
        $this->assertSame([], $this->changeable('delete'));
        $this->assertSame([], $this->readable());
    }

    public function testADeleteRuleOnFilesDeletesButDoesNotUpdate(): void
    {
        $this->as(39, [['bdus_files', 'delete', null]]);
        $this->assertSame(self::ALL, $this->changeable('delete'));
        $this->assertSame([], $this->changeable('update'));
    }

    public function testRulesOnAllTablesIncludeFiles(): void
    {
        $this->as(39, [['*', 'read', null]]);
        $this->assertSame(self::ALL, $this->readable(), 'orphans included: `*` covers bdus_files itself');

        \Auth\CurrentUser::set(['id' => 71, 'name' => 'fa', 'email' => 'x', 'privilege' => 1, 'app' => 'test']);
        static::$db->exec('DELETE FROM bdus_user_groups');
        static::$db->exec('DELETE FROM bdus_group_rules');
        static::$db->exec('DELETE FROM bdus_groups');
        $this->as(39, [['*', 'update', null]]);
        $this->assertSame(self::ALL, $this->changeable('update'));
    }

    public function testACreateRuleOnFilesAllowsStandaloneUpload(): void
    {
        $this->as(39, [['bdus_files', 'create', null]]);
        $this->assertTrue($this->fa()->mayCreate());
    }

    public function testACreateRuleOnAnotherTableDoesNot(): void
    {
        $this->as(39, [['items', 'create', null]]);
        $this->assertFalse($this->fa()->mayCreate());
    }

    // ── locks ─────────────────────────────────────────────────────────────────

    public function testALockOnAnAttachedRecordFreezesTheFileWhateverTheRights(): void
    {
        static::$db->exec("INSERT INTO bdus_table_locks (table_name, filter) VALUES ('items', '{\"id\":{\"_eq\":1}}')");

        $this->as(20);
        $this->assertSame([703, 704, 705], $this->changeable('update'), '701 and 702 are attached to the locked items 1');
        $this->assertSame([703, 704, 705], $this->changeable('delete'));

        $this->as(1);
        $this->assertSame(self::ALL, $this->changeable('update'), 'administrators are never locked');
    }

    public function testALockOnFilesThemselvesApplies(): void
    {
        static::$db->exec("INSERT INTO bdus_table_locks (table_name, filter) VALUES ('bdus_files', '{\"keywords\":{\"_eq\":\"pub\"}}')");
        $this->as(39, [['bdus_files', 'update', null]]);
        $this->assertSame([701, 702, 704, 705], $this->changeable('update'), '703 is locked');
    }

    public function testAMissingFileIsNeverChangeable(): void
    {
        $this->as(1);
        $this->assertFalse($this->fa()->mayChange('update', 999999));
    }

    public function testAnUnknownActionIsRefused(): void
    {
        $this->as(1);
        $this->assertFalse($this->fa()->mayChange('create', 701));
    }
}
