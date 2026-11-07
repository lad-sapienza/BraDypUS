<?php

namespace Tests\Integration;

use DB\System\Manage;
use DB\System\Migrations\M050_AddDefaultGroups;
use Tests\Support\BdusTestCase;

/**
 * Differential test of the system groups against the levels they replace.
 *
 * Every scenario runs twice from the same database state, through the real
 * controllers: once as user 52 at the level (30 Reader, 25 Self writer,
 * 20 Writer), once as the same user at level Enter in the system group of the
 * same name. The outcome (status and code, or the ids a list returns) and the
 * resulting rows of every data table must be identical. A scenario also says
 * what the level is declared to do, so a pair that agreed on the wrong thing
 * would still fail.
 *
 * (Until 5.19 level 25 differed from its group in 12 operations: it let a self
 * writer add plugin rows and geometries to other people's records, and refused
 * them the dependants of their own. The level was fixed; this test keeps them
 * together.)
 *
 * Items 1 and 3 were created by user 52 (their own); 2, 4 and 5 by 'admin'.
 * Item 1 has two tags (plugin rows), files, an RS relation and a manual link,
 * both to item 2. Item 2 has a tag and a file link of its own.
 */
class LevelGroupParityTest extends BdusTestCase
{
    private const TABLES = [
        'items', 'tags', 'categories', 'reviews', 'bdus_userlinks', 'bdus_rs',
        'bdus_file_links', 'bdus_files', 'bdus_geodata',
    ];

    /** @var array<string, array> */
    private array $baseline = [];
    private int $linkOfOwn = 0;
    private int $linkOfOther = 0;
    private int $tagOfOther = 0;
    private int $tagOfOwn = 0;
    private int $rsOwn = 0;
    private int $linkSeeded = 0;

    protected function setUp(): void
    {
        static::$db->exec("INSERT INTO bdus_users (id, name, email, password, privilege) VALUES (52, 'p', 'p@example.com', 'x', 20)");
        static::$db->exec("UPDATE items SET creator = CASE WHEN id IN (1, 3) THEN '52' ELSE 'admin' END");
        static::$db->exec("INSERT INTO tags (label, id_link) VALUES ('tag-of-other', 2)");
        static::$db->exec("INSERT INTO bdus_file_links (file_id, table_name, record_id, sort) VALUES (2, 'items', 2, 1)");
        static::$db->exec("INSERT INTO bdus_geodata (id, table_link, id_link, geometry) VALUES (901, 'items', 1, 'POINT(1 1)'), (902, 'items', 2, 'POINT(2 2)')");

        $q = fn(string $sql) => (int) static::$db->query($sql, [], 'read')[0]['id'];
        $this->tagOfOther  = $q("SELECT id FROM tags WHERE label = 'tag-of-other'");
        $this->tagOfOwn    = $q("SELECT id FROM tags WHERE label = 'tag-a'");
        $this->linkOfOwn   = $q('SELECT id FROM bdus_file_links WHERE record_id = 1 AND file_id = 1');
        $this->linkOfOther = $q('SELECT id FROM bdus_file_links WHERE record_id = 2 AND file_id = 2');
        $this->rsOwn       = $q('SELECT id FROM bdus_rs WHERE first = 1 AND second = 2');
        $this->linkSeeded  = $q('SELECT id FROM bdus_userlinks WHERE id_one = 1 AND id_two = 2');

        M050_AddDefaultGroups::run(new Manage(static::$db));
        $this->baseline = $this->snapshot();
    }

    protected function tearDown(): void
    {
        $this->restore($this->baseline);
        // what setUp added on top of the shared fixtures
        static::$db->exec("DELETE FROM tags WHERE label = 'tag-of-other'");
        static::$db->exec('DELETE FROM bdus_file_links WHERE file_id = 2 AND record_id = 2');
        static::$db->exec('DELETE FROM bdus_geodata WHERE id IN (901, 902)');
        static::$db->exec('DELETE FROM bdus_user_groups');
        static::$db->exec('DELETE FROM bdus_group_rules');
        static::$db->exec('DELETE FROM bdus_groups');
        static::$db->exec('DELETE FROM bdus_users WHERE id = 52');
        static::$db->exec("UPDATE items SET creator = 'admin'");
        $this->setPrivilege(1);
    }

