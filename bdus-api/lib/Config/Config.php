<?php
declare(strict_types=1);

namespace Config;


class Config
{
    private $dot;
    private $cfg;
    private $path2cfg;
    private $prefix;

    private $errors = [];

    public function __construct( \Adbar\Dot $dot, string $path2cfg, string $prefix )
    {   
        try {
            $this->dot = $dot;
            $this->path2cfg = $path2cfg;
            $this->prefix = $prefix;
            $this->cfg = ConfigLoad::all( $path2cfg, $prefix );
        } catch ( ConfigException $e) {
            $this->addError($e->getMessage, "error");
        }
        
    }

    /**
     * Ceturns configuration string or Array, using dot notation with or without wildchar
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
     * @return void
     */
    public function get(string $key = '*', string $filter_key = null, string $filter_val = null)
    {

        if (strpos($key, '*') === false ) {
            $this->dot->setArray($this->cfg);
            return $this->dot->get($key, false);
        }


        $cfg = $this->cfg;

        $part = explode('.', $key);

        if ($part[0] === '*') {
            return $cfg;

        } elseif ($part[0] === 'main'){
            // main, main.*
            if (count($part) > 2 ) {
                $this->addError("Only the first two elements of `$key` will be considered", "warning");
            }
            if ( $part[1] === '*') {
                return $cfg['main'];
            }

        } elseif ($part[0] === 'tables' && $part[2] === 'fields' ) {
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
        $data = $cfg['tables'][$part[1]]['fields'];

        // tables.tb.fields.*, 
        if (!isset($part[4])){
            return $data;
        }

        // tables.tb.fields.*.sth, 
        $ret = [];
        foreach ($data as $fld => $fld_arr) {
            if (array_key_exists( $part[4], $data[$fld] ) ) {
                $ret[$fld] = $data[$fld][$part[4]]; 
            }
        }
        if (!empty($ret)){
            return $ret;
        }
        return false;
    }

    private function mngTables(array $part, array $cfg, string $filter_key = null, string $filter_val = null)
    {   
        // tables
        if ( !isset($part[1]) ) {
            return $cfg['tables'];
        }
        // tables.*
        if ($part[1] === '*' && count($part) <= 2 ) {
            $ret = $cfg['tables'];
            if (is_array($ret) && $filter_key && $filter_val) {
                foreach ($ret as $key => $value) {
                    if ( $value[$filter_key] !== $filter_val) {
                        unset($ret[$key]);
                    }
                }
            }
            return $ret;
        }
        
        // tables.*.sth
        if ( count($part) <= 3 ){
            $ret = [];
            if ($part[1] === '*') {
                foreach ($cfg['tables'] as $tb => $tb_data) {
                    if (array_key_exists($part[2], $cfg['tables'][$tb] )) {
                        if ($filter_key && $filter_val) {
                            if ($cfg['tables'][$tb][$filter_key] === $filter_val){
                                $ret[$tb] = $cfg['tables'][$tb][$part[2]];
                            }
                        } else {
                            $ret[$tb] = $cfg['tables'][$tb][$part[2]];
                        }
                    }
                }
            }
            if (!empty($ret)){
                return $ret;
            }
            $this->addError("Invalid search parameter `$key`", 'error');
            return false;
        }
    }

    private function addError(string $error, string $type = "error")
    {
        \array_push($this->errors, strtoupper($type) . ': '. $error);
    }

    public function getErrors()
    {
        return $this->errors;
    }

    public function save()
    {
        return ConfigToFiles::all( $this->cfg, $this->path2cfg, $this->prefix );
    }

}