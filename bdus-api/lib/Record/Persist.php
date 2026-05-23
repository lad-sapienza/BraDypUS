<?php
/**
 * @copyright 2007-2022 Julian Bogdani
 * @license AGPL-3.0; see LICENSE
 */

namespace Record;

use DB\DBInterface;
use Config\Config;

/**
 * Persists a Record model to the database.
 *
 * Receives the model produced by Record\Read::getFull() (after optional
 * modifications by Record\Edit) and translates the _val / _delete markers
 * into INSERT / UPDATE / DELETE SQL statements.
 *
 * All SQL placeholders are positional `?` to match the rest of BraDypUS.
 */
class Persist
{
    private DBInterface $db;
    private Config $cfg;

    /** Full model array */
    private array $model;

    /** Fully-qualified table name */
    private string $tb;

    /** Current record id (null for new records, populated after INSERT) */
    private ?int $id;

    // ── Constructor & factory ────────────────────────────────────────────────

    public function __construct(array $model, DBInterface $db, Config $cfg)
    {
        $this->model  = $model;
        $this->db     = $db;
        $this->cfg    = $cfg;
        $this->tb     = $this->model['metadata']['tb_id'];
        // rec_id may be an int, a string, or the full field array ['name'=>'id','val'=>N]
        $recId        = $this->model['metadata']['rec_id'];
        if (is_array($recId)) {
            $recId = $recId['val'] ?? null;
        }
        $this->id     = $recId ? (int) $recId : null;
    }

    /**
     * Persist all sections of the model and return an array of result counts.
     *
     * @param array       $model  Model as returned by Read::getFull() + Edit modifications
     * @param DBInterface $db     Active DB connection
     * @param Config      $cfg    Application config
     * @return array              ['core'=>[…], 'plugins'=>[…], 'manualLinks'=>[…], 'rs'=>[…]]
     */
    public static function all(array $model, DBInterface $db, Config $cfg): array
    {
        $instance = new self($model, $db, $cfg);

        $result = [];
        $result['core'] = $instance->persistCore();

        // After core, sync the id back into the instance so sub-sections can use it.
        // persistCore() already updates $this->id internally.

        $result['plugins']     = $instance->persistPlugins();
        $result['manualLinks'] = $instance->persistManualLinks();
        $result['rs']          = $instance->persistRs();

        return $result;
    }

    // ── Public single-section methods ────────────────────────────────────────

    /**
     * Persist the core record (INSERT, UPDATE, or DELETE cascade).
     *
     * @return array  e.g. ['affected'=>1, 'id'=>42] or ['deleted'=>1] or ['affected'=>0]
     */
    public function persistCore(): array
    {
        // Full record deletion requested
        if (isset($this->model['core']['id']['_delete'])) {
            // Snapshot the full record before deleting so it can be recovered later.
            if ($this->id) {
                $this->db->saveSnapshot(
                    $this->tb,
                    $this->id,
                    $this->buildSnapshotContent(),
                    'delete'
                );
            }
            return $this->deleteAll();
        }

        // Collect changed fields
        $changed = [];
        foreach ($this->model['core'] as $fld => $data) {
            if (isset($data['_val'])) {
                $changed[$data['name']] = $data['_val'];
            }
        }

        if (empty($changed)) {
            return ['affected' => 0];
        }

        if ($this->id) {
            // Second-pass filter: drop fields whose new value is string-equal to
            // the current DB value.  Edit::setCore() uses strict PHP !== which
            // fires on type mismatches (DB returns "5"/string, form sends 5/int).
            // Those "changes" produce a no-op UPDATE and a phantom history entry.
            $changed = array_filter(
                $changed,
                function ($newVal, string $fldName): bool {
                    $oldVal = $this->model['core'][$fldName]['val'] ?? null;
                    // Both null → no change.
                    if ($oldVal === null && ($newVal === null || $newVal === '')) {
                        return false;
                    }
                    // String comparison: "5" and 5 are equal, so no phantom entry.
                    return (string)($oldVal ?? '') !== (string)($newVal ?? '');
                },
                ARRAY_FILTER_USE_BOTH
            );

            if (empty($changed)) {
                return ['affected' => 0];
            }

            // Snapshot the record as it is NOW, before the UPDATE is applied.
            $this->db->saveSnapshot(
                $this->tb,
                $this->id,
                $this->buildSnapshotContent(),
                'update'
            );

            // UPDATE
            $setParts = array_map(fn($k) => "{$k} = ?", array_keys($changed));
            $sql      = "UPDATE {$this->tb} SET " . implode(', ', $setParts) . " WHERE id = ?";
            $values   = array_values($changed);
            $values[] = $this->id;

            $this->db->query($sql, $values, 'boolean');

            return ['affected' => 1, 'id' => $this->id];
        } else {
            // INSERT
            $fields   = array_keys($changed);
            $placeholders = implode(', ', array_fill(0, count($fields), '?'));
            $sql      = "INSERT INTO {$this->tb} (" . implode(', ', $fields) . ") VALUES ({$placeholders})";
            $values   = array_values($changed);

            $newId    = (int) $this->db->query($sql, $values, 'id');
            $this->id = $newId;

            return ['affected' => 1, 'id' => $this->id];
        }
    }