    // ── Harness ───────────────────────────────────────────────────────────────

    private function snapshot(): array
    {
        $out = [];
        foreach (self::TABLES as $t) {
            $out[$t] = static::$db->query("SELECT * FROM {$t} ORDER BY id", [], 'read') ?: [];
        }
        return $out;
    }

    private function restore(array $snap): void
    {
        // Rows go back with their ids, in any order: foreign keys are checked again afterwards.
        static::$db->exec('PRAGMA foreign_keys = OFF');
        try {
            foreach (array_reverse(self::TABLES) as $t) {
                static::$db->exec("DELETE FROM {$t}");
            }
            foreach (self::TABLES as $t) {
                foreach ($snap[$t] as $row) {
                    $cols = implode(', ', array_keys($row));
                    $marks = implode(', ', array_fill(0, count($row), '?'));
                    static::$db->query("INSERT INTO {$t} ({$cols}) VALUES ({$marks})", array_values($row), 'boolean');
                }
            }
        } finally {
            static::$db->exec('PRAGMA foreign_keys = ON');
        }
    }

    /** Rows of every data table without their ids (new rows get different ids in each run). */
    private function state(): array
    {
        $out = [];
        foreach ($this->snapshot() as $t => $rows) {
            $rows = array_map(function ($r) {
                unset($r['id']);
                return $r;
            }, $rows);
            usort($rows, fn($a, $b) => strcmp(json_encode($a), json_encode($b)));
            $out[$t] = $rows;
        }
        return $out;
    }

    private function actAs(int $tier): void
    {
        \Auth\CurrentUser::set(['id' => 52, 'name' => 'p', 'email' => 'p@example.com', 'privilege' => $tier, 'app' => 'test']);
    }

    /** The user as a level alone (no groups), or at Enter in a system group. */
    private function principal(int $level, ?string $group): void
    {
        static::$db->exec('DELETE FROM bdus_user_groups');
        if ($group === null) {
            $this->actAs($level);
            return;
        }
        $id = (int) static::$db->query('SELECT id FROM bdus_groups WHERE name = ?', [$group], 'read')[0]['id'];
        static::$db->exec("INSERT INTO bdus_user_groups (user_id, group_id) VALUES (52, {$id})");
        $this->actAs(39);
    }

    private function call(string $class, string $method, array $get = [], array $post = []): array
    {
        return $this->callController($this->makeController("Bdus\\Controllers\\{$class}", $get, $post), $method);
    }

    /** What two runs must agree on: status and code, plus the ids of a list. */
    private function outcome(array $res): array
    {
        $o = ['status' => $res['status'] ?? null, 'code' => $res['code'] ?? null];
        if (isset($res['data']) && is_array($res['data'])) {
            $o['ids'] = array_map(fn($r) => (int) ($r['id'] ?? 0), $res['data']);
        }
        if (isset($res['core']['id'])) {
            $o['visible'] = true;
        }
        return $o;
    }

    private function plant(string $csv): string
    {
        $id = bin2hex(random_bytes(8));
        file_put_contents(sys_get_temp_dir() . '/bdus_import_' . $id, $csv);
        return $id;
    }

