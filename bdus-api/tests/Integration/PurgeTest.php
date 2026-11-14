<?php

namespace Tests\Integration;

use Record\Purge;
use Tests\Support\BdusTestCase;

/** Retention: records and their history older than N days (#72). */
class PurgeTest extends BdusTestCase
{
    private const NOW = 1_800_000_000; // 2027-01-15

    protected function setUp(): void
    {
        // only what this class creates carries a birth_date or the 'purge-test' marker
        static::$db->query("DELETE FROM bdus_versions WHERE tb IN ('items','tags') AND content LIKE '%purge-test%'", [], 'boolean');
        static::$db->query("DELETE FROM tags WHERE label = 'purge-test'", [], 'boolean');
        static::$db->query("DELETE FROM items WHERE birth_date IS NOT NULL", [], 'boolean');
    }

    private function item(string $date, string $email = 'a@b.it'): int
    {
        $id = (int) static::$db->query(
            "INSERT INTO items (name, email_addr, birth_date) VALUES ('purge-test', ?, ?)", [$email, $date], 'id'
        );
        static::$db->query(
            "INSERT INTO bdus_versions (userid, time, tb, rowid, content, operation) VALUES (1, 1, 'items', ?, ?, 'update')",
            [$id, json_encode(['core' => ['email_addr' => $email, 'name' => 'purge-test']])], 'boolean'
        );
        return $id;
    }

    private function versions(int $id): int
    {
        return (int) static::$db->query("SELECT COUNT(*) AS c FROM bdus_versions WHERE tb = 'items' AND rowid = ?", [$id], 'read')[0]['c'];
    }

    private function exists(int $id): bool
    {
        return !empty(static::$db->query('SELECT 1 FROM items WHERE id = ?', [$id], 'read'));
    }

    public function testDryRunCountsAndWritesNothing(): void
    {
        $old = $this->item('2020-01-01');
        $r   = Purge::run(static::$db, static::$cfg, 'items', 'birth_date', 365, [], false, self::NOW);

        $this->assertSame(1, $r['matched']);
        $this->assertSame(1, $r['versions']);
        $this->assertFalse($r['applied']);
        $this->assertTrue($this->exists($old));
        $this->assertSame(1, $this->versions($old));
    }

    public function testDeleteRemovesOldRecordsAndTheirHistoryOnly(): void
    {
        $old    = $this->item('2020-01-01');
        $recent = $this->item('2026-12-31');
        $none   = (int) static::$db->query("INSERT INTO items (name) VALUES ('purge-test-nodate')", [], 'id');
        static::$db->query("INSERT INTO tags (label, id_link) VALUES ('purge-test', ?)", [$old], 'boolean');

        $r = Purge::run(static::$db, static::$cfg, 'items', 'birth_date', 365, [], true, self::NOW);

        $this->assertTrue($r['applied']);
        $this->assertSame(1, $r['matched']);
        $this->assertFalse($this->exists($old));
        $this->assertSame(0, $this->versions($old));
        $this->assertSame(0, (int) static::$db->query("SELECT COUNT(*) AS c FROM tags WHERE id_link = ?", [$old], 'read')[0]['c'], 'plugin rows go with the record');
        $this->assertTrue($this->exists($recent));
        $this->assertSame(1, $this->versions($recent));
        $this->assertTrue($this->exists($none), 'a record with no date is never matched');

        static::$db->query('DELETE FROM items WHERE id IN (?, ?)', [$recent, $none], 'boolean');
    }

    public function testAnonymizeBlanksFieldsAndPurgesTheSnapshotsWithTheOldValues(): void
    {
        $old = $this->item('2020-01-01', 'secret@example.org');
        $r   = Purge::run(static::$db, static::$cfg, 'items', 'birth_date', 365, ['email_addr'], true, self::NOW);

        $this->assertSame('anonymize', $r['action']);
        $this->assertSame(1, $r['matched']);
        $row = static::$db->query('SELECT name, email_addr, birth_date FROM items WHERE id = ?', [$old], 'read')[0];
        $this->assertNull($row['email_addr']);
        $this->assertSame('purge-test', $row['name'], 'other fields are kept');
        $this->assertSame('2020-01-01', $row['birth_date']);
        $this->assertSame(0, $this->versions($old), 'no snapshot may keep the old value');

        // a second run has nothing left to do
        $again = Purge::run(static::$db, static::$cfg, 'items', 'birth_date', 365, ['email_addr'], true, self::NOW);
        $this->assertSame(0, $again['matched']);
    }

    public function testCutoffIsExactlyNDaysBack(): void
    {
        $this->item('2026-01-14');            // 366 days before 2027-01-15
        $keep = $this->item('2026-01-15');    // exactly 365 days: not before the cutoff
        $r = Purge::run(static::$db, static::$cfg, 'items', 'birth_date', 365, [], false, self::NOW);

        $this->assertSame('2026-01-15', $r['cutoff']);
        $this->assertSame(1, $r['matched']);
        $this->assertTrue($this->exists($keep));
    }

    public function testRefusesWhatItCannotDo(): void
    {
        $bad = [
            ['tags', 'label', 365, []],            // a plugin table
            ['items', 'name', 365, []],            // not a date field
            ['items', 'birth_date', 0, []],        // no age
            ['items', 'birth_date', 365, ['id']],  // structural field
            ['items', 'birth_date', 365, ['birth_date']], // the date it measures by
            ['items', 'birth_date', 365, ['nope']],
            ['bdus_users', 'birth_date', 365, []],
        ];
        foreach ($bad as [$tb, $date, $days, $anon]) {
            try {
                Purge::run(static::$db, static::$cfg, $tb, $date, $days, $anon, true, self::NOW);
                $this->fail("{$tb}.{$date} {$days} " . implode(',', $anon) . ' should be refused');
            } catch (\InvalidArgumentException $e) {
                $this->assertNotSame('', $e->getMessage());
            }
        }
    }
}
