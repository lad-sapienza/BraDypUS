<?php

/**
 * Parses shortSql query syntax:
 *      Blocks are separated by tildes (~)
 *      Blocks order is not important
 *      - @table:alias               => Required. Alias is not supported yet (22.12.2019)
 *      - [field:Alias,fieldn:AliasN => Optional, default: *. List of fields to fetch, separated by commas. 
 *                                      Alias is optional. 
 *      - +tbname:Alias||onStatement => Optional. Multiple. Join statement
 *                                      Each statement if made of two parts separated by a double pipe ||. 
 *                                      The first element is the table name and optionally the alias. Alias is not supported yet (22.12.2019)
 *                                      The second part is the On statement expressed as Where statement
 *      - ?where statements          => Different where statements are separated by double pipe, ||
 *                                      Where statements other than first must contain the connector as first element.
 *                                      Where elements are separate by single pipes, |
 *                                      Each where statement contains a field, an operator and a value
 *                                      Field may be provided as field, field:alias, table.field or table.field:alias
 *                                      If value starts with ^, the value will not binded nor escaped by quotes as string: it is assumed to be a table field
 *      - >field:order               => Optional. Multiple. Sorting options separated by colon
 *                                      First element is field name. it should be e valid field name
 *                                      Second element is optional, Default: ASC. The sorting direction: ASC od DESC (case insensitive)
 *      - -tot:offset                => Optional. Limit statemnt, semicolon separated
 *                                      First element is the total numer of rows to fetch. Should be a numeral
 *                                      Second element, optional, is the offset
 *      - *field1,fieldn             => Optional. Group statement. Comma separated list of fields to use for grouping
 *                                      Each field shoul be a valid field and may be provided as table.field or field.
 * uses cfg
 * uses PREFIX
 */
class ShortSql
{
    /**
     * Parses string and returns array of data:
     *  'tb'        => $tb,
     *  'fields'    => $fields,
     *  'join'      => $join,
     *  'where'     => $where,
     *  'group'     => $group,
     *  'sort'      => $sort,
     *  'rows'      => $rows,
     *  'offset'    => $offset,
     *  'values'    => $values
     * If $pagination_limit is provided and NO Limit statement is given, the first one will be appended to the query
     *
     * @param String $str
     * @param String $pagination_limit
     * @return array
     */
    public static function getData($str, $pagination_limit = false)
    {
        return self::parseFullString($str, $pagination_limit);
    }

    /**
     * Parses string and returns array containing full SQL statement and value bindings.
     * If $pagination_limit is provided and NO Limit statement is given, the first one will be appended to the query
     *
     * @param String $str
     * @return Array
     */
    public static function getSQLAndValues($str, $pagination_limit = false)
    {
        $data = self::parseFullString($str, $pagination_limit);

        return [
            join(' ', [
                "SELECT",
                join(', ', $data['fields']),
                "FROM",
                "`{$data['tb']}`" . ($data['tbAlias'] ? " AS {$data['tbAlias']}" : ''),
                join(' ', $data['join']),
                "WHERE",
                $data['where'],
                $data['group']  ? ' GROUP BY ' . implode(', ', $data['group']) : false,
                $data['sort']   ? ' ORDER BY ' . implode(', ', $data['sort']) : false,
                !is_null($data['rows'])   ? ' LIMIT ' . $data['rows'] . ($data['offset'] ? ', ' . $data['offset'] : '') : false
            ]),
            $data['values'],
            $data['tb']
        ];
    }

    /**
     * Parses and returns Where part of a SQl string
     *
     * @param String $where_str     Where string
     * @param String|False $tb      Reference table name, if not available will be extracted from $where_string
     * @return Array                First index is SQL where and second array of binded data
     */
    public function getWhere($where_str, $tb = false)
    {
        return self::parseWhereStr($where_str, $tb);
    }

