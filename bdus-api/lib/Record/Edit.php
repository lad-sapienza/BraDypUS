<?php
/**
 * @copyright 2007-2021 Julian Bogdani
 * @license AGPL-3.0; see LICENSE
 */


namespace Record;

use \Record\Read;
use \Record\Persist;

class Edit
{
    /**
     * Model data structure
     *
     * @var array
     */
    private $model = [];

    /**
     * Container for non-blocking internal messages / warnings / etc.
     *
     * @var array
     */
    private $log = [];

    /**
     * Sets model with availabledata 
     * or creates new model id record is not available (new record)
     *
     * @param string $app       Application name
     * @param string $tb        Table name
     * @param int|false $id     Record id
     */
    public function __construct (Read $record)
    {
        $this->model = $record->getFull();
    }

    public function persist(string $prefix) : void
    {
        Persist::all($this->getModel(), $prefix);
    }

    private function addLog( string $msg) : void
    {
        $this->log[] = $msg;
    }

    public function getLog() : array
    {
        return $this->log;
    }


    /**
     * Returns Model array
     * If $json, JSON version will be returned
     * if $pretty, the JSON is indented
     *
     * @param boolean $json     if true, JSON will be returned
     * @param boolean $pretty   if true, returned JSON will be indented
     * @return array|string     Model array or JSON represeentation
     */
    public function getModel( bool $json = false, bool $pretty = false) : mixed
    {
        return $json ? json_encode($this->model, ($pretty ? JSON_PRETTY_PRINT : false)) : $this->model;
    }

    /**
     * Marks main record for deletion
     * Sets model.core.id._delete to true
     *
     * @return object   Main object
     */
    public function delete() : self
    {
        if( isset($this->model['core']['id']) ) {
            $this->model['core']['id']['_delete'] = true;
        } else {
            $this->addLog("model.core.id is not set: ignoring delete");
        }
        return $this;
    }


    /**
     * Set new core data from $data_arr source
     * $data_array is an array of fld=>val pairs containing core data
     * Only new or different from existing data will be written
     * Id field will be ignored
     *
     * @param array $data_arr array of fld => val pairs with core data
     * @return object   Main object
     */
    public function setCore(array $data_arr = []) : self
    {
        foreach ($data_arr as $fld => $val) {
            if ( $this->model['core'][$fld]['val'] !== $val ) {
                if (in_array($fld, ['id'])){
                    $this->addLog("model.core.id.val cannot be set set: ignoring field update");
                    continue;
                }
                $this->model['core'][$fld]['_val'] = $val;
            } else {
                $this->addLog("model.core.{$fld}.val is already set to {$val}: ignoring field update");
            }
        }
        return $this;
    }

    public function setFile( int $id = null, array $data = [], $file = false ) : self
    {
        // TODO:che facciamo con i file?
        // Delete
        if ($id && empty($data) && !$file) {
            if ( isset ($this->model['files'][$id]) ) {
                $this->model['files'][$id]['_delete'] = true;
            } else {
                $this->addLog("File  #{$id} not found: ignoring file deletion");
            }
            return $this;
        
        // Update
        } else if ( $id && (!empty($data) || $file) ) {
            if( !isset ( $this->model['files'][$id] ) ) {
                $this->addLog("File #{$id} not found: ignoring file update");
            }
            
            
            foreach ($data as $fld => $val) {
                if ($fld === 'id') {
                    $this->addLog("Field id cannot be updated: ignoring setFiles");
                    continue;
                }
                if ( $val !== $this->model['files'][$id][$fld]['val'] ) {
                    $this->model['files'][$id][$fld]['_val'] = $val;
                }
            }
            return $this;

        // Add new
        } else if ( !$id && !empty($data) && $file ) {

            $new_arr = [];
            foreach ($data as $fld => $val) {
                $new_arr["_{$fld}"] = $val;
            }
            array_push($this->model['files'], $new_arr);
            return $this;

        } else {

            $this->addLog("Missing data: ignoring setFile");
            return $this;
        }
    }

    /**
     * Sets RS. The following actions are available:
     *      Add new rs,     eg: setRs(false, somestr, somestr, someint)
     *      Delete rs,      eg: setRs(someint)
     *      Update rs,      eg: setRs(someint, somestr, somestr, someint)
     * Perfect matches are being ignored

     *
     * @param int!false $id
     * @param string|false $first
     * @param string|false $second
     * @param string|false $relation
     * @return void
     */
    public function setRs(int $id = null, string $first = null, string $second = null, string $relation = null) : self
    {   
        // Missing data
        if (!$id && (!$first || !$second || !$relation) ) {
            $this->addLog("Both id and relation data are missing: ignoring setRs");
        
        // Add new row
        } else if ( !$id && $first && $second && $relation) {
            $found = false;
            foreach ($this->model['rs'] as $key => $rel) {
                if (
                    ( $first === $rel['first'] && $second === $rel['second'] )
                    ||
                    ( $second === $rel['first'] && $first === $rel['second'] )
                ) {
                    $found = true;
                }
            }
            if (!$found){
                array_push($this->model['rs'], [
                    "_first" => $first,
                    "_second" => $second,
                    "_relation" => $relation
                ]);
            } else {
                $this->addLog("A relation between $first and $second already exists: ignoring setRs");
            }
            return $this;
        
        // Delete geodata
        } else if ( $id && !$first && !$second && !$relation ){
            if ( isset ($this->model['rs'][$id]) ) {
                $this->model['rs'][$id]['_delete'] = true;
            } else {
                $this->addLog("Rs  #{$id} not found: ignoring rs deletion");
            }
            return $this;

        // Update
        } else if ($id && $first && $second && $relation ) {
            if ( isset ($this->model['rs'][$id]) ) {

                $this->model['rs'][$id]['_first'] = $first;
                $this->model['rs'][$id]['_second'] = $second;
                $this->model['rs'][$id]['_relation'] = $relation;
                
            } else {
                $this->addLog("Rs #{$id} not found: ignoring rs update");
            }
            return $this;
        }
    }

