<?php

namespace Tests\Integration;

use Tests\Support\BdusTestCase;
use Record\Read;
use Record\Edit;
use Record\Persist;

/**
 * Integration tests for Record\Persist.
 *
 * Each test reads from / writes to the shared in-memory DB (set up once per
 * class by BdusTestCase::setUpBeforeClass).
 *
 * Tests are ordered so that earlier ones do not destroy state needed by later
 * ones (reads & inserts first, destructive deletes last).
 */
class RecordPersistTest extends BdusTestCase
{
    private const TB     = 'items';
    private const TB_PLG = 'tags';

    // ── Helper: build a Read object for a given item id ───────────────────

    private function makeRead(int $id): Read
    {
        return new Read($id, null, self::TB, static::$db, static::$cfg);
    }

    // ── Core: no changes ─────────────────────────────────────────────────

    public function testPersistCoreNoChangesIsNoop(): void
    {
        $read  = $this->makeRead(2);
        $edit  = new Edit($read);
        // No modifications
        $result = $edit->persist(static::$db, static::$cfg);

        $this->assertSame(0, $result['core']['affected']);

        // Verify DB unchanged
        $rows = static::$db->query("SELECT name FROM items WHERE id = 2", [], 'read');
        $this->assertSame('Beta item', $rows[0]['name']);
    }

    // ── Core: UPDATE ─────────────────────────────────────────────────────

    public function testPersistCoreUpdateChangesFieldInDb(): void
    {
        $read  = $this->makeRead(2);
        $edit  = new Edit($read);
        $edit->setCore(['name' => 'Beta item UPDATED']);
        $result = $edit->persist(static::$db, static::$cfg);

        $this->assertSame(1, $result['core']['affected']);
        $this->assertSame(2, $result['core']['id']);

        $rows = static::$db->query("SELECT name FROM items WHERE id = 2", [], 'read');
        $this->assertSame('Beta item UPDATED', $rows[0]['name']);
    }

    // ── Core: INSERT ─────────────────────────────────────────────────────

    public function testPersistCoreInsertCreatesNewRecord(): void
    {
        // Build a model for a non-existent record (id = null)
        // We do that by reading item 3 and then crafting the model manually
        // to simulate a new record.
        $model = [
            'metadata'    => ['tb_id' => self::TB, 'rec_id' => null, 'tb_stripped' => 'items', 'tb_label' => 'Items'],
            'core'        => [
                'id'     => ['name' => 'id',     'val' => null],
                'creator'=> ['name' => 'creator','val' => null],
                'name'   => ['name' => 'name',   'val' => null, '_val' => 'New Record From Test'],
                'description' => ['name' => 'description', 'val' => null],
                'status' => ['name' => 'status', 'val' => null, '_val' => 'active'],
            ],
            'plugins'     => [],
            'links'       => [],
            'backlinks'   => [],
            'manualLinks' => [],
            'files'       => [],
            'geodata'     => [],
            'rs'          => [],
        ];

        $result = Persist::all($model, static::$db, static::$cfg);

        $this->assertSame(1, $result['core']['affected']);
        $newId = $result['core']['id'];
        $this->assertGreaterThan(5, $newId); // we have 5 seed items

        $rows = static::$db->query("SELECT name FROM items WHERE id = ?", [$newId], 'read');
        $this->assertSame('New Record From Test', $rows[0]['name']);

        // Clean up the inserted row so it does not affect other tests
        static::$db->execInTransaction("DELETE FROM items WHERE id = {$newId}");
    }

    // ── Plugin: INSERT ────────────────────────────────────────────────────

