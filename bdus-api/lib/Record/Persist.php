<?php

namespace Record;

class Persist
{   
    private $tb;
    private $id;
    private $model = [];

    public function __construct (Edit $edit, string $prefix)
    {
        $this->model    = $edit->getModel();
        $this->tb       = $this->model['metadata']['tb_id'];
        $this->id       = $this->model['metadata']['rec_id'];
        $this->prefix   = $prefix;
    }

    public static function all(Edit $edit, string $prefix)
    {
        $this_class = new self($edit, $prefix);
        $this_class->Core();
        $this_class->Plugin();
        $this_class->ManualLinks();
        $this_class->Geodata();
        $this_class->Rs();
        $this_class->Files();
    }

    /**
     * Returns Model array
     * If $json, JSON version will be returned
     * if $pretty, the JSON is indented
     *
     * @param boolean $json     if true, JSON will bereturned
     * @param boolean $pretty   if true, returned JSON will be indented
     * @return array|string     Model array or JSON represeentation
     */

    public function getModel($json = false, $pretty = false)
    {
        return $json ? json_encode($this->model, ($pretty ? JSON_PRETTY_PRINT : false)) : $this->model;
    }


    private function runInDb($sql, $val, $setCoreId = false)
    {
        echo "<pre>$sql\t" . json_encode($val) . "</pre>";
        if (!$this->id && $setCoreId){
            echo('set $this->id after query');
        }
    }

    public function ManualLinks()
    {
        if (!$this->id){
            throw new Exception("Set core before manualLinks: missing id");
        }
        foreach( $this->model['manualLinks'] as $linkid => $l ){
            // Add new link
            if ( 
                !isset($l['key']) &&
                 isset($l['_tb_id']) &&
                 isset($l['_ref_id'])
            ){
                $sql = "INSERT INTO {$this->prefix}userlinks "
                    . "(tb_one, id_one, tb_two, id_two, sort) "
                    . "VALUES (?, ?, ?, ?, ?)";
                $values = [
                    $this->tb,
                    $this->id,
                    $l['_tb_id'],
                    $l['_ref_id'],
                    $l['_sort']
                ];

            // UPDATE sort
            } else if ( isset($l['key']) && isset($l['_sort']) ) {
                $sql = "UPDATE {$this->prefix}userlinks SET sort = ? WHERE id = ?";
                $values = [
                    $l['_sort'], $l['key']
                ];

            // DELETE
            } else if ( isset($l['key']) && isset($l['_deleted'])){
                $sql = "DELETE FROM {$this->prefix}userlinks WHERE id = ?";
                $values = [ $l['key'] ];
            }

            if ($sql) {
                $this->runInDb($sql, $values);
            }
        }
    }


    public function Geodata()
    {
        if (!$this->id){
            throw new Exception("Set core before geodata: missing id");
        }
        foreach( $this->model['geodata'] as $geoid => $gd ){
            // Add new link
            if ( !isset($gd['id']) && isset($gd['_geometry']) ){
                $fields = [
                    "table_link",
                    "id_link",
                    "geometry"
                ];
                $values = [
                    $this->tb,
                    $this->id,
                    $gd['_geometry']
                ];

                $sql = "INSERT INTO {$this->prefix}geodata "
                    . "(" . implode(", ", $fields) . ") "
                    . "VALUES (". implode(',', array_fill(0, count($values), '?')) .")";
                

            // UPDATE
            } else if ( isset($gd['id']) && isset($gd['_geometry']) ) {
                $fields = [];
                $values = [];
                array_push($fields, "geometry = ?");
                array_push($values, $gd['_geometry']);
                $sql = "UPDATE {$this->prefix}geodata SET " . implode(", ", $fields). " WHERE id = ?";
                array_push($values, $gd['id']);
                

            // DELETE
            } else if ( isset($gd['id']) && isset($gd['id']['_deleted'])){
                $sql = "DELETE FROM {$this->prefix}geodata WHERE id = ?";
                $values = [ $gd['id'] ];
            }

            if ($sql) {
                $this->runInDb($sql, $values);
            }
        }
    }


    public function Rs()
    {
        foreach( $this->model['rs'] as $rsid => $rs ){
            // Add new link
            if ( 
                !isset($rs['id']) && 
                isset($rs['_first']) &&
                isset($rs['_second']) &&
                isset($rs['_relation'])
            ){
                $fields = [
                    "tb",
                    "first",
                    "second",
                    "relation"
                ];
                $values = [
                    $this->tb,
                    $rs['_first'],
                    $rs['_second'],
                    $rs['_relation']
                ];

                $sql = "INSERT INTO {$this->prefix}rs "
                    . "(" . implode(", ", $fields) . ") "
                    . "VALUES (". implode(',', array_fill(0, count($values), '?')) .")";
                

            // UPDATE
            } else if ( 
                isset($gd['id']) && 
                isset($rs['_first']) &&
                isset($rs['_second']) &&
                isset($rs['_relation'])
            ) {
                $fields = [
                    "tb",
                    "first",
                    "second",
                    "relation"
                ];
                $values = [
                    $this->tb,
                    $rs['_first'],
                    $rs['_second'],
                    $rs['_relation']
                ];
                $sql = "UPDATE {$this->prefix}rs SET " . implode(", ", $fields). " WHERE id = ?";
                array_push($values, $rs['id']);
                

            // DELETE
            } else if ( isset($rs['id']) && isset($rs['id']['_deleted'])){
                $sql = "DELETE FROM {$this->prefix}rs WHERE id = ?";
                $values = [ $rs['id'] ];
            }

            if ($sql) {
                $this->runInDb($sql, $values);
            }
        }
    }

