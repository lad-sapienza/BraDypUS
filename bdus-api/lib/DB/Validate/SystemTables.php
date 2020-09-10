<?php
namespace DB\Validate;

use DB\DBInterface;
use DB\Inspect;
use DB\System\Manage;
use DB\Validate\Resp;

class SystemTables
{
    private $db;
    private $resp;
    private $inspect;
    private $prefix;
    private $system;


    public function __construct(Resp $resp, DBInterface $db, string $prefix = null)
    {
        $this->resp = $resp;
        $this->db = $db;
        $this->prefix = $prefix;

        $this->inspect = new Inspect($db);

        $this->system = new Manage($this->db, $this->prefix);
    }

    public function checkExist(): void
    {
        $sys_tables = array_map( function($el){
            return $this->prefix . $el;
        }, $this->system->available_tables);

        foreach ($sys_tables as $tb) {
            if ($this->inspect->tableExists($tb)){
                $this->resp->set(
                    'success',
                    "System table $tb exists in database"
                );
            } else {
                $this->resp->set(
                    'danger',
                    "System table $tb does not exist in database.",
                    "Create table $tb",
                    ['create', str_replace($this->prefix, '', $tb)]
                );
                
            }
        }
    }

    public function latestStructure()
    {
        $short_sys_tables = $this->system->available_tables;
        
        foreach ($short_sys_tables as $tb) {
            
            if (!$this->inspect->tableExists($this->prefix . $tb)){
                continue;
            }
            $model_cols = array_map(function($el){
                return $el['name'];
            }, $this->system->getStructure($tb));

            $db_cols = array_map(function($el){
                return $el['fld'];
            }, $this->inspect->tableColumns($this->prefix.$tb));
            
            $this->resp->set('head', "Checking $tb from model to database");

            foreach ($model_cols as $col) {
                if (in_array($col, $db_cols)){
                    $this->resp->set(
                        'success',
                        "Model field {$tb}.{$col} is available in database table"
                    );
                } else {
                    $this->resp->set(
                        'danger',
                        "Model field {$tb}.{$col} is not available in database table",
                        "Add {$tb}.{$col} to the database",
                        ['create', $this->prefix . $tb, $col]
                    );
                }
            }

            $this->resp->set('head', "Checking $tb from database to model");

            foreach ($db_cols as $col) {
                if (in_array($col, array_values($model_cols))){
                    $this->resp->set(
                        'success',
                        "Database field {$tb}.{$col} is available in the model"
                    );
                } else {
                    $this->resp->set(
                        'danger',
                        "Database column {$tb}.{$col} is not available in the model",
                        "Remove {$tb}.{$col} from the database",
                        ['delete', $this->prefix . $tb, $col]
                    );
                }
            }

        }
    }
}