<?php
/**
 * Gets Unique values from database
 * 
 * @copyright 2007-2021 Julian Bogdani
 * @license AGPL-3.0; see LICENSE
 * @requires cfg
 * @requires DB
 */

namespace API2;

use Config\Config;
use DB\DBInterface;
use SQL\ShortSql\ParseShortSql;


class GetUniqueVal
{
    public static function run(string $tb, string $fld, string $str = null, string $where = null, DBInterface $db, Config $cfg)
    {
        if ($str === 'false'){
            $str = false;
        }
        if ($where === 'false'){
            $where = false;
        }
        $fld_type = $cfg->get("tables.$tb.fields.$fld.type");
        $id_from_tb = $cfg->get("tables.$tb.fields.$fld.id_from_tb");
        if ($id_from_tb) {
            $f = $cfg->get("tables.$id_from_tb.id_field");
            $sql = "SELECT DISTINCT {$f} as f FROM {$id_from_tb} WHERE ";
        } else {
            $sql = "SELECT DISTINCT {$fld} as f FROM {$tb} WHERE ";
            $f = $fld;
        }

        $sql_part = [];
        $values = [];

        if ($str) {
            array_push($sql_part, " {$f} LIKE ? ");
            array_push($values, "%{$str}%");
        }

        if ($where) {
            $parseShortSql = new ParseShortSql();
            $parseShortSql->parseAll("@$tb~?$where");
            list($where_sql, $v) = $parseShortSql->getSql(true);
            array_push($sql_part, $where_sql);
            $values = array_merge($values, $v);
        }

        if(!$str && !$where){
            array_push($sql_part, " 1=1 ");
        }
        $sql .= implode(' AND ', $sql_part);
        $res = $db->query($sql, $values);
        
        $resp = [];
        foreach ($res as $v) {
            // Ignore empty values
            if ($v['f'] === null || trim($v['f']) === '') {
                continue;
            }
            if ($fld_type === 'multi_select') {
                $v_a = \utils::csv_explode($v['f'], ';');
                foreach ($v_a as $i) {
                    $i = trim($i);

                    // Ignore duplicate values
                    if (in_array($i, $resp)) {
                        continue;
                    }
                    // Returned values must contains $str, if $str is provided
                    if ($str && strpos(strtolower($i), strtolower($str)) === false) {
                        continue;
                    }
                    if ($i !== ""){
                        array_push($resp, $i);
                    }
                }
            } else {
                if(!in_array($v['f'], $resp)){
                    array_push($resp, trim($v['f']));
                }
            }
        }
        return $resp;
    }
}