    private static function parseFullString($str, $pagination_limit = false)
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
                $group_str = substr($part, 1);
            }
        }

        // If no limit is defined && pagination limit is provided, add the limit from pagination
        if (!$limit_str && $pagination_limit) {
            $limit_str = $pagination_limit;
        }

        // validate $tb
        list($tb, $tbAlias) = self::parseTb($tb);

        // validate $fields: set to * if not available
        $fields = self::parseFields($fields_arr, $tb);

        $join = self::parseJoinArr($join_arr);
        
        list($where, $values) = self::parseWhereStr($where_str, $tb);
        
        $sort = self::parseOrderArr($sort_arr, $tb);
        
        list($rows, $offset) = self::parseLimitStr($limit_str);

        $group = self::parseGroupStr($group_str, $tb);

        return [
            'tb'        => $tb,
            'tbAlias'   => $tbAlias,
            'fields'    => $fields,
            'join'      => $join,
            'where'     => $where,
            'group'     => $group,
            'sort'      => $sort,
            'rows'      => $rows,
            'offset'    => $offset,
            'values'    => $values
        ];
    }

    /**
     * Parses string query and returns array of:
     *      SQL with placeholders
     *      Array of values to binf
     *
     * @param String $str       String to parse
     * @param String|false $tb  Table name. If not available will be extrcted from string, if provided
     * @param Boolean $noValues If true no data bindin array will be provied and values will be put in SQL
     * @return array
     */
    private static function parseWhereStr($str, $tb = false, $noValues = false)
    {
        $sql_values = [];
        $sql_parts = [];

        if (!$str || $str === '1') {
            return [
                '1',
                []
            ];
        }

        $where_parts = explode('||', $str);

        if (!is_array($where_parts)){
            throw new Exception("Invalid input string", 1);
        }

        foreach ($where_parts as $index => $part) {
            list($p, $v) = self::parseWherePart($part, $index, $tb, $noValues);
            array_push($sql_parts, $p);
            array_push($sql_values, $v);
        }

        $sql = implode(' ', $sql_parts);

        return [
            $sql, 
            $sql_values
        ];
    }

    /**
     * Parses single part (3 o 4 elements) of query statement.
     * Validates, field, operator and connector for index > 0
     *
     * @param String $part      string to parse
     * @param Integer $index    0 based index of part in the context of the entire query; default: 0
     * @param String $tb        Reference table name, used to validate field
     * @param Boolean $noValues If true no data bindin array will be provied and values will be put in SQL     * @return Array            SQL string and array of binded values
     */
    private static function parseWherePart($part, $index = 0, $tb = false, $noValues = false)
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
            
            // Validate connector
            if (!in_array(strtolower($connector), ['and', 'or'])) {
                throw new Exception("Connector `$connector` non valid");
            }
        } else {
            list($fld, $operator, $value) = $mini_parts;
            $connector = false;
        }

        // Validate operator
        $validOperators = [ '=', '!=', 'like', 'not like', '<', '<=', '>', '>=' ];
        if (!in_array(strtolower($operator), $validOperators)) {
            throw new Exception("Operator `$operator` non valid");
        }

        list($tb, $fld, $alias) = self::parseAndValidateFld($fld, $tb);

        $fld = "`$tb`.`$fld`";

        if ($value[0] === '^'){
            list($tb, $fld, $alias) = self::parseAndValidateFld(substr($value, 1));
            $binded = "`$tb`.`$fld`";
        } else if ($noValues) {
            $binded = "'" . str_replace("'", "\'", $value) . "'";
        } else {
            $binded = '?';
        }

        $sql = implode(' ', [
            $connector, 
            $fld, 
            $operator, 
            $binded]);

        return([
            $sql,
            $value
        ]);
    }

    /**
     * Gets field string and returns array containing table name (if available) and field name.
     * All backticks are removed. Table name is prefixed, if not already prefixed
     * Field should be available in the field-list of main cfg file
     * Excpetion is thrown on error
     *
     * @param String $fld       Field plain name or tbname.fieldname:alias
     * @param String $tb        Reference table name, used to validate field
     * @return String
     */
    private static function parseAndValidateFld($fld, $tb = false){
        $fld = str_replace('`', '', $fld);
        if (strpos($fld, '.') !== false){
            list($tb, $fld) = explode('.', $fld);
        } else {
            $fld = $fld;
        }
        list($fld, $alias) = explode(':', $fld);

        if (!$tb){
            throw new Exception("Table name is required");
        }
        if(strpos($tb, PREFIX) === false){
            $tb = PREFIX . $tb;
        }

        $flds = array_keys(cfg::fldEl($tb, 'all', 'name'));
        // Add system fields, not available usually on cfg files
        array_push($flds, 'table_link');
        array_push($flds, 'id_link');
        if (!in_array($fld, $flds)){
            throw new Exception("The field $fld is not available for table $tb");
        }

        return [$tb, $fld, $alias];
    }

    
    /**
     * Parses GROUP comma separated list of fields
     *
     * @param String $group_arr GROUP comma saparated list of fields
     * @param String $tb        Reference table name, used to validate field
     * @return Array
     */
    private static function parseGroupStr($group_str = false, $tb = false)
    {
        if (!$group_str){
            return false;
        }
        $formatted_flds = array_map(function($f) use ($tb){
            list($tb, $fld, $alias) = self::parseAndValidateFld($f, $tb);
            return "`$tb`.`$fld`";
        }, explode(',', $group_str));
        
        return $formatted_flds;
    }

    /**
     * Parses LIMIT string in the formof rows:offest
     *
     * @param String $limit_str LIMIT string in the form of rows:offset
     * @return Array
     */
    private static function parseLimitStr($limit_str = false)
    {
        if (!$limit_str){
            return false;
        }

        list($rows, $offset) = explode(':', $limit_str);

        if (!is_numeric($rows)){
            throw new Exception("Invalid Limit string (0) $limit_str");
        }
        if ($offset && !is_numeric($offset)){
            throw new Exception("Invalid Limit string (1) $limit_str");
        }

        return [$rows, $offset];
    }

    /**
     * Parses ORDER arrays in the form of field:(asc|desc)
     *
     * @param String $sort_arr  Sort array
     * @param String $tb        Reference table name, used to validate field
     * @return Array
     */
    private static function parseOrderArr($sort_arr = false, $tb = false)
    {
        if (!$sort_arr || empty($sort_arr)){
            return false;
        }

        foreach ($sort_arr as $s) {
            list($fld, $order) = explode(':', $s);
            if ( !in_array(strtolower($order), ['asc', 'desc']) ){
                $order = 'ASC';
            }
            list($tb, $fld, $alias) = self::parseAndValidateFld($fld, $tb);
            $sort[] = "`$tb`.`$fld`  " . strtoupper($order);
        }
        return $sort;
    }

    /**
     * Gets an array of fields and returns a comma-separated string with fieldnames. If no array is provided, returns *
     *
     * @param Array|False $fields_arr   Array of fieldnames
     * @return Array of fields
     */
    private static function parseFields($fields_arr = false, $tb = false)
    {
        if (!$fields_arr || empty($fields_arr)){
            return ['*'];
        } else {
            $formatted_flds = array_map(function($f) use ($tb){
                list($tb, $fld, $alias) = self::parseAndValidateFld($f, $tb);
                return "`$tb`.`$fld`" . ($alias ? " AS $alias" : '');
            }, $fields_arr);
            return $formatted_flds;
        }
    }

    /**
     * Gets an array of unparsed JOIN statements (table:on-statement) and returns a unique string
     *
     * @param Array $join_arr   Array of join ststements in the form of tb:on-statement
     * @return Array           Array of parsed JOIN statemets
     */
    private static function parseJoinArr($join_arr){
        $join_ret = [];
        if (!$join_arr || empty($join_arr)){
            return $$join_ret;
        }
        foreach ($join_arr as $join) {
            $j_parts = explode('||', $join);
            $tb = array_shift($j_parts);
            list($tb, $tbAlias) = self::parseTb($tb);

            list($on, $values) = self::parseWhereStr(implode('||', $j_parts), $tb, true);
            
            array_push($join_ret, "JOIN `$tb` " . ($tbAlias ? " AS $tbAlias " : ''). "ON $on");
        }

        return $join_ret;
    }

    /**
     * Checks if $table is a valid table name and returns full table name (with prefix)
     * Throws Exception on error
     *
     * @param String $tb    Table name to check
     * @return String       Valid table name, with Prefix
     */
    private static function parseTb($tb){
        // Table name is required
        if (!$tb){
            throw new Exception("Missing table name");
        }
        // If table name has no prefix, add it
        if(strpos($tb, PREFIX) === false){
            $tb = PREFIX. $tb;
        }

        list($tb, $alias) = explode(':', $tb);

        // Table name should be in configuration file!
        $all_tbs = array_keys(cfg::tbEl('all', 'name'));
        if (!in_array($tb, $all_tbs)){
            throw new Exception("Not valid table: $tb");
        }

        return [$tb, $alias];
    }

}