    /**
     * Sets, updates or deletes geodata. The following actions are available:
     *  if $id is set an geometry is not set the geometry will be deleted
     *  if $id is set an geometry is set the geometry will be updates
     *  if $id is not set an geometry is set the geometry will be added
     *
     * @param integer $id
     * @param string $geometry
     * @return self
     */
    public function setGeodata( int $id = null, string $geometry = null) : self
    {   
        // TODO: rename $geometry to $wkt_geometry
        // pass wkt_geometry through ST_AsText function, if a geographic database is available
        
        // Missing data
        if (!$id && !$geometry) {
            $this->addLog("Both id and geometry are missing: ignoring setGeodata");
        
        // Add new row
        } else if ( !$id && $geometry ) {
            array_push($this->model['geodata'], [
                "_geometry" => $geometry
            ]);
            return $this;
        
        // Delete geodata
        } else if ( $id && !$geometry ){
            if ( isset ($this->model['geodata'][$id]) ) {
                $this->model['geodata'][$id]['_delete'] = true;
            } else {
                $this->addLog("Geodata  #{$id} not found: ignoring geodata deletion");
            }
            return $this;

        // Update
        } else if ($id &&  $geometry ) {
            if ( isset ($this->model['geodata'][$id]) ) {
                
                // Update geometry
                if ( $geometry && $this->model['geodata'][$id]['geometry'] !== $geometry) {
                    $this->model['geodata'][$id]['_geometry'] = $geometry;
                }
                
            } else {
                $this->addLog("Geodata #{$id} not found: ignoring geodata update");
            }
            return $this;
        }
    }

    /**
     * Sets manual link. The following actions are available:
     *
     * @param integer $id
     * @param string $toTable
     * @param integer $toId
     * @param integer $sort
     * @return self
     */
    public function setManualLink( int $id = null, string $toTable = null, int $toId = null, int $sort = null) : self
    {   
        // New link
        if ( !$id ) {
            if ( !$toTable || !$toId ){
                $this->addLog("Missing record id, toTable and toId: ignoring");
                return $this;
            }

            array_push($this->model['manualLinks'], [
                "_tb_id" => $toTable,
                "_ref_id" => $toId,
                "_sort" => $sort
            ]);
            return $this;
        
        // Delete link
        } else if ( $id && !$toTable && !$toTable && !$sort ){
            if ( isset ($this->model['manualLinks'][$id]) ) {
                $this->model['manualLinks'][$id]['_delete'] = true;
            } else {
                $this->addLog("Manual link #{$id} not found: ignoring link deletion");
            }
            return $this;

        // Update sort
        } else if ($id && $sort) {
            if ( isset ($this->model['manualLinks'][$id]) ) {
                $this->model['manualLinks'][$id]['_sort'] = $sort;
            } else {
                $this->addLog("Manual link #{$id} not found: ignoring link sort edit");
            }
            return $this;
        }
    }

    public function setPluginRow($plugin, $id = false, $data_arr = []) : void
    {   
        // Scenario 1: update values for existing plugin record
        if ( 
            $id && 
            !empty($data) &&
            isset($this->model['plugins'][$plugin]['data'][$id])
        ){

            foreach ($data_arr as $fld => $val) {
                if (in_array($fld, ['table_link', 'id_link'])){
                    // table_link and id_link fields can not be set
                    continue;
                }
                if ( $this->model['plugins'][$plugin]['data'][$id][$fld]['val'] !== $val){
                    $this->model['plugins'][$plugin]['data'][$id][$fld]['_val'] = $val;
                }
            }

        // Scenario 2: add new plugin record
        } else if ( !$id && !empty($data) ){
            foreach ($data_arr as $fld => $val) {
                $new_row = [
                    $fld => [
                        "name" => $fld,
                        "_val" => $val
                    ]
                ];
            }
            array_push($this->model['plugins'][$plugin]['data'], $new_row);
        
        // Scenario 3: delete plugin record
        } else if (
            $id && 
            empty($data_arr) &&
            isset($this->model['plugins'][$plugin]['data'][$id])
        ) {
            $this->model['plugins'][$plugin]['data'][$id]['id']["_delete"] = true;
        }
    }
}