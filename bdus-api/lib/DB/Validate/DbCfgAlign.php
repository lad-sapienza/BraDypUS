<?php
/**
 * @copyright 2007-2021 Julian Bogdani
 * @license AGPL-3.0; see LICENSE
 */

namespace DB\Validate;

use DB\DBInterface;
use DB\Validate\Resp;

class DbCfgAlign
{
    private $db;
    private $resp;
    private $inspect;
    private $cfg;

    public function __construct(Resp $resp, DBInterface $db, \Config\Config $cfg)
    {
        $this->resp = $resp;
        $this->db = $db;
        $this->cfg = $cfg;

        $db_engine = $db->getEngine();
        $this->inspect = new \DB\Inspect($this->db);
    }

    public function cfgHasDb(): void
    {
        $cfg_tbs = $this->cfg->get('tables.*.name');

        foreach ($cfg_tbs as $cfg_tb) {
            if ($this->inspect->tableExists($cfg_tb)){
                $this->resp->set(
                    'success',
                    "Configuration table $cfg_tb exists in database"
                );
            } else {
                $this->resp->set(
                    'danger',
                    "Configuration table $cfg_tb does not exist in database"
                );
            }
        }
    }

    public function cfgColsHasDb()
    {
        $cfg = $this->cfg->get('tables.*');

        foreach ($cfg as $tb => $tb_data) {
            
            $cfg_cols = array_keys($tb_data['fields']);
            $db_cols = array_map(function($el){
                return $el['fld'];
            }, $this->inspect->tableColumns($tb));

            $this->resp->set('head', "Checking $tb from configuration to database");

            foreach ($cfg_cols as $col) {
                if (!in_array($col, $db_cols)){
                    $this->resp->set(
                        'danger',
                        "Configuration field {$tb}.{$col} is not available in database table",
                        "Manually add {$tb}.{$col} to the database or delete it from the configuration files"
                    );
                } else {
                    $this->resp->set(
                        'success',
                        "Configuration field {$tb}.{$col} is available in database table"
                    );
                }
            }


            $this->resp->set('head', "Checking $tb from database to configuration");

            foreach ($db_cols as $col) {
                if (!in_array($col, array_values($cfg_cols))){
                    $this->resp->set(
                        'danger',
                        "Database column {$tb}.{$col} is not available in configuration files",
                        "Manually remove {$tb}.{$col} from the database or add it to the configuration files"
                    );
                } else {
                    $this->resp->set(
                        'success',
                        "Database column {$tb}.{$col} is available in the configuration files"
                    );
                }
            }
            
        }

    }
}