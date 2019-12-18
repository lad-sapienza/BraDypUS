<?php

/**
 * Parses Where statements string to SQL array
 * Checks fields and setting values array
 * @requires cfg
 * @requires DB
 */
class WhereFromString
{
    private static $validOperators = [
        '=',
        '!=',
        'like',
        'not like',
        '<',
        '<=',
        '>',
        '>=',
    ];

    /**
     * Parses string query and returns array of:
     *      SQL with placeholders
     *      Array of values to binf
     *
     * @param String $str       String to parse
     * @param String|false $tb  Table name. If not available will be extrcted from string, if provided
     * @return array
     */
    public static function parse($str, $tb = false)
    {
        $sql_values = [];
        $sql_parts = [];

        if (!$str) {
            throw new Exception("Missing string to parse");
        }

        $where_parts = explode('||', $str);

        if (!is_array($where_parts)){
            throw new Exception("Invalid input string", 1);
        }

        foreach ($where_parts as $index => $part) {
            list($p, $v) = self::parseWherePart($part, $index, $tb);
            array_push($sql_parts, $p);
            array_push($sql_values, $v);
        }

        $sql = implode(' ', $sql_parts);

        return [
            $sql, 
            $sql_values
        ];
    }

    private static function parseWherePart($part, $index, $tb)
    {
        $mini_parts = explode('|', $part);
        if (!is_array($mini_parts) || empty($mini_parts)){
            throw new Exception("Invalid part");
        }
        if ($index === 0 && count($mini_parts) !== 3) {
            throw new Exception("First block must contain 3 parts");
        }
        if ($index > 0 && count($mini_parts) !== 4) {
            throw new Exception("Block other than first must contain 4 parts");
        }
        if ($index > 0){
            list($connector, $fld, $operator, $value) = $mini_parts;
            self::validateConnector($connector);
            $sql = implode(' ', [$connector, self::parseFld($fld)[1], $operator, '?']);
        } else {
            list($fld, $operator, $value) = $mini_parts;
            $sql = implode(' ', [self::parseFld($fld)[1], $operator, '?']);
        }
        self::validateFld($fld, $tb);
        self::validateOperator($operator);
        
        return([
            $sql,
            $value
        ]);
    }

    private function parseFld($fld){
        if (strpos($fld, ':') !== false){
            return explode(':', $fld);
        } else {
            return [false, $fld];
        }
    }

    private static function validateFld($fld, $tb = false){
        if (strpos($fld, ':') !== false){
            list($tb, $fld) = explode(':', $fld);
        }

        if (!$tb){
            throw new Exception("Table name is required");
        }
        if(strpos($tb, PREFIX) === false){
            $tb = PREFIX . $tb;
        }

        $flds = array_keys(cfg::fldEl($tb, 'all', 'name'));
        if (!in_array($fld, $flds)){
            throw new Exception("The field $fld ins not available for table $tb");
        }
    }

    private static function validateConnector($connector){
        if (!in_array(strtolower($connector), ['and', 'or'])) {
            throw new Exception("Connector `$connector` non valid");
        }
    }

    private static function validateOperator($operator){
        if (!in_array(strtolower($operator), self::$validOperators)) {
            throw new Exception("Operator `$operator` non valid");
        }
    }
    
}


