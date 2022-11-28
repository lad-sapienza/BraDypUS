<?php

/**
 * @copyright 2007-2022 Julian Bogdani
 * @license AGPL-3.0; see LICENSE
 */

declare(strict_types=1);

namespace Config;

use \Config\ConfigException;


class Config
{
    private $dot;
    private $cfg;
    private $path2cfg;
    private $prefix;

    private $errors = [];

    public function __construct(
        \Adbar\Dot $dot,
        string $path2cfg,
        string $prefix
    ) {
        try {
            $this->dot = $dot;
            $this->path2cfg = $path2cfg;
            $this->prefix = $prefix;
            $this->cfg = Load::all($path2cfg, $prefix);
        } catch (ConfigException $e) {
            throw new \Exception($e->getMessage());
        }
    }

    /**
     * Returns configuration string or Array, using dot notation with or without wildchar
     * For tables, if filter_key and filter values are set, onlymatchin records will be returned
     * Examples of valid dot notation, making use of wildchar
     * main
     * main.sth
     * main.*
     * 
     * tables
     * tables.*
     * tables.*.sth
     * 
     * tables.tb.fields.*, 
     * tables.tb.fields.*.sth, 
     *
     * @param string $key
     * @param string $filter_key
     * @param string $filter_val
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


        $cfg = $this->cfg;

        $part = explode('.', $key);

        if ($part[0] === '*') {
            return $cfg;
        } elseif ($part[0] === 'main') {
            // main, main.*
            if (count($part) > 2) {
                $this->addError("Only the first two elements of `$key` will be considered", "warning");
            }
            if ($part[1] === '*') {
                return $cfg['main'];
            }
        } elseif ($part[0] === 'tables' && $part[2] === 'fields') {
            // tables.tb.fields.*, tables.tb.fields.*.sth
            return $this->mngFields($part, $cfg);
        } elseif ($part[0] === 'tables') {
            // tables, tables.*, tables.*.sth
            return $this->mngTables($part, $cfg, $filter_key, $filter_val);
        } else {
            $this->addError("Invalid search parameter `$key`", 'error');
            return false;
        }
    }

    private function mngFields(array $part, array $cfg)
    {
        $data = $cfg['tables'][$part[1]]['fields'] ?: [];

        // tables.tb.fields.*, 
        if (!isset($part[4])) {
            return $data;
        }

        // tables.tb.fields.*.sth, 
        $ret = [];
        foreach ($data as $fld => $fld_arr) {
            if (array_key_exists($part[4], $data[$fld])) {
                $ret[$fld] = $data[$fld][$part[4]];
            }
        }
        if (!empty($ret)) {
            return $ret;
        }
        return false;
    }

    private function mngTables(
        array $part,
        array $cfg,
        string $filter_key = null,
        string $filter_val = null
    ) {
        // tables
        if (!isset($part[1])) {
            return $cfg['tables'];
        }
        // tables.*
        if ($part[1] === '*' && count($part) <= 2) {
            $ret = $cfg['tables'];
            if (is_array($ret) && $filter_key) {

                foreach ($ret as $key => $value) {
                    // if filter_valis is set, remove elements that do not have filter_val
                    if ($filter_val && $value[$filter_key] !== $filter_val) {
                        unset($ret[$key]);
                    } else if (!$filter_val && isset($value[$filter_key])) {
                        // if filter_valis is not set, remove elements that do have filter_val
                        unset($ret[$key]);
                    }
                }
            }
            return $ret;
        }

        // tables.*.sth
        if (count($part) <= 3) {
            $ret = [];
            if ($part[1] === '*') {
                foreach ($cfg['tables'] as $tb => $tb_data) {
                    if (array_key_exists($part[2], $cfg['tables'][$tb])) {
                        if ($filter_key) {
                            if ($filter_val && $cfg['tables'][$tb][$filter_key] === $filter_val) {
                                // filter_val is set: record must match
                                $ret[$tb] = $cfg['tables'][$tb][$part[2]];
                            } else if (!$filter_val  && !isset($cfg['tables'][$tb][$filter_key])) {
                                // filter_val is not set: record must not match
                                $ret[$tb] = $cfg['tables'][$tb][$part[2]];
                            }
                        } else {
                            $ret[$tb] = $cfg['tables'][$tb][$part[2]];
                        }
                    }
                }
            }
            if (!empty($ret)) {
                return $ret;
            }
            $this->addError("Invalid search parameter `$filter_key`", 'error');
            return false;
        }
    }

    private function addError(string $error, string $type = "error")
    {
        \array_push($this->errors, strtoupper($type) . ': ' . $error);
    }

    public function getErrors()
    {
        return $this->errors;
    }

    public function save()
    {
        return ToFiles::all($this->cfg, $this->path2cfg, $this->prefix);
    }

    public function setMain(array $main)
    {
        $this->cfg['main'] = $main;
        return ToFiles::all($this->cfg, $this->path2cfg, $this->prefix);
    }

    public function setTable(array $tb_data)
    {
        $tb = $tb_data['name'];

        $tb_data['fields'] = $this->cfg['tables'][$tb]['fields'] ?: [];

        $this->cfg['tables'][$tb] = $tb_data;

        return ToFiles::all($this->cfg, $this->path2cfg, $this->prefix);
    }

    public function renameFld(
        string $tb,
        string $old_name,
        string $new_name
    ) {
        // https://stackoverflow.com/a/8884153
        $keys = array_keys($this->cfg['tables'][$tb]['fields']);
        $index = array_search($old_name, $keys, true);
        $keys[$index] = $new_name;
        $this->cfg['tables'][$tb]['fields'] = array_combine($keys, array_values($this->cfg['tables'][$tb]['fields']));
        $this->cfg['tables'][$tb]['fields'][$new_name]['name'] = $new_name;
        return ToFiles::all($this->cfg, $this->path2cfg, $this->prefix);
    }

    public function deleteFld(string $tb, string $fld)
    {
        unset($this->cfg['tables'][$tb]['fields'][$fld]);
        return ToFiles::all($this->cfg, $this->path2cfg, $this->prefix);
    }

    public function renameTb(string $old_name, string $new_name)
    {
        $keys = array_keys($this->cfg['tables']);
        $index = array_search($old_name, $keys, true);
        $keys[$index] = $new_name;
        $this->cfg['tables'] = array_combine($keys, array_values($this->cfg['tables']));

        $this->cfg['tables'][$new_name]['name'] = $new_name;

        ToFiles::all($this->cfg, $this->path2cfg, $this->prefix);

        rename(
            $this->path2cfg . str_replace($this->prefix, '', $old_name) . '.json',
            $this->path2cfg . str_replace($this->prefix, '', $new_name) . '.json'
        );
    }

    public function deleteTb($tb)
    {
        unset($this->cfg['tables'][$tb]);

        ToFiles::all($this->cfg, $this->path2cfg, $this->prefix);

        unlink($this->path2cfg . str_replace($this->prefix, '', $tb) . '.json');
    }

    public function setFld(string $tb, string $fld_name, array $post_data)
    {
        $this->cfg['tables'][$tb]['fields'][$fld_name] = $post_data;

        ToFiles::all($this->cfg, $this->path2cfg, $this->prefix);
    }

    public function sortTables(array $sort): bool
    {
        uksort($this->cfg['tables'], function ($a, $b) use ($sort) {
            $index_a = array_search($a, $sort);
            $index_b = array_search($b, $sort);

            return $index_a < $index_b ? -1 : 1;
        });

        ToFiles::all($this->cfg, $this->path2cfg, $this->prefix);
        return true;
    }
}
