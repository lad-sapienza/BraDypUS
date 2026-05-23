<?php

/**
 * @copyright 2007-2022 Julian Bogdani
 * @license AGPL-3.0; see LICENSE
 */

declare(strict_types=1);

namespace Config;

use DB\DBInterface;

/**
 * Application configuration facade.
 *
 * Loads table/field definitions either from the database (bdus_cfg_tables +
 * bdus_cfg_fields, preferred) or from JSON files in cfg/ (legacy fallback).
 * App-level settings (lang, name, status, …) always come from cfg/config.json
 * because that file also holds the DB credentials needed to open the connection
 * in the first place.
 *
 * All mutating methods (setTable, setFld, …) write to the DB when available,
 * otherwise to JSON files.
 */
class Config
{
    private $dot;
    private $cfg;
    private string $path2cfg;
    private ?DBInterface $db;
    private bool $useDb;

    private array $errors = [];

    public function __construct(
        \Adbar\Dot $dot,
        string $path2cfg,
        ?DBInterface $db = null
    ) {
        $this->dot      = $dot;
        $this->path2cfg = $path2cfg;
        $this->db       = $db;
        $this->useDb    = $db !== null && LoadFromDB::isAvailable($db);

        try {
            // App-level settings always come from the JSON file (holds DB creds).
            $this->cfg['main'] = Load::main($path2cfg);

            // Table/field definitions: DB if available, JSON otherwise.
            if ($this->useDb) {
                $this->cfg['tables'] = LoadFromDB::tables($db);
            } else {
                $data = Load::all($path2cfg);
                $this->cfg['tables'] = $data['tables'];
            }
        } catch (ConfigException $e) {
            throw new \Exception($e->getMessage());
        }
    }

    // ── Read API ──────────────────────────────────────────────────────────────

    /**
     * Returns configuration value using dot notation (supports wildcards).
     *
     * Valid patterns:
     *   main | main.sth | main.*
     *   tables | tables.* | tables.*.sth
     *   tables.tb.fields.* | tables.tb.fields.*.sth
     */
    public function get(
        string $key = '*',
        string $filter_key = null,
        string $filter_val = null
    ) {
        if (strpos($key, '*') === false) {
            $this->dot->setArray($this->cfg);
            return $this->dot->get($key, false);
        }

        $cfg  = $this->cfg;
        $part = explode('.', $key);

        if ($part[0] === '*') {
            return $cfg;
        } elseif ($part[0] === 'main') {
            if ($part[1] === '*') {
                return $cfg['main'];
            }
        } elseif ($part[0] === 'tables' && ($part[2] ?? null) === 'fields') {
            return $this->mngFields($part, $cfg);
        } elseif ($part[0] === 'tables') {
            return $this->mngTables($part, $cfg, $filter_key, $filter_val);
        } else {
            $this->addError("Invalid search parameter `$key`", 'error');
            return false;
        }
    }

    // ── Write API ─────────────────────────────────────────────────────────────

    /** Persists the in-memory state (used after bulk in-memory edits). */
    public function save(): void
    {
        if ($this->useDb) {
            foreach ($this->cfg['tables'] as $tbData) {
                // upsertTable() also calls upsertRelations() internally.
                ToDB::upsertTable($this->db, $tbData);
                foreach ($tbData['fields'] as $fldData) {
                    ToDB::upsertField($this->db, $tbData['name'], $fldData);
                }
            }
        } else {
            ToFiles::all($this->cfg, $this->path2cfg);
        }
    }

    /** Replaces the app-level settings (always JSON). */
    public function setMain(array $main): void
    {
        $this->cfg['main'] = $main;
        ToFiles::writeMain($this->path2cfg, $main);
    }

    /** Inserts or replaces a table entry (metadata only — fields managed separately). */
    public function setTable(array $tbData): void
    {
        $name = $tbData['name'];
        // Preserve existing fields when updating metadata.
        $tbData['fields'] = $this->cfg['tables'][$name]['fields'] ?? [];
        $this->cfg['tables'][$name] = $tbData;

        if ($this->useDb) {
            ToDB::upsertTable($this->db, $tbData);
        } else {
            ToFiles::all($this->cfg, $this->path2cfg);
        }
    }

    /** Inserts or replaces a single field in the in-memory config and persists it. */
    public function setFld(string $tb, string $fldName, array $post_data): void
    {
        $this->cfg['tables'][$tb]['fields'][$fldName] = $post_data;

        if ($this->useDb) {
            ToDB::upsertField($this->db, $tb, $post_data);
        } else {
            ToFiles::all($this->cfg, $this->path2cfg);
        }
    }

