<?php
/**
 * @copyright 2007-2025 Julian Bogdani
 * @license AGPL-3.0; see LICENSE
 */

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
    private $system;


    public function __construct(Resp $resp, DBInterface $db)
    {
        $this->resp = $resp;
        $this->db = $db;

        $this->inspect = new Inspect($db);

        $this->system = new Manage($this->db);
    }

    public function checkExist(): void
    {
        $sys_tables = $this->system->available_tables;

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
                    ['create', $tb]
                );

            }
        }
    }

    public function latestStructure()
    {
        $sys_tables = $this->system->available_tables;

        foreach ($sys_tables as $tb) {

            if (!$this->inspect->tableExists($tb)){
                continue;
            }
            $model_cols = array_map(function($el){
                return $el['name'];
            }, $this->system->getStructure($tb));

            $db_cols = array_map(function($el){
                return $el['fld'];
            }, $this->inspect->tableColumns($tb));

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
                        ['create', $tb, $col]
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
                        ['delete', $tb, $col]
                    );
                }
            }

        }
    }
}