    public function Plugins()
    {
        foreach ($this->model['plugins'] as $plugin_name => $plugin_data) {
            foreach ($plugin_data['data'] as $plg_row) {

                $sql = false;
                $values = [];

                // Delete row
                if ( 
                    isset($plg_row['id']['val']) && 
                    isset($plg_row['id']['_delete'])
                ){
                    // delete row
                    $sql = "DELETE FROM {$plugin_name} WHERE id = ?";
                    $values = [$plg_row['id']['val']];

                // New row
                } else if (!isset($plg_row['id']['val'])){
                    $plg_fld_to_write = [];
                    foreach ($plg_row as $el) {
                        if(isset($el['_val'])){
                            $plg_fld_to_write[$el['name']] = $el['_val'];
                        }
                    }

                    if (!isset($plg_fld_to_write['table_link'])){
                        $plg_fld_to_write['table_link']['_val'] = $this->tb;
                    }
                    if (!isset($plg_fld_to_write['id_link'])){
                        if (!$this->id){
                            throw new Exception("Set core before plugins: missing id");
                        }                
                        $plg_fld_to_write['id_link']['_val'] = $this->id; 
                    }

                    if ( !empty($plg_fld_to_write)){
                        $sql = "INSERT INTO {$plugin_name} ("
                            . implode(", ", array_keys($plg_fld_to_write)) 
                            . ") VALUES ( " 
                            // https://www.php.net/manual/en/function.str-repeat.php
                            . implode(',', array_fill(0, count($plg_fld_to_write), '?'))
                            . ")";
                        $values = array_values($plg_fld_to_write);
                    } 

                // Update existing row 
                } else {
                    $plg_fld_to_write = [];
                    foreach ($plg_row as $el) {
                        if(
                            !in_array($el['name'], ['id', 'table_link', 'id_link']) &&
                            isset($el['_val'])
                        ){
                            $plg_fld_to_write[$el['name']] = $el['_val'];
                        }
                    }

                    if(!empty($plg_fld_to_write)){
                        $sql = "UPDATE {$plugin_name} SET "
                            . implode(" = ? ", array_keys($plg_fld_to_write)) 
                            . " WHERE id = ?";
                        $values = array_values($plg_fld_to_write);
                        $values = array_push($values, $plg_row['id']['val']);
                    }

                }

                if ($sql) {
                    $this->runInDb($sql, $values);
                }
            }
        }
    }


    public function Core()
    {
        if (isset($this->model['core']['id']['_delete'])){
            $this->recursivelyDeleteAll();
            return;
        }
        $core_el_updated = [];

        foreach ($this->model['core'] as $fld => $data) {
            if (isset($data['_val'])) {
                $core_el_updated[$data['name']] = $data['_val'];
            }
        }

        if (empty($core_el_updated)){
            return true;
        }

        // Update
        if ($this->id){
            $sql = "UPDATE $this->tb SET "
                . implode(", ", array_map(function($v){
                    return "{$v} = ? ";
                }, array_keys($core_el_updated)))
                . " WHERE id = ?";
            $values = array_values($core_el_updated);
            array_push($values, $this->id);
            
        } else {
        // Add
            $sql = "INSERT INTO {$this->tb}, (" 
                . implode( ", ", array_keys($core_el_updated) )
                . ") VALUES ("
                . implode(", ", array_map(function($v){
                    return "?";
                }, array_values($core_el_updated)))
                . ")";
            $values = array_values($core_el_updated);
        }

        $this->runInDb($sql, $values, true);
    }



    private function recursivelyDeleteAll(){
        if (!$this->id){
            throw new Exception("Can not delete all: missing id");
        }

        // DELETE FROM TABLE
        $this->runInDb(
            "DELETE FROM {$this->tb} WHERE id = ? ",
            [ $this->id ]
        );

        // DELETE plugins
        foreach ($this->model['plugins'] as $plugin_name => $plugin_data) {
            foreach ($plugin_data['data'] as $row_id => $plg_row) {
                $this->runInDb(
                    "DELETE FROM $plugin_name WHERE id = ? ",
                    [ $row_id ]
                );
            }
        }

        // Delete manual links
        foreach( $this->model['manualLinks'] as $l ){
            $this->runInDb(
                "DELETE FROM {$this->prefix}userlinks WHERE id = ?",
                [ $l['key'] ]
            );
        }

        // Delete geodata
        $this->runInDb(
            "DELETE FROM {$this->prefix}geodata WHERE table_link = ? AND id_link = ?",
            [ $this->tb, $this->id ]
        );

        // DELETE RS
        foreach( $this->model['rs'] as $rs ){
            $this->runInDb(
                "DELETE FROM {$this->prefix}rs WHERE id = ?",
                [ $rs['id'] ]
            );
        }

        // Delete and remove files
        foreach( $this->model['files'] as $file ){
            $this->runInDb(
                "DELETE FROM {$this->prefix}files WHERE id = ?",
                [ $file['id'] ]
            );
            @unlink(PROJ_DIR . "files/{$file['id']}.{$file['ext']}");
        }
        $this->runInDb(
            "DELETE FROM {$this->prefix}userlinks WHERE "
                . "(tb_one = ? AND id_one = ?) OR (tb_two = ? AND id_two = ?)",
            [ $this->tb, $this->id, $this->tb, $this->id ]
        );
    }
}