    /** Renames a field, preserving its position in the ordered field map. */
    public function renameFld(string $tb, string $old_name, string $new_name): void
    {
        // Rename key in the ordered map (preserves position).
        $keys  = array_keys($this->cfg['tables'][$tb]['fields']);
        $index = array_search($old_name, $keys, true);
        $keys[$index] = $new_name;
        $this->cfg['tables'][$tb]['fields'] = array_combine(
            $keys,
            array_values($this->cfg['tables'][$tb]['fields'])
        );
        $this->cfg['tables'][$tb]['fields'][$new_name]['name'] = $new_name;

        if ($this->useDb) {
            ToDB::renameField($this->db, $tb, $old_name, $new_name);
        } else {
            ToFiles::all($this->cfg, $this->path2cfg);
        }
    }

    /** Deletes a field from the in-memory config and persists the change. */
    public function deleteFld(string $tb, string $fld): void
    {
        unset($this->cfg['tables'][$tb]['fields'][$fld]);

        if ($this->useDb) {
            ToDB::deleteField($this->db, $tb, $fld);
        } else {
            ToFiles::all($this->cfg, $this->path2cfg);
        }
    }

    /** Renames a table in memory and persists the change. */
    public function renameTb(string $old_name, string $new_name): void
    {
        $keys  = array_keys($this->cfg['tables']);
        $index = array_search($old_name, $keys, true);
        $keys[$index] = $new_name;
        $this->cfg['tables'] = array_combine(
            $keys,
            array_values($this->cfg['tables'])
        );
        $this->cfg['tables'][$new_name]['name'] = $new_name;

        if ($this->useDb) {
            ToDB::renameTable($this->db, $old_name, $new_name);
        } else {
            ToFiles::all($this->cfg, $this->path2cfg);
            rename(
                $this->path2cfg . $old_name . '.json',
                $this->path2cfg . $new_name . '.json'
            );
        }
    }

    /** Deletes a table from memory and persists the change. */
    public function deleteTb(string $tb): void
    {
        unset($this->cfg['tables'][$tb]);

        if ($this->useDb) {
            ToDB::deleteTable($this->db, $tb);
        } else {
            ToFiles::all($this->cfg, $this->path2cfg);
            @unlink($this->path2cfg . $tb . '.json');
        }
    }

    /** Sorts tables by the given ordered array of names. */
    public function sortTables(array $sort): bool
    {
        uksort($this->cfg['tables'], function ($a, $b) use ($sort) {
            return array_search($a, $sort) <=> array_search($b, $sort);
        });

        if ($this->useDb) {
            ToDB::sortTables($this->db, $sort);
        } else {
            ToFiles::all($this->cfg, $this->path2cfg);
        }
        return true;
    }

    // ── Diagnostics ───────────────────────────────────────────────────────────

    public function getErrors(): array
    {
        return $this->errors;
    }

    // ── Private helpers ───────────────────────────────────────────────────────

    private function mngFields(array $part, array $cfg)
    {
        $data = $cfg['tables'][$part[1]]['fields'] ?? [];

        if (!isset($part[4])) {
            return $data;
        }

        $ret = [];
        foreach ($data as $fld => $fld_arr) {
            if (array_key_exists($part[4], $fld_arr)) {
                $ret[$fld] = $fld_arr[$part[4]];
            }
        }
        return $ret ?: false;
    }

    private function mngTables(
        array $part,
        array $cfg,
        ?string $filter_key = null,
        ?string $filter_val = null
    ) {
        if (!isset($part[1])) {
            return $cfg['tables'];
        }

        if ($part[1] === '*' && count($part) <= 2) {
            $ret = $cfg['tables'];
            if (is_array($ret) && $filter_key) {
                foreach ($ret as $key => $value) {
                    if ($filter_val && ($value[$filter_key] ?? null) !== $filter_val) {
                        unset($ret[$key]);
                    } elseif (!$filter_val && ($value[$filter_key] ?? false)) {
                        // filter_val=null means "falsy only": drop rows where the
                        // key's value is truthy.  Works for both JSON (key absent)
                        // and DB-backed config ('0' = falsy, '1' = truthy).
                        unset($ret[$key]);
                    }
                }
            }
            return $ret;
        }

        if (count($part) <= 3) {
            $ret = [];
            if ($part[1] === '*') {
                foreach ($cfg['tables'] as $tb => $tb_data) {
                    if (!array_key_exists($part[2], $tb_data)) continue;
                    if ($filter_key) {
                        if ($filter_val && ($tb_data[$filter_key] ?? null) === $filter_val) {
                            $ret[$tb] = $tb_data[$part[2]];
                        } elseif (!$filter_val && !($tb_data[$filter_key] ?? false)) {
                            // filter_val=null means "falsy only": include rows where
                            // the key's value is falsy or absent.
                            $ret[$tb] = $tb_data[$part[2]];
                        }
                    } else {
                        $ret[$tb] = $tb_data[$part[2]];
                    }
                }
            }
            return $ret ?: false;
        }
    }

    private function addError(string $error, string $type = 'error'): void
    {
        $this->errors[] = strtoupper($type) . ': ' . $error;
    }
}
