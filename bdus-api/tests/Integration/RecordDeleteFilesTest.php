<?php

namespace Tests\Integration;

use Tests\Support\BdusTestCase;

require_once __DIR__ . '/../Support/UploadShim.php';

/**
 * Deleting a record asks what to do with the files only it uses: keep them (they become orphans:
 * the default, a file is never lost without being asked) or delete them with it. A file shared
 * with a record that stays is never deleted.
 *
 * Records A and B of `items` (made here). Files:
 *   901 only on A      902 on A and B (shared)      903 only on A      904 only on B
 */
class RecordDeleteFilesTest extends BdusTestCase
{
    private int $a;
    private int $b;

    protected function setUp(): void
    {
        $this->setPrivilege(1);
        $this->a = $this->newItem('del A');
        $this->b = $this->newItem('del B');
        foreach ([901, 902, 903, 904] as $id) {
            $uuid = static::insertFile($id, 'png', "f{$id}");
            file_put_contents(PROJ_DIR . "files/{$uuid}.png", "BIN{$id}");
            @mkdir(PROJ_DIR . 'cache/thumb', 0775, true);
            file_put_contents(PROJ_DIR . "cache/thumb/{$uuid}.webp", 'x');
        }
        static::$db->exec("INSERT INTO bdus_file_links (file_id, table_name, record_id, sort) VALUES
            (901, 'items', {$this->a}, 1), (902, 'items', {$this->a}, 2), (902, 'items', {$this->b}, 1),
            (903, 'items', {$this->a}, 3), (904, 'items', {$this->b}, 2)");
        static::$db->exec("INSERT INTO bdus_users (id, name, email, password, privilege) VALUES (73, 'fd', 'fd73@example.com', 'x', 39)");
    }

    protected function tearDown(): void
    {
        foreach ([901, 902, 903, 904] as $id) {
            $uuid = static::fileUuid($id);
            @unlink(PROJ_DIR . "files/{$uuid}.png");
            @unlink(PROJ_DIR . "cache/thumb/{$uuid}.webp");
        }
        static::$db->exec('DELETE FROM bdus_file_links WHERE file_id >= 901');
        static::$db->exec('DELETE FROM bdus_files WHERE id >= 901');
        static::$db->exec("DELETE FROM items WHERE name IN ('del A', 'del B')");
        static::$db->exec('DELETE FROM bdus_user_groups');
        static::$db->exec('DELETE FROM bdus_group_rules');
        static::$db->exec('DELETE FROM bdus_groups');
        static::$db->exec('DELETE FROM bdus_users WHERE id = 73');
        $this->setPrivilege(1);
    }

    private function newItem(string $name): int
    {
        return (int) static::$db->query(
            "INSERT INTO items (creator, name, description, status) VALUES ('test', ?, 'd', 'draft')", [$name], 'id'
        );
    }

    private function erase(array $ids, mixed $files = null): array
    {
        $post = $files === null ? [] : ['files' => $files];
        return $this->callController($this->makeController('Bdus\\Controllers\\Record', ['tb' => 'items', 'id' => $ids], $post), 'erase');
    }

    private function fileRow(int $id): bool
    {
        return (int) static::$db->query('SELECT COUNT(*) AS n FROM bdus_files WHERE id = ?', [$id], 'read')[0]['n'] > 0;
    }

    private function binary(int $id): bool
    {
        return is_file(PROJ_DIR . 'files/' . static::fileUuid($id) . '.png');
    }

    private function links(int $fileId): int
    {
        return (int) static::$db->query('SELECT COUNT(*) AS n FROM bdus_file_links WHERE file_id = ?', [$fileId], 'read')[0]['n'];
    }

    // ── which files the question is about ─────────────────────────────────────

    public function testOnlyFilesUsedByNothingElseAreListed(): void
    {
        $ids = array_column(\Record\Delete::exclusiveFiles(static::$db, 'items', [$this->a]), 'id');
        $this->assertSame([901, 903], $ids, 'the shared file 902 stays with B');
    }

    public function testASharedFileBecomesExclusiveWhenAllItsRecordsGo(): void
    {
        $files = array_column(\Record\Delete::exclusiveFiles(static::$db, 'items', [$this->a, $this->b]), null, 'id');
        $this->assertSame([901, 902, 903, 904], array_keys($files));
        $this->assertSame([$this->a, $this->b], $files[902]['record_ids']);
    }

    public function testNoIdsNoFiles(): void
    {
        $this->assertSame([], \Record\Delete::exclusiveFiles(static::$db, 'items', []));
        $this->assertSame([], \Record\Delete::exclusiveFiles(static::$db, 'items', [0, -0]));
    }

    // ── the default: keep ─────────────────────────────────────────────────────

    public function testWithoutAnAnswerTheFilesAreKeptAsOrphans(): void
    {
        $res = $this->erase([$this->a]);

        $this->assertSame('all_record_deleted', $res['code'], json_encode($res));
        $this->assertSame([901, 903], $res['orphaned_files']);
        $this->assertSame([], $res['deleted_files']);
        foreach ([901, 902, 903] as $id) {
            $this->assertTrue($this->fileRow($id), "file $id");
            $this->assertTrue($this->binary($id), "binary $id");
        }
        $this->assertSame(0, $this->links(901), 'the link went with the record: an orphan');
        $this->assertSame(1, $this->links(902), 'the shared file is still attached to B');
    }

    public function testAnUnknownAnswerMeansKeep(): void
    {
        $res = $this->erase([$this->a], 'burn');
        $this->assertSame([901, 903], $res['orphaned_files']);
        $this->assertTrue($this->fileRow(901));
    }

    // ── delete ────────────────────────────────────────────────────────────────

    public function testDeleteRemovesTheExclusiveFilesTheirBinariesAndVersionsButNotTheSharedOne(): void
    {
        $res = $this->erase([$this->a], 'delete');

        $this->assertSame([901, 903], $res['deleted_files']);
        $this->assertSame([], $res['orphaned_files']);
        foreach ([901, 903] as $id) {
            $this->assertFalse($this->fileRow($id), "row $id");
            $this->assertFalse($this->binary($id), "binary $id");
            $this->assertFileDoesNotExist(PROJ_DIR . 'cache/thumb/' . static::fileUuid($id) . '.webp', "derived $id");
        }
        $this->assertTrue($this->fileRow(902), 'shared with B: untouched');
        $this->assertTrue($this->binary(902));
        $this->assertTrue($this->fileRow(904));
    }

    public function testTheChoiceCanBeMadeFileByFile(): void
    {
        $res = $this->erase([$this->a], [901 => 'delete', 903 => 'keep']);

        $this->assertSame([901], $res['deleted_files']);
        $this->assertSame([903], $res['orphaned_files']);
        $this->assertFalse($this->fileRow(901));
        $this->assertTrue($this->fileRow(903));
        $this->assertTrue($this->binary(903));
    }

    public function testAFileOutsideTheListCannotBeDeletedThroughTheChoice(): void
    {
        // 902 is shared with B; 904 belongs to B only: naming them must not remove them
        $res = $this->erase([$this->a], [902 => 'delete', 904 => 'delete']);

        $this->assertSame('all_record_deleted', $res['code'], json_encode($res));
        $this->assertTrue($this->fileRow(902));
        $this->assertTrue($this->fileRow(904));
        $this->assertTrue($this->binary(902));
        $this->assertTrue($this->binary(904));
    }

    public function testDeletingAllTheRecordsOfASharedFileDeletesIt(): void
    {
        $res = $this->erase([$this->a, $this->b], 'delete');

        $this->assertSame(2, $res['deleted']);
        foreach ([901, 902, 903, 904] as $id) {
            $this->assertFalse($this->fileRow($id), "file $id");
            $this->assertFalse($this->binary($id), "binary $id");
        }
    }

    public function testARecordThatCannotBeDeletedKeepsItsFilesWhateverTheAnswer(): void
    {
        $res = $this->erase([999999], 'delete');

        $this->assertSame('no_record_deleted', $res['code']);
        foreach ([901, 902, 903, 904] as $id) {
            $this->assertTrue($this->fileRow($id));
        }
    }

    // ── the question ──────────────────────────────────────────────────────────

    public function testTheCheckListsWhatWouldBeLeftWithoutRecords(): void
    {
        $res = $this->callController($this->makeController('Bdus\\Controllers\\Record', ['tb' => 'items', 'id' => $this->a]), 'filesBeforeDelete');

        $this->assertSame('success', $res['status'], json_encode($res));
        $this->assertSame([901, 903], array_column($res['files'], 'id'));
        $this->assertSame(static::fileUuid(901), $res['files'][0]['uuid']);
        $this->assertTrue($res['files'][0]['is_image']);
        $this->assertSame([$this->a], $res['files'][0]['record_ids']);
    }

    public function testTheCheckAcceptsSeveralRecords(): void
    {
        $res = $this->callController($this->makeController('Bdus\\Controllers\\Record', ['tb' => 'items', 'id' => [$this->a, $this->b]]), 'filesBeforeDelete');
        $this->assertSame([901, 902, 903, 904], array_column($res['files'], 'id'));
    }

    public function testTheCheckIsRefusedToWhoCannotDeleteTheRecord(): void
    {
        $g = (int) static::$db->query("INSERT INTO bdus_groups (name) VALUES ('G')", [], 'id');
        static::$db->exec("INSERT INTO bdus_group_rules (group_id, table_name, action) VALUES ({$g}, 'items', 'read')");
        static::$db->exec("INSERT INTO bdus_user_groups (user_id, group_id) VALUES (73, {$g})");
        \Auth\CurrentUser::set(['id' => 73, 'name' => 'fd', 'email' => 'fd73@example.com', 'privilege' => 39, 'app' => 'test']);

        $res = $this->callController($this->makeController('Bdus\\Controllers\\Record', ['tb' => 'items', 'id' => $this->a]), 'filesBeforeDelete');

        $this->assertSame('not_enough_privilege', $res['code']);
        $this->assertArrayNotHasKey('files', $res, 'nothing about the files is revealed');
    }

    public function testTheCheckIsRefusedWhenOnlyAnotherTableCanBeEdited(): void
    {
        // Can edit something (so the cheap pre-gate passes), but not delete in `items`
        $g = (int) static::$db->query("INSERT INTO bdus_groups (name) VALUES ('G')", [], 'id');
        static::$db->exec("INSERT INTO bdus_group_rules (group_id, table_name, action) VALUES ({$g}, 'categories', 'update'), ({$g}, 'items', 'read')");
        static::$db->exec("INSERT INTO bdus_user_groups (user_id, group_id) VALUES (73, {$g})");
        \Auth\CurrentUser::set(['id' => 73, 'name' => 'fd', 'email' => 'fd73@example.com', 'privilege' => 39, 'app' => 'test']);

        $res = $this->callController($this->makeController('Bdus\\Controllers\\Record', ['tb' => 'items', 'id' => $this->a]), 'filesBeforeDelete');

        $this->assertSame('not_enough_privilege', $res['code']);
        $this->assertArrayNotHasKey('files', $res);
    }

    public function testWithoutAnyDecisionInTheModelPersistKeepsTheFiles(): void
    {
        // The model of a delete that carries no choice at all (the safe fallback of Persist itself)
        $reader = new \Record\Read($this->a, null, 'items', static::$db, static::$cfg);
        $model  = (new \Record\Edit($reader))->delete()->getModel();
        unset($model['_files_on_delete']);

        $result = \Record\Persist::all($model, static::$db, static::$cfg);

        $this->assertSame([901, 903], $result['core']['orphaned_files']);
        $this->assertTrue($this->fileRow(901), 'kept');
    }

    public function testTheCheckRefusesAnUnknownTableAndMissingParameters(): void
    {
        $this->assertSame('parameter_missing', $this->callController($this->makeController('Bdus\\Controllers\\Record', ['tb' => 'nope', 'id' => 1]), 'filesBeforeDelete')['code']);
        $this->assertSame('parameter_missing', $this->callController($this->makeController('Bdus\\Controllers\\Record', ['tb' => 'items']), 'filesBeforeDelete')['code']);
    }
}
