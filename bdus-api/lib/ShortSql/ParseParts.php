<?php
/**
 * @uses Validation
 * @uses PREFIX
 * @uses cfg
 */
namespace ShortSql;


class ParseParts
{   

    /**
     * Parses GROUP comma separated list of fields
     *
     * @param String $group_arr GROUP comma saparated list of fields
     * @param String $tb        Reference table name, used to validate field
     * @return Array
     */
    public static function GroupStr($group_str = false, $tb)
    {
        if (!$group_str){
            return false;
        }
        $formatted_flds = array_map(function($f) use ($tb){
            list($tb, $fld, $alias) = self::Fld($f, $tb);
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
    public static function LimitStr($limit_str = false)
    {
        if (!$limit_str){
            return false;
        }

        list($rows, $offset) = explode(':', $limit_str);

        if (!is_numeric($rows)){
            throw new \Exception("Invalid Limit string (0) $limit_str");
        }
        if ($offset && !is_numeric($offset)){
            throw new \Exception("Invalid Limit string (1) $limit_str");
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
    public static function OrderArr($sort_arr = false, $tb)
    {
        if (!$sort_arr || empty($sort_arr)){
            return false;
        }

        foreach ($sort_arr as $s) {
            list($fld, $order) = explode(':', $s);
            if ( !in_array(strtolower($order), ['asc', 'desc']) ){
                $order = 'ASC';
            }
            list($tb, $fld, $alias) = self::Fld($fld, $tb);
            $sort[] = "`$tb`.`$fld` " . strtoupper($order);
        }
        return $sort;
    }

    /**
     * Parses single part (3 o 4 elements) of query statement.
     * Validates, field, operator and connector for index > 0
     *
     * @param String $part      string to parse
     * @param Integer $index    0 based index of part in the context of the entire query; default: 0
     * @param String $tb        Reference table name, used to validate field
     * @param Boolean $noValues If true no data binding array will be provied and values will be put in SQL
     * @throws \Exception        On error
     * @return Array            [SQL string, array of binded values, array of arrays of plugin joins]
     */
    private static function WherePart($part, $index = 0, $tb, $noValues = false)
    {
        // Explode string in parts
        $mini_parts = explode('|', $part);
        if (!is_array($mini_parts) || empty($mini_parts)){
            throw new \Exception("Invalid part");
        }
        
        // First block of the where statement must contain 3 parts
        if ($index === 0 && count($mini_parts) !== 3) {
            throw new \Exception("First block must contain 3 parts");
        }

        // Block other than forst of the where statement must contain 4 parts, the first being the connector
        if ($index > 0 && count($mini_parts) !== 4) {
            throw new \Exception("Block other than first must contain 4 parts");
        }

        // Set $fld, $operator, $value and $connector (might be false)
        if ($index > 0){
            list($connector, $fld, $operator, $value) = $mini_parts;
            
            Validation::isValidConnector($connector);
            
            // Connectors, as operators, are uppercase for better readability
            $connector = strtoupper($connector);
        } else {
            list($fld, $operator, $value) = $mini_parts;
            $connector = false;
        }

        Validation::isValidOperator($operator);
        
        // Operators, as connectors, are uppercase
        $operator = strtoupper($operator); // Always upper case

        // Set $tb, $fls and possibly $alias
        list($fld_tb, $fld, $alias) = self::Fld($fld, $tb);

        // If field-table is different from table name JOIN plugin table
        if( $tb !== $fld_tb ) {
            // Add PREFIX to table name, if not available
            $auto_join[] = [
                'tb' => $fld_tb,
                'string' => "JOIN `{$fld_tb}` ON `{$fld_tb}`.`table_link` = '{$tb}' AND `{$fld_tb}`.`id_link` = `{$tb}`.`id`"
            ];
        }

        // The current field is set as id_from_tb
        $id_from_tb = \cfg::fldEl($fld_tb, $fld, 'id_from_tb');
        if( $id_from_tb ){
            // Set unique alias for joined table (might be same as referenced table)
            $alias = uniqid($id_from_tb);
            // Get the id_field of to-join table
            $id_from_tb_id_fld = \cfg::tbEl($id_from_tb, 'id_field');
            // Set join
            $auto_join[] = [
                'tb' => $id_from_tb,
                'string' => "JOIN `{$id_from_tb}` AS `{$alias}` ON `{$fld_tb}`.`{$fld}` = `{$alias}`.`id`"
            ];
            // Change field
            $fld = "`$alias`.`$id_from_tb_id_fld`";
        } else {
            // $fld must always contain table name as prefix
            $fld = "`$fld_tb`.`$fld`";
        }

        // Set value
        // If the caret is the forst char, the value is not a string: it is a field name
        if ($value[0] === '^') {
            list($binded_tb, $binded_fld, $binded_alias) = self::Fld(substr($value, 1));
            $binded = "`$binded_tb`.`$binded_fld`";
        } else if (in_array($operator, ['IS NULL', 'IS NOT NULL'])) {
            $binded = '';
        } else if ($noValues) {
            $binded = "'" . str_replace("'", "\'", $value) . "'";
        } else {
            $binded = '?';
        }
        // Value is not passed if value starts with ^ or if operator is IS NULL or IS NOT NULL
        if ($value[0] === '^' || in_array($operator, ['IS NULL', 'IS NOT NULL']) ){
            unset($value);
        }

        $sql = implode(' ', [
            $connector, 
            $fld, 
            $operator, 
            $binded]);

        return([
            $sql,
            $value,
            $auto_join
        ]);
    }

    /**
     * Parses string query and returns array of:
     *      SQL with placeholders,
     *      Array of values to bind,
     *      Array of system JOINS
     *
     * @param String $str String to parse
     * @param String $tb        Table name
     * @param Boolean $noValues If true no data bindin array will be provied and values will be put in SQL
     * @return array
     */
    public static function Where($str = false, $tb, $noValues = false)
    {
        if (!$str || $str === '1') {
            return [ '1', [], [] ];
        }

        $sql_values = [];
        $sql_parts = [];
        $join_parts = [];


        $where_parts = explode('||', $str);

        if (!is_array($where_parts)){
            throw new \Exception("Invalid input string", 1);
        }

        foreach ($where_parts as $index => $part) {
            list($p, $v, $j) = self::WherePart($part, $index, $tb, $noValues);
            array_push($sql_parts, $p);
            array_push($sql_values, $v);
            $join_parts = array_merge($join_parts, $j ?: []);
        }

        return [
            implode(' ', $sql_parts), 
            $sql_values,
            $join_parts
        ];
    }

    /**
     * Gets an array of unparsed JOIN statements 
     * in the forma of table||on-statement) 
     * and returns a list (array) of valid SQL JOIN statements
     *
     * @param Array|False $join_arr   Array of join statements in the form of tb||on-statement
     * @return Array                  Array of parsed JOIN statemets
     */
    public static function JoinArr($join_arr = [])
    {

        if (empty($join_arr)){
            return [];
        }

        $join_ret = [];

        // Loop in JOIN statements
        foreach ($join_arr as $join) {
            // Separate JOIN parts, ie. tb name from ON statement
            $j_parts = explode('||', $join);

            // Assign table name to $tb and remove from join parts
            $tb = array_shift($j_parts);

            // Format table name and validate
            list($tb, $tbAlias) = self::Tb($tb);

            // Parse ON statementto SQL and vales
            list($on, $values) = self::Where(implode('||', $j_parts), $tb, true);

            // Add values to return list
            $join_ret[$tb] = "JOIN `{$tb}` " . ($tbAlias ? " AS `{$tbAlias}` " : '') . "ON {$on}";
        }

        // Return array of JOIN statements
        return $join_ret;
    }


    /**
     * Gets field string and returns array containing table name (if available) and field name.
     * All backticks are removed. Table name is prefixed, if not already prefixed
     * Field should be available in the field-list of main cfg file
     * Excpetion is thrown on error
     *
     * @param String $fld       Field plain name or tbname.fieldname:alias
     * @param String $tb        Reference table name, used to validate field
     * @return Array            [table name, field name, alias]
     */
    private static function Fld($fld, $tb = false){
        // Remove backticks
        $fld = str_replace('`', '', $fld);
        
        // if table name is provided as dot-separated prefix, get it
        if (strpos($fld, '.') !== false){
            list($tb, $fld) = explode('.', $fld);
        }

        // If alias is provided as colon-separated postfix, get it
        list($fld, $alias) = explode(':', $fld);

        // Table name is provided as parameter 
        // or as a dot-separated prefix in the field name
        // If not found an exceptionis is thrown
        if (!$tb){
            throw new \Exception("Table name is required");
        }

        // Add prefix and validate table name
        list($tb, $tbAlias) = self::Tb( $tb );
        
        Validation::isValidFld( $fld, $tb );
        
        return [$tb, $fld, $alias];
    }

    /**
     * Gets an array of fields and 
     * returns a comma-separated string with fieldnames. 
     * If no array is provided, returns [tablename.*]
     *
     * @param Array|False $fields_arr   Array of fieldnames
     * @param String $tb                Reference table name
     * @return Array of fields
     */
    public static function FieldList ( $fields_arr = [], $tb )
    {
        if (empty($fields_arr)){
            return ["`{$tb}`.*"];
        } else {
            $formatted_flds = array_map(function($f) use ($tb){
                list($tb, $fld, $alias) = self::Fld($f, $tb);
                return "`$tb`.`$fld`" . ($alias ? " AS `$alias`" : '');
            }, $fields_arr);
            return $formatted_flds;
        }
    }
    /**
     * Tries to get alias,if available
     * Add prefix to table name if no present
     * Validates table name
     *
     * @param String $tb    Table name to check
     * @throws \Exception    On error
     * @return Array        [Vald table name with prefix, possible table alias]
     */
    public static function Tb( $tb )
    {
        list($tb, $alias) = explode(':', $tb);

        // If table name has no prefix, add it
        if(strpos($tb, PREFIX) === false){
            $tb = PREFIX. $tb;
        }

        Validation::isValidTable($tb);

        return [$tb, $alias];
    }
}