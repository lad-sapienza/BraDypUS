<?php

/**
 * Gets Unique values from database
 * @requires cfg
 * @requires DB
 */
class GetUniqueVal
{
    public static function run($tb, $fld, $str = false, $where = false)
    {
        if ($str === 'false'){
            $str = false;
        }
        if ($where === 'false'){
            $where = false;
        }
        $fld_type = cfg::fldEl($tb, $fld, 'type');
        $id_from_tb = cfg::fldEl($tb, $fld, 'id_from_tb');
        if ($id_from_tb) {
            $f = cfg::tbEl($id_from_tb, 'id_field');
            $sql = "SELECT DISTINCT `{$f}` as `f` FROM `{$id_from_tb}` WHERE ";
        } else {
            $sql = "SELECT DISTINCT `{$fld}` as `f` FROM `{$tb}` WHERE ";
            $f = $fld;
        }

        $sql_part = [];
        $values = [];

        if ($str) {
            array_push($sql_part, " {$f} LIKE ? ");
            array_push($values, "%{$str}%");
        }
        if ($where) {
            list($where_sql, $where_values) = ShortSql::getWhere($where, $tb);
            array_push($sql_part, $where_sql);
            $values = array_merge($values, $where_values);
        }
        if(!$str && !$where){
            array_push($sql_part, " 1 ");
        }
        $sql .= implode(' AND ', $sql_part);
        $res = DB::start()->query($sql, $values);
        
        $resp = [];
        foreach ($res as $v) {
            // Ignore empty values
            if ($v['f'] === null || trim($v['f']) === '') {
                continue;
            }
            if ($fld_type === 'multi_select') {
                $v_a = utils::csv_explode($v['f'], ';');
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