    public function testPersistPluginInsertAddsRow(): void
    {
        $read  = $this->makeRead(3); // item 3 has no tags
        $edit  = new Edit($read);
        $edit->setPluginRow(self::TB_PLG, null, ['label' => 'new-tag-for-3']);
        $result = $edit->persist(static::$db, static::$cfg);

        $this->assertSame(1, $result['plugins']['inserted']);

        $rows = static::$db->query(
            "SELECT label FROM tags WHERE id_link = 3 AND table_link = 'items'",
            [],
            'read'
        );
        $labels = array_column($rows, 'label');
        $this->assertContains('new-tag-for-3', $labels);

        // Clean up
        static::$db->execInTransaction("DELETE FROM tags WHERE id_link = 3 AND label = 'new-tag-for-3'");
    }

    // ── Plugin: UPDATE ────────────────────────────────────────────────────

    public function testPersistPluginUpdateChangesField(): void
    {
        // tag id=1 belongs to item 1
        $read  = $this->makeRead(1);
        $edit  = new Edit($read);
        $edit->setPluginRow(self::TB_PLG, 1, ['label' => 'tag-a-updated']);
        $result = $edit->persist(static::$db, static::$cfg);

        $this->assertSame(1, $result['plugins']['updated']);

        $rows = static::$db->query("SELECT label FROM tags WHERE id = 1", [], 'read');
        $this->assertSame('tag-a-updated', $rows[0]['label']);

        // Restore original value
        static::$db->execInTransaction("UPDATE tags SET label = 'tag-a' WHERE id = 1");
    }

    // ── Plugin: DELETE ────────────────────────────────────────────────────

    public function testPersistPluginDeleteRemovesRow(): void
    {
        // First insert a temporary tag so we can delete it
        static::$db->execInTransaction(
            "INSERT INTO tags (label, id_link, table_link) VALUES ('temp-tag', 4, 'items')"
        );
        $tempId = (int) static::$db->query(
            "SELECT id FROM tags WHERE label = 'temp-tag' AND id_link = 4",
            [],
            'read'
        )[0]['id'];

        $read  = $this->makeRead(4);
        $edit  = new Edit($read);
        $edit->setPluginRow(self::TB_PLG, $tempId, []);
        $result = $edit->persist(static::$db, static::$cfg);

        $this->assertSame(1, $result['plugins']['deleted']);

        $rows = static::$db->query("SELECT id FROM tags WHERE id = ?", [$tempId], 'read');
        $this->assertEmpty($rows);
    }

    // ── ManualLinks: INSERT ───────────────────────────────────────────────

    public function testPersistManualLinkInsertAddsUserlink(): void
    {
        $read  = $this->makeRead(5);
        $edit  = new Edit($read);
        $edit->setManualLink(null, 'items', 3, 1);
        $result = $edit->persist(static::$db, static::$cfg);

        $this->assertSame(1, $result['manualLinks']['inserted']);

        $rows = static::$db->query(
            "SELECT id FROM userlinks WHERE tb_one = 'items' AND id_one = 5 AND tb_two = 'items' AND id_two = 3",
            [],
            'read'
        );
        $this->assertNotEmpty($rows);

        // Clean up
        static::$db->execInTransaction("DELETE FROM userlinks WHERE tb_one = 'items' AND id_one = 5 AND id_two = 3");
    }

    // ── ManualLinks: DELETE ───────────────────────────────────────────────

    public function testPersistManualLinkDeleteRemovesUserlink(): void
    {
        // The seed added a userlink between item 1 and item 2; it is id=3.
        $rows = static::$db->query(
            "SELECT id FROM userlinks WHERE tb_one = 'items' AND id_one = 1 AND tb_two = 'items' AND id_two = 2",
            [],
            'read'
        );
        $this->assertNotEmpty($rows, "Seed userlink between item 1 and item 2 must exist");
        $ulId = (int) $rows[0]['id'];

        $read  = $this->makeRead(1);
        $edit  = new Edit($read);
        $edit->setManualLink($ulId); // delete
        $result = $edit->persist(static::$db, static::$cfg);

        $this->assertSame(1, $result['manualLinks']['deleted']);

        $after = static::$db->query(
            "SELECT id FROM userlinks WHERE id = ?",
            [$ulId],
            'read'
        );
        $this->assertEmpty($after);
    }

