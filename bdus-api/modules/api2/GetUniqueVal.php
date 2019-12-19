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

        if ($str && $str !== 'false') {
            array_push($sql_part, " {$f} LIKE ? ");
            array_push($values, "%{$str}%");
        }
        if ($where && $where !== 'false') {
            array_push($sql_part, " {$where}");
        }
        if(!$str && !$where || ($str === 'false' && $where === 'false')){
            array_push($sql_part, " 1 ");
        }
        $sql .= implode(' AND ', $sql_part);

        $res = DB::start()->query($sql, $values);


        $resp = [];
        foreach ($res as $v) {
            if ($v['f'] === null || trim($v['f']) === '') {
                continue;
            }
            if ($fld_type === 'multi_select' && strpos($v['f'], ';')) {
                $v_a = utils::csv_explode($v['f'], ';');
                foreach ($v_a as $i) {
                    if (!in_array($i, $resp)) {
                        array_push($resp, $i);
                    }
                }
            } else {
                array_push($resp, $v['f']);
            }
        }
        return $resp;
    }
}