class SqlFromStr
{
    public static function parse($str)
    {
        $parts = explode('~', $str);

        foreach ($parts as $part) {

            // table name
            if($part[0] === '@') {
                $tb = substr($part, 1);
            
            // Field list
            } elseif ($part[0] === '[') {
                $fields_arr = explode(',', substr($part, 1));
            
            // JOINS
            } elseif ($part[0] === '+') {
                $join_arr[] = substr($part, 1);
            
            // Where
            } elseif ($part[0] === '?') {
                $where_str = substr($part, 1);

            // ORDER
            } elseif ($part[0] === '>') {
                $sort_arr[] = substr($part, 1);

            // LIMIT
            } elseif ($part[0] === '-') {
                $limit_str = substr($part, 1);

            // GROUP
            } elseif ($part[0] === '*') {
                $group_arr = explode(',', substr($part, 1));
            }
        }

        // validate $tb
        $tb = self::parseTb($tb);

        // validate $fields: set to * if not available
        $fields = self::parseFields($fields_arr);

        $join = self::parseJoinArr($join_arr);
        
        $sort = self::parseSortArr($sort_arr);
        
        $limit = self::parseLimitStr($limit_str);

        list($where, $values) = WhereFromString::parse($where_str, $tb);

        $group = self::parseGroupArr($group_str);


        return [
            join(' ', [
                "SELECT",
                $fields,
                "FROM",
                $tb,
                $join,
                "WHERE",
                $where,
                $group,
                $sort,
                $limit
            ]),
            $values
        ];

        

        // parse and validate $join_arr

        // @tablename~                          table name, required
        // [field,field,field,field,field~      field to retrieve, optional, optional, default: *
        // +tb:[on statement]~                  JOINS, optional
        // ?where~                              WHERE, optional
        // >fld:(asc|desc)|fldn(asc|desc)~      SORT 1, optional
        // -LIMITtot:LimitFrom                  LIMIT
        // *field1,field2,fieldn                GROUP

        // EG:
        // @places~
        // [id,name,tmgeo~
        // +paths__geodata:places.id=paths__geodata.id_link and paths__geodata.table_link="paths__places"~
        // ?name|like|%Magna%~
        // >id:desc~
        // -0:1~
        // *id,name

        // => SELECT id, name, tmgeo FROM paths__places JOIN paths__geodata ON places.id=paths__geodata.id_link and paths__geodata.table_link="paths__places"  WHERE name like ?  GROUP BY id, name ORDER BY  id desc LIMIT 0, 1
        // => ["%Magna%"]
    }

    private static function parseGroupArr($group_arr = false)
    {
        if (!$group_arr){
            return false;
        }
        return ' GROUP BY ' . implde(', ', $group_arr);
    }

    private static function parseLimitStr($limit_str = false)
    {
        if (!$limit_str){
            return false;
        }

        $limit_parts = explode(':', $limit_str);

        if (!is_numeric($limit_parts[0])){
            throw new Exception("Invalid Limit string (0) $limit_str");
        }
        if ($limit_parts[1] && !is_numeric($limit_parts[1])){
            throw new Exception("Invalid Limit string (1) $limit_str");
        }

        $limit = 'LIMIT ';
        $limit .= $limit_parts[0] . ($limit_parts[1] ? ', ' . $limit_parts[1] : '');

        return $limit;
    }

    private static function parseSortArr($sort_arr = false)
    {
        if (!$sort_arr || empty($sort_arr)){
            return false;
        }

        foreach ($sort_arr as $s) {
            $sort_parts = explode(':', $s);
            $sort[] = ' ' . $sort_parts[0] . ' ' . $sort_parts[1] ?: '';
        }
        return 'ORDER BY ' . implode(', ', $sort);
    }

    private static function parseFields($fields_arr = false)
    {
        if (!$fields_arr || empty($fields_arr)){
            return '*';
        } else {
            return join(', ', $fields_arr);
        }
    }

    private static function parseJoinArr($join_arr){
        $join_str = '';
        if (!$join_arr || empty($join_arr)){
            return $join_str;
        }


        foreach ($join_arr as $join) {
            list($tb, $on) = explode(':', $join);
            $join_str .= "JOIN $tb ON $on ";
        }

        return $join_str;
    }

    /**
     * Checks if $tb is a valid table name
     *
     * @param String $tb
     * @return void
     */
    private static function parseTb($tb = false){
        if (!$tb){
            throw new Exception("Missing table name");
        }
        if(strpos($tb, PREFIX) === false){
            $tb = PREFIX. $tb;
        }
        $all_tbs = array_keys(cfg::tbEl('all', 'name'));
        if (!in_array($tb, $all_tbs)){
            throw new Exception("Not valid table: $tb");
        }

        return $tb;
    }
}

/*
    $str = 'name|like|ciao||and|copticname|like|bau';
    var_dump(WhereFromString::parse($str, 'authors'));
    
    $str_a[] = '@places';
    $str_a[] = '[id,name,tmgeo';
    $str_a[] = '+paths__geodata:places.id=paths__geodata.id_link and paths__geodata.table_link="paths__places"';
    $str_a[] = '?name|like|%Magna%';
    $str_a[] = '>id:desc';
    $str_a[] = '-0:1';
    $str_a[] = '*id,name';

    $str = join('~', $str_a);
    print_r($str);
    echo '<hr>';
    print_r(SqlFromStr::parse($str));
    echo '</pre>';
*/