    // ── RS: INSERT ────────────────────────────────────────────────────────

    public function testPersistRsInsertAddsRelation(): void
    {
        // Use item 3 so there is no existing RS conflict
        $read  = $this->makeRead(3);
        $edit  = new Edit($read);
        $edit->setRs(null, 'C', 'D', '2');
        $result = $edit->persist(static::$db, static::$cfg);

        $this->assertSame(1, $result['rs']['inserted']);

        $rows = static::$db->query(
            "SELECT id FROM rs WHERE tb = 'items' AND first = 'C' AND second = 'D'",
            [],
            'read'
        );
        $this->assertNotEmpty($rows);

        // Clean up
        static::$db->execInTransaction("DELETE FROM rs WHERE first = 'C' AND second = 'D'");
    }

    // ── RS: DELETE ────────────────────────────────────────────────────────

    public function testPersistRsDeleteRemovesRelation(): void
    {
        // The seed inserted an RS row (first='1', second='2') that Read::getRs() will
        // find when reading item id=1 (because first='1' matches $this->id=1).
        $rows = static::$db->query(
            "SELECT id FROM rs WHERE tb = 'items' AND first = '1' AND second = '2'",
            [],
            'read'
        );
        $this->assertNotEmpty($rows, "Seed RS row must exist");
        $rsId = (int) $rows[0]['id'];

        // Read item 1 — its model['rs'] should contain the row (first or second = 1)
        $read  = $this->makeRead(1);
        $edit  = new Edit($read);
        $edit->setRs($rsId); // delete
        $result = $edit->persist(static::$db, static::$cfg);

        $this->assertSame(1, $result['rs']['deleted']);

        $after = static::$db->query(
            "SELECT id FROM rs WHERE id = ?",
            [$rsId],
            'read'
        );
        $this->assertEmpty($after);
    }

    // ── Core DELETE (cascade) ─────────────────────────────────────────────

    public function testPersistCoreDeleteRemovesRecordAndPlugins(): void
    {
        // Insert a temporary item with a tag for clean cascade deletion test
        static::$db->execInTransaction(
            "INSERT INTO items (creator, name, description, status) VALUES ('admin', 'TempItem', 'to delete', 'active')"
        );
        $tempItemId = (int) static::$db->query(
            "SELECT id FROM items WHERE name = 'TempItem'",
            [],
            'read'
        )[0]['id'];

        static::$db->execInTransaction(
            "INSERT INTO tags (label, id_link, table_link) VALUES ('temp-tag-del', {$tempItemId}, 'items')"
        );
        static::$db->execInTransaction(
            "INSERT INTO userlinks (tb_one, id_one, tb_two, id_two, sort) VALUES ('items', {$tempItemId}, 'items', 2, 1)"
        );

        // Now read and delete via Edit
        $read  = new Read($tempItemId, null, self::TB, static::$db, static::$cfg);
        $edit  = new Edit($read);
        $edit->delete();
        $result = $edit->persist(static::$db, static::$cfg);

        $this->assertSame(1, $result['core']['deleted']);

        // Verify item gone
        $itemRows = static::$db->query(
            "SELECT id FROM items WHERE id = ?",
            [$tempItemId],
            'read'
        );
        $this->assertEmpty($itemRows, "Item should have been deleted");

        // Verify tags gone
        $tagRows = static::$db->query(
            "SELECT id FROM tags WHERE id_link = ? AND table_link = 'items'",
            [$tempItemId],
            'read'
        );
        $this->assertEmpty($tagRows, "Plugin tags should have been deleted");

        // Verify userlinks gone
        $ulRows = static::$db->query(
            "SELECT id FROM userlinks WHERE (tb_one = 'items' AND id_one = ?) OR (tb_two = 'items' AND id_two = ?)",
            [$tempItemId, $tempItemId],
            'read'
        );
        $this->assertEmpty($ulRows, "Userlinks should have been deleted");
    }
}
