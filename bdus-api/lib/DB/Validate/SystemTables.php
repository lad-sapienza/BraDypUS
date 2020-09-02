<?php
namespace DB\Validate;

use DB\DBInterface;
use DB\Inspect;
use DB\Inspect\Sqlite;
use DB\Inspect\Mysql;
use DB\Inspect\Postgres;
use DB\System\Manage;

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

        $db_engine = $db->getEngine();
        if ($db_engine === 'sqlite'){
            $driver = new Sqlite($db);
        } else if ($db_engine === 'mysql'){
            $driver = new Mysql($db);
        } else if ($db_engine === 'pgsql'){
            $driver = new Postgres($db);
        }
        $this->inspect = new Inspect($driver);

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
                    "System table $tb does not exist in database."
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
                if (!in_array($col, $db_cols)){
                    $this->resp->set(
                        'danger',
                        "Model field {$tb}.{$col} is not available in database table",
                        "Manually add {$tb}.{$col} to the database"
                    );
                } else {
                    $this->resp->set(
                        'success',
                        "Model field {$tb}.{$col} is available in database table"
                    );
                }
            }

            $this->resp->set('head', "Checking $tb from database to model");

            foreach ($db_cols as $col) {
                if (!in_array($col, array_values($model_cols))){
                    $this->resp->set(
                        'danger',
                        "Database column {$tb}.{$col} is not available in the model",
                        "Manually remove {$tb}.{$col} from the database"
                    );
                } else {
                    $this->resp->set(
                        'success',
                        "Database field {$tb}.{$col} is available in the model"
                    );
                }
            }

        }


    }
}