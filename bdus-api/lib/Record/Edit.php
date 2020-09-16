<?php

namespace Record;

class Edit
{
    /**
     * Model datas tructure
     *
     * @var array
     */
    private $model = [];

    /**
     * Container for non-blockinf internal messages / warnings / etc.
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
    public function __construct ($app, $tb, $id = false)
    {
        $this->model = [
            "metadata" => [
                "app"   => $app,
                "tb_id" => $tb,
                "rec_id" => $id,
            ]
        ];

        $this->model["core"]        = $id ? Read::getCore($tb, $id) :               [];
        $this->model["plugins"]     = $id ? Read::getPlugins($app, $tb, $id) :      [];
        $this->model["manualLinks"] = $id ? Read::getManualLinks($app, $tb, $id) :  [];
        $this->model["files"]       = $id ? Read::getFiles($app, $tb, $id) :        [];
        $this->model["geodata"]     = $id ? Read::getGeodata($app, $tb, $id) :      [];
        $this->model["rs"]          = $id ? Read::getRs($app, $tb, $id) :           [];
    }

    private function addLog($msg)
    {
        $this->log[] = $msg;
    }

    public function getLog()
    {
        return $this->log;
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

    /**
     * Marks main record for deletion
     * Sets model.core.id._delete to true
     *
     * @return object   Main object
     */
    public function delete()
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
    public function setCore($data_arr = [])
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

    public function setFile( $id = false, $data = [], $file = false )
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
    public function setRs($id = false, $first = false, $second = false, $relation = false)
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
     * Sets geodata. The following actions are available:
     *      Add new geodata,                    eg: setGeodata(false, somestr, somestr?, somestr?)
     *      Delete geodata,                     eg: setGeodata(someint)
     *      Update sorting for existing link,   eg: setGeodata(someint, somestr, somestr, somestr)
     *                                          eg: setGeodata(someint, somestr, somestr?, somestr?)
     *                                          eg: setGeodata(someint, somestr?, somestr, somestr?)
     *                                          eg: setGeodata(someint, somestr?, somestr?, somestr)
     * Perfect matches are being ignored

     *
     * @param boolean $id
     * @param boolean $geometry
     * @return void
     */
    public function setGeodata($id = false, $geometry = false)
    {   
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
     *      Update sorting for existing link,   eg: setManualLink(someint, false, false, someint)
     *      Add new link,                       eg: setManualLink(false, somestr, someint)
     *      Delete link,                        eg: setManualLink(someint)
     * Perfect matches are being ignored
     *
     * @param int $id           id of link record
     * @param string $toTable   referenced table
     * @param int|false $toId         referenced record id
     * @param int|false $sort       sorting
     * @return object   Main object
     */
    public function setManualLink($id = false, $toTable = false, $toId = false, $sort = false)
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

    public function setPluginRow($plugin, $id = false, $data_arr = [])
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