    /**
     * @return array<string, array{0: callable, 1: array{30: bool, 25: bool, 20: bool}}>
     *   name => [scenario(callable $call), whether the level succeeds: [Reader, Self writer, Writer]]
     */
    private function scenarios(): array
    {
        $c = fn(string $class, string $m, array $get = [], array $post = []) => $this->call($class, $m, $get, $post);
        $all = [30 => true, 25 => true, 20 => true];
        $own = [30 => false, 25 => true, 20 => true];        // self writer: only what is theirs
        $writers = [30 => false, 25 => false, 20 => true];   // writer only
        $none = [30 => false, 25 => false, 20 => false];

        return [
            // ── Reading
            'list items'             => [fn() => $c('Record', 'getRecords', ['tb' => 'items']), $all],
            'read own record'        => [fn() => $c('Record', 'getRecord', ['tb' => 'items', 'id' => 1]), $all],
            'read other record'      => [fn() => $c('Record', 'getRecord', ['tb' => 'items', 'id' => 2]), $all],

            // ── Create / duplicate
            'create'                 => [fn() => $c('Record', 'saveRecord', [], ['tb' => 'items', 'core' => ['name' => 'brand new', 'status' => 'draft']]), $own],
            'duplicate own'          => [fn() => $c('Record', 'duplicateRecord', ['tb' => 'items', 'id' => 1]), $own],
            'duplicate other'        => [fn() => $c('Record', 'duplicateRecord', ['tb' => 'items', 'id' => 2]), $own],

            // ── Update / erase
            'update own'             => [fn() => $c('Record', 'saveRecord', [], ['tb' => 'items', 'id' => 1, 'core' => ['description' => 'changed']]), $own],
            'update other'           => [fn() => $c('Record', 'saveRecord', [], ['tb' => 'items', 'id' => 2, 'core' => ['description' => 'changed']]), $writers],
            'erase own'              => [fn() => $c('Record', 'erase', ['tb' => 'items', 'id' => 3]), $own],
            'erase other'            => [fn() => $c('Record', 'erase', ['tb' => 'items', 'id' => 4]), $writers],

            // ── Plugin rows follow their host record
            'add plugin row, own host'    => [fn() => $c('Record', 'saveRecord', [], ['tb' => 'tags', 'core' => ['label' => 'new-1', 'id_link' => 1]]), $own],
            'add plugin row, other host'  => [fn() => $c('Record', 'saveRecord', [], ['tb' => 'tags', 'core' => ['label' => 'new-2', 'id_link' => 2]]), $writers],
            'edit plugin row, own host'   => [fn() => $c('Record', 'saveRecord', [], ['tb' => 'tags', 'id' => $this->tagOfOwn, 'core' => ['label' => 'edited']]), $own],
            'edit plugin row, other host' => [fn() => $c('Record', 'saveRecord', [], ['tb' => 'tags', 'id' => $this->tagOfOther, 'core' => ['label' => 'edited']]), $writers],
            'erase plugins, own host'     => [fn() => $c('Record', 'erasePlugins', ['tb' => 'items', 'id' => 1]), $own],
            'erase plugins, other host'   => [fn() => $c('Record', 'erasePlugins', ['tb' => 'items', 'id' => 2]), $writers],

            // ── Stratigraphic relations and manual links need both ends
            'rs between own records'      => [fn() => $c('Record', 'addRs', [], ['tb' => 'items', 'first' => 1, 'relation' => 5, 'second' => 3]), $own],
            'rs with another record'      => [fn() => $c('Record', 'addRs', [], ['tb' => 'items', 'first' => 1, 'relation' => 5, 'second' => 4]), $writers],
            'delete rs to another record' => [fn() => $c('Record', 'deleteRs', [], ['id' => $this->rsOwn]), $writers],
            'link between own records'    => [fn() => $c('Record', 'addManualLink', [], ['tb_one' => 'items', 'id_one' => 1, 'tb_two' => 'items', 'id_two' => 3]), $own],
            'link with another record'    => [fn() => $c('Record', 'addManualLink', [], ['tb_one' => 'items', 'id_one' => 1, 'tb_two' => 'items', 'id_two' => 4]), $writers],
            'delete link to another'      => [fn() => $c('Record', 'deleteManualLink', [], ['id' => $this->linkSeeded]), $writers],

            // ── Files
            'attach file to own record'   => [fn() => $c('Record', 'linkFile', ['tb' => 'items', 'id' => 3], ['fileId' => 1]), $own],
            'attach file to other record' => [fn() => $c('Record', 'linkFile', ['tb' => 'items', 'id' => 4], ['fileId' => 1]), $writers],
            'detach file, own record'     => [fn() => $c('Record', 'unlinkFile', ['linkId' => $this->linkOfOwn]), $own],
            'detach file, other record'   => [fn() => $c('Record', 'unlinkFile', ['linkId' => $this->linkOfOther]), $writers],
            'delete file linked to own record only' => [fn() => $c('Record', 'deleteFile', [], ['fileId' => 1]), $own],
            'delete file also linked to another'    => [fn() => $c('Record', 'deleteFile', [], ['fileId' => 2]), $writers],

            // ── Geometries
            'add geometry, own record'     => [fn() => $c('Geoface', 'saveNew', [], ['tb' => 'items', 'id' => 3, 'geometry' => 'POINT(3 3)']), $own],
            'add geometry, other record'   => [fn() => $c('Geoface', 'saveNew', [], ['tb' => 'items', 'id' => 4, 'geometry' => 'POINT(4 4)']), $writers],
            'move geometry, own record'    => [fn() => $c('Geoface', 'updateGeometry', [], ['geodata' => [['id' => 901, 'geometry' => 'POINT(5 5)']]]), $own],
            'move geometry, other record'  => [fn() => $c('Geoface', 'updateGeometry', [], ['geodata' => [['id' => 902, 'geometry' => 'POINT(6 6)']]]), $writers],
            'erase geometry, own record'   => [fn() => $c('Geoface', 'eraseGeometry', [], ['ids' => [901]]), $own],
            'erase geometry, other record' => [fn() => $c('Geoface', 'eraseGeometry', [], ['ids' => [902]]), $writers],

            // ── Import (the whole import is refused when one row is not allowed)
            'import new rows'              => [fn() => $this->import("name\nimported-a\nimported-b\n"), $own],
            'import over own record'       => [fn() => $this->import("name,description\nAlpha item,from import\n"), $own],
            'import over another record'   => [fn() => $this->import("name,description\nBeta item,from import\n"), $writers],
        ];
    }