    /**
     * Persist all plugin rows (INSERT, UPDATE, DELETE) in a single transaction.
     *
     * @return array  ['inserted'=>N, 'updated'=>N, 'deleted'=>N]
     */
    public function persistPlugins(): array
    {
        $counts = ['inserted' => 0, 'updated' => 0, 'deleted' => 0];

        if (empty($this->model['plugins'])) {
            return $counts;
        }

        $this->db->beginTransaction();

        try {
            foreach ($this->model['plugins'] as $pluginName => $pluginData) {
                if (empty($pluginData['data'])) {
                    continue;
                }

                foreach ($pluginData['data'] as $row) {
                    $rowId = $row['id']['val'] ?? null;

                    // DELETE
                    if ($rowId && isset($row['id']['_delete'])) {
                        $this->db->query(
                            "DELETE FROM {$pluginName} WHERE id = ?",
                            [$rowId],
                            'boolean'
                        );
                        $counts['deleted']++;
                        continue;
                    }

                    // UPDATE
                    if ($rowId) {
                        $toWrite = [];
                        foreach ($row as $fld => $data) {
                            if (
                                in_array($fld, ['id', 'table_link', 'id_link'], true)
                                || !isset($data['_val'])
                            ) {
                                continue;
                            }
                            $toWrite[$fld] = $data['_val'];
                        }

                        if (!empty($toWrite)) {
                            $setParts = array_map(fn($k) => "{$k} = ?", array_keys($toWrite));
                            $sql      = "UPDATE {$pluginName} SET " . implode(', ', $setParts) . " WHERE id = ?";
                            $values   = array_values($toWrite);
                            $values[] = $rowId;

                            $this->db->query($sql, $values, 'boolean');
                            $counts['updated']++;
                        }
                        continue;
                    }

                    // INSERT (no existing id)
                    $toWrite = [];
                    foreach ($row as $fld => $data) {
                        if (isset($data['_val'])) {
                            $toWrite[$fld] = $data['_val'];
                        }
                    }

                    if (empty($toWrite)) {
                        continue;
                    }

                    if (!isset($toWrite['table_link'])) {
                        $toWrite['table_link'] = $this->tb;
                    }
                    if (!isset($toWrite['id_link'])) {
                        if (!$this->id) {
                            throw new \Exception("Cannot insert plugin row: core id not set. Persist core first.");
                        }
                        $toWrite['id_link'] = $this->id;
                    }

                    $fields       = array_keys($toWrite);
                    $placeholders = implode(', ', array_fill(0, count($fields), '?'));
                    $sql          = "INSERT INTO {$pluginName} (" . implode(', ', $fields) . ") VALUES ({$placeholders})";
                    $values       = array_values($toWrite);

                    $this->db->query($sql, $values, 'boolean');
                    $counts['inserted']++;
                }
            }

            $this->db->commit();
        } catch (\Throwable $e) {
            $this->db->rollBack();
            throw $e;
        }

        return $counts;
    }

    /**
     * Persist manual links (userlinks table): INSERT, UPDATE sort, DELETE.
     *
     * @return array  ['inserted'=>N, 'updated'=>N, 'deleted'=>N]
     */
    public function persistManualLinks(): array
    {
        $counts = ['inserted' => 0, 'updated' => 0, 'deleted' => 0];

        if (empty($this->model['manualLinks'])) {
            return $counts;
        }

        foreach ($this->model['manualLinks'] as $link) {
            $key = $link['key'] ?? null;

            // DELETE
            if ($key && isset($link['_delete'])) {
                $this->db->query(
                    "DELETE FROM bdus_userlinks WHERE id = ?",
                    [$key],
                    'boolean'
                );
                $counts['deleted']++;
                continue;
            }

            // UPDATE sort
            if ($key && isset($link['_sort']) && !isset($link['_delete'])) {
                $this->db->query(
                    "UPDATE bdus_userlinks SET sort = ? WHERE id = ?",
                    [$link['_sort'], $key],
                    'boolean'
                );
                $counts['updated']++;
                continue;
            }

            // INSERT
            if (!$key && isset($link['_tb_id']) && isset($link['_ref_id'])) {
                if (!$this->id) {
                    throw new \Exception("Cannot insert manual link: core id not set. Persist core first.");
                }
                $sort = $link['_sort'] ?? null;
                $this->db->query(
                    "INSERT INTO bdus_userlinks (tb_one, id_one, tb_two, id_two, sort) VALUES (?, ?, ?, ?, ?)",
                    [$this->tb, $this->id, $link['_tb_id'], $link['_ref_id'], $sort],
                    'boolean'
                );
                $counts['inserted']++;
            }
        }

        return $counts;
    }

    /**
     * Persist record-set (RS) relations: INSERT, UPDATE, DELETE.
     *
     * @return array  ['inserted'=>N, 'updated'=>N, 'deleted'=>N]
     */
    public function persistRs(): array
    {
        $counts = ['inserted' => 0, 'updated' => 0, 'deleted' => 0];

        if (empty($this->model['rs'])) {
            return $counts;
        }

        foreach ($this->model['rs'] as $rs) {
            $rsId = $rs['id'] ?? null;

            // DELETE
            if ($rsId && isset($rs['_delete'])) {
                $this->db->query(
                    "DELETE FROM bdus_rs WHERE id = ?",
                    [$rsId],
                    'boolean'
                );
                $counts['deleted']++;
                continue;
            }

            // UPDATE
            if (
                $rsId
                && (isset($rs['_first']) || isset($rs['_second']) || isset($rs['_relation']))
            ) {
                $toWrite = [];
                if (isset($rs['_first']))    $toWrite['first']    = $rs['_first'];
                if (isset($rs['_second']))   $toWrite['second']   = $rs['_second'];
                if (isset($rs['_relation'])) $toWrite['relation'] = $rs['_relation'];

                if (!empty($toWrite)) {
                    $setParts = array_map(fn($k) => "{$k} = ?", array_keys($toWrite));
                    $sql      = "UPDATE bdus_rs SET " . implode(', ', $setParts) . " WHERE id = ?";
                    $values   = array_values($toWrite);
                    $values[] = $rsId;

                    $this->db->query($sql, $values, 'boolean');
                    $counts['updated']++;
                }
                continue;
            }

            // INSERT
            if (
                !$rsId
                && isset($rs['_first'])
                && isset($rs['_second'])
                && isset($rs['_relation'])
            ) {
                $this->db->query(
                    "INSERT INTO bdus_rs (tb, first, second, relation) VALUES (?, ?, ?, ?)",
                    [$this->tb, $rs['_first'], $rs['_second'], $rs['_relation']],
                    'boolean'
                );
                $counts['inserted']++;
            }
        }

        return $counts;
    }

    // ── Snapshot helpers ─────────────────────────────────────────────────────

    /**
     * Builds the snapshot payload for saveSnapshot().
     *
     * Captures the record state AS IT IS IN THE DATABASE right now (before the
     * current write is applied), so a future restore can reconstruct it exactly.
     *
     * Core fields are extracted from the model's `val` entries (already loaded
     * by Record\Read before any Edit modifications).  Plugin rows are re-queried
     * from the DB to get raw flat arrays — the model's plugin structure is too
     * rich and would complicate restore logic.
     *
     * @return array  ['core' => [field => value, …], 'plugins' => [tb => [rows]]]
     */
    private function buildSnapshotContent(): array
    {
        // Core: flatten {fieldname: {val: …}} → {fieldname: value}
        $core = [];
        foreach ($this->model['core'] as $fld => $data) {
            $core[$fld] = $data['val'] ?? null;
        }

        // Plugins: fetch raw current rows from DB for every plugin table
        // configured for this table (not just those present in the model —
        // a plugin with no rows simply gets an empty array in the snapshot).
        $plugins     = [];
        $pluginNames = $this->cfg->get("tables.{$this->tb}.plugin") ?: [];

        foreach ($pluginNames as $pluginName) {
            $rows = $this->db->query(
                "SELECT * FROM {$pluginName} WHERE table_link = ? AND id_link = ?",
                [$this->tb, $this->id]
            ) ?: [];
            $plugins[$pluginName] = $rows;
        }

        return ['core' => $core, 'plugins' => $plugins];
    }

