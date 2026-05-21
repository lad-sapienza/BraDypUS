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

            $this->db->commit();
        } catch (\Throwable $e) {
            $this->db->rollBack();
            throw $e;
        }

        return ['deleted' => 1];
    }
}