    private function import(string $csv): array
    {
        $cols = array_map('trim', explode(',', strtok($csv, "\n")));
        return $this->call('Import', 'importData', [], [
            'temp_id' => $this->plant($csv), 'type' => 'csv', 'tb' => 'items',
            'mapping' => array_combine($cols, $cols), 'key_field' => 'name',
        ]);
    }

    private function parity(int $level, string $group): void
    {
        $problems = [];
        $ran = 0;
        foreach ($this->scenarios() as $name => [$run, $expected]) {
            $this->restore($this->baseline);

            $this->principal($level, null);
            $byLevel = $this->outcome($run());
            $levelState = $this->state();

            $this->restore($this->baseline);

            $this->principal($level, $group);
            $byGroup = $this->outcome($run());
            $groupState = $this->state();

            $this->restore($this->baseline);
            $ran++;

            if ($byLevel !== $byGroup) {
                $problems[] = "{$name}: level " . json_encode($byLevel) . ' ≠ group ' . json_encode($byGroup);
            } elseif ($levelState !== $groupState) {
                $problems[] = "{$name}: same answer, different rows in the database";
            }
            $declared = $expected[$level];
            $ok = ($byGroup['status'] ?? null) === 'success';
            if ($declared !== $ok) {
                $problems[] = "{$name}: {$group} " . ($declared ? 'should succeed' : 'should be refused') . ', got ' . json_encode($byGroup);
            }
        }

        $this->assertGreaterThan(30, $ran);
        $this->assertSame([], $problems, implode("\n", $problems));
    }

    public function testReaderGroupDoesWhatLevelReaderDoes(): void
    {
        $this->parity(30, 'Reader');
    }

    public function testSelfWriterGroupDoesWhatLevelSelfWriterDoes(): void
    {
        $this->parity(25, 'Self writer');
    }

    public function testWriterGroupDoesWhatLevelWriterDoes(): void
    {
        $this->parity(20, 'Writer');
    }

    public function testTheHarnessNoticesAGroupThatDiffersFromItsLevel(): void
    {
        // Not vacuous: let the Writer group delete nothing and the comparison must complain.
        $writer = (int) static::$db->query("SELECT id FROM bdus_groups WHERE name = 'Writer'", [], 'read')[0]['id'];
        static::$db->query("DELETE FROM bdus_group_rules WHERE group_id = ? AND action = 'delete'", [$writer], 'boolean');

        $this->expectException(\PHPUnit\Framework\AssertionFailedError::class);
        $this->parity(20, 'Writer');
    }
}