    // ── Private helpers ──────────────────────────────────────────────────────

    /**
     * Delete the core record and all associated data in a single transaction.
     *
     * Handles:
     *  1. Core row
     *  2. All plugin rows (by table_link + id_link)
     *  3. All userlinks entries referencing this record
     *  4. All rs entries referencing this record (via the rs field configured in cfg)
     *
     * File deletion is NOT handled here — that is the controller's responsibility.
     *
     * @return array  ['deleted' => 1]
     */
    private function deleteAll(): array
    {
        if (!$this->id) {
            throw new \Exception("Cannot delete record: id not set.");
        }

        $this->db->beginTransaction();

        try {
            // 1. Delete core row
            $this->db->query(
                "DELETE FROM {$this->tb} WHERE id = ?",
                [$this->id],
                'boolean'
            );

            // 2. Delete all plugin rows
            foreach ($this->model['plugins'] as $pluginName => $pluginData) {
                $this->db->query(
                    "DELETE FROM {$pluginName} WHERE table_link = ? AND id_link = ?",
                    [$this->tb, $this->id],
                    'boolean'
                );
            }

            // 3. Delete userlinks in both directions
            $this->db->query(
                "DELETE FROM bdus_userlinks WHERE (tb_one = ? AND id_one = ?) OR (tb_two = ? AND id_two = ?)",
                [$this->tb, $this->id, $this->tb, $this->id],
                'boolean'
            );

            // 4. Delete RS entries
            // The rs field configured in cfg determines which core field's value is
            // used as first/second in the rs table.
            $rsFld = $this->cfg->get("tables.{$this->tb}.rs");
            if ($rsFld) {
                // Retrieve the value of the rs field from the core data
                $rsFldVal = $this->model['core'][$rsFld]['val'] ?? null;
                if ($rsFldVal !== null) {
                    $this->db->query(
                        "DELETE FROM bdus_rs WHERE tb = ? AND (first = ? OR second = ?)",
                        [$this->tb, $rsFldVal, $rsFldVal],
                        'boolean'
                    );
                }
            }

            // 5. Handle file links
            // For each file linked to this record:
            //   - if the file is linked to other records too → remove only this link
            //   - if this is the only link → also purge the bdus_files row so that
            //     the orphaned file entry and physical file can be cleaned up.
            // Physical file deletion happens after commit (cannot roll back unlink).
            $linkedFiles = $this->db->query(
                "SELECT f.id, f.ext
                 FROM bdus_files f
                 INNER JOIN bdus_file_links fl ON fl.file_id = f.id
                 WHERE fl.table_name = ? AND fl.record_id = ?",
                [$this->tb, $this->id]
            ) ?: [];

            $toDeletePhysically = [];
            foreach ($linkedFiles as $file) {
                $fileId = (int) $file['id'];
                $rows   = $this->db->query(
                    "SELECT COUNT(*) AS cnt FROM bdus_file_links
                     WHERE file_id = ? AND NOT (table_name = ? AND record_id = ?)",
                    [$fileId, $this->tb, $this->id]
                );
                $otherLinks = (int) ($rows[0]['cnt'] ?? 0);

                if ($otherLinks === 0) {
                    // Exclusively linked here — purge the bdus_files row.
                    // The link itself is removed in the bulk DELETE below.
                    $this->db->query(
                        "DELETE FROM bdus_files WHERE id = ?",
                        [$fileId],
                        'boolean'
                    );
                    $toDeletePhysically[] = ['id' => $fileId, 'ext' => $file['ext']];
                }
            }

            // Remove all file links for this record (shared or exclusive).
            $this->db->query(
                "DELETE FROM bdus_file_links WHERE table_name = ? AND record_id = ?",
                [$this->tb, $this->id],
                'boolean'
            );

            $this->db->commit();
        } catch (\Throwable $e) {
            $this->db->rollBack();
            throw $e;
        }

        // Delete physical files for orphaned entries — best-effort, outside the
        // transaction so a missing file never causes a rollback.
        if (defined('PROJ_DIR')) {
            foreach ($toDeletePhysically as $file) {
                $path = PROJ_DIR . 'files/' . $file['id'] . '.' . $file['ext'];
                if (file_exists($path)) {
                    @unlink($path);
                }
            }
        }

        return ['deleted' => 1];
    }
}
