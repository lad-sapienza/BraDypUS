<?php

/**
 * @copyright 2007-2026 Julian Bogdani
 * @license AGPL-3.0; see LICENSE
 */

namespace Record;

use DB\DBInterface;
use Config\Config;
use Auth\Access;
use Auth\WriteGate;

/**
 * Deletes one record with everything that hangs off it (plugin rows, links,
 * stratigraphic relations; a file used only by it is deleted or left as an orphan, as the
 * caller chooses — see Record\Persist), after checking that the
 * principal may: tier or group rule, ownership for a self writer, row scope,
 * locks. The record is snapshotted first, so it can be restored.
 *
 * Request-independent: the delete endpoint and the MCP tool share it.
 */
class Delete
{
    public const DELETED       = 'deleted';
    public const NOT_FOUND     = 'not_found';
    public const NOT_PERMITTED = 'not_enough_privilege';

    /** What to do with a file only the deleted record uses. */
    public const FILE_KEEP   = 'keep';
    public const FILE_DELETE = 'delete';

    private readonly WriteGate $gate;

    /** Files left without any record by the deletes of this instance (kept on purpose). */
    public array $orphanedFiles = [];

    /** Files removed together with their records by the deletes of this instance. */
    public array $deletedFiles = [];

    public function __construct(
        private readonly DBInterface $db,
        private readonly Config $cfg,
        Access $access,
    ) {
        $this->gate = new WriteGate($db, $cfg, $access);
    }

    /**
     * May the principal delete anything at all in $tb? (tier or group rule; a
     * self writer may, on their own records)
     */
    public static function mayOnTable(string $tb): bool
    {
        return \Auth\Authorization::can('edit', table: $tb)
            || \Auth\Authorization::can('edit', \Auth\CurrentUser::id(), $tb);
    }

    /**
     * The files that are attached ONLY to the given records of `$tb`: the ones a deletion would leave
     * without any record. A file attached to a record outside the set is not listed (it stays).
     *
     * @param  int[] $ids
     * @return list<array{id: int, uuid: string, ext: string, filename: string, record_ids: int[]}>
     */
    public static function exclusiveFiles(DBInterface $db, string $tb, array $ids): array
    {
        $ids = array_values(array_unique(array_filter(array_map('intval', $ids))));
        if (!$ids) {
            return [];
        }
        $in   = implode(',', array_fill(0, count($ids), '?'));
        $rows = $db->query(
            "SELECT f.id, f.uuid, f.ext, f.filename, fl.record_id
               FROM bdus_file_links fl
               JOIN bdus_files f ON f.id = fl.file_id
              WHERE fl.table_name = ? AND fl.record_id IN ({$in})
                AND NOT EXISTS (
                    SELECT 1 FROM bdus_file_links o
                     WHERE o.file_id = fl.file_id
                       AND NOT (o.table_name = ? AND o.record_id IN ({$in}))
                )
              ORDER BY f.id, fl.record_id",
            array_merge([$tb], $ids, [$tb], $ids),
            'read'
        ) ?: [];

        $files = [];
        foreach ($rows as $r) {
            $fid = (int) $r['id'];
            $files[$fid] ??= [
                'id' => $fid, 'uuid' => (string) $r['uuid'], 'ext' => (string) $r['ext'],
                'filename' => (string) $r['filename'], 'record_ids' => [],
            ];
            $files[$fid]['record_ids'][] = (int) $r['record_id'];
        }
        return array_values($files);
    }

    /**
     * @param array<int,string> $fileChoices  fileId => 'delete'|'keep' for the files only this record uses
     * @param string            $filesDefault what to do with such a file without an explicit choice
     * @return string  DELETED, NOT_FOUND or NOT_PERMITTED
     * @throws \Throwable when the database refuses the delete
     */
    public function delete(string $tb, int $id, array $fileChoices = [], string $filesDefault = self::FILE_KEEP): string
    {
        if (!$this->db->query("SELECT 1 FROM {$tb} WHERE id = ?", [$id], 'read')) {
            return self::NOT_FOUND;
        }

        $reader = new Read($id, null, $tb, $this->db, $this->cfg);

        // Ownership check — only needed when the caller doesn't already have a
        // blanket edit privilege (i.e. they are self_writer). Plugin tables have
        // no `creator` of their own: the gate resolves it through the host record.
        $isPlugin = (bool) $this->cfg->get("tables.{$tb}.is_plugin");
        $creator  = \Auth\Authorization::can('edit', table: $tb)
            ? null
            : $this->gate->ownerCreator($tb, $reader, $isPlugin);
        // Row scope and locks (a plugin row follows its host record).
        if (!$this->gate->mayChange('delete', $tb, $id, $creator)) {
            return self::NOT_PERMITTED;
        }

        // The record's Pleiades link (if the plugin is active on this table) owns a
        // bdus_geodata row; remove it alongside the record instead of leaving it orphaned.
        if ($this->cfg->get("tables.{$tb}.pleiades")) {
            $geoId = (int) ($reader->getCore('pleiades_geo_id', true) ?? 0);
            if ($geoId) {
                $this->db->query('DELETE FROM bdus_geodata WHERE id = ?', [$geoId], 'boolean');
            }
        }

        $editor = new Edit($reader);
        $editor->delete($fileChoices, $filesDefault);
        $result = $editor->persist($this->db, $this->cfg);

        $this->orphanedFiles = array_merge($this->orphanedFiles, $result['core']['orphaned_files'] ?? []);
        $this->deletedFiles  = array_merge($this->deletedFiles,  $result['core']['deleted_files']  ?? []);

        return self::DELETED;
    }
}
