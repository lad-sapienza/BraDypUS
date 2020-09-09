<?php
declare(strict_types=1);

namespace SQL\ShortSql;

use SQL\ShortSql\Validator;
use SQL\ShortSql\ShortSqlException;

use SQL\QueryObject;
use Config\Config;


class ParseShortSql
{
    private $parts;

    private $prefix;
    private $validator;
    private $cfg;
    private $qo;

    public function __construct(string $prefix = null, Config $cfg = null, Validator $validator = null)
    {
        $this->prefix = $prefix;
        $this->validator = $validator;
        $this->cfg = $cfg;
        $this->qo = new QueryObject();
        $this->parts = [
            'tb'        => null, // tb, [tb, alias]
            'fields'    => null, // fld1,fld2, [[tb, fld1, fld1_alias], [ ... ]]
            'join'      => null, // [ [tb, on, values], [...] ]
            'where'     => null, // [ [null, fld, op, val ], [conn, fld, op, val] ]
            'group'     => null,
            'order'     => null,
            'limit'     => null,
            'values'    => null,
        ];
        $this->symbols = [
            '@' => 'tb'    ,
            '[' => 'fields',
            '+' => 'join'  ,
            '?' => 'where' ,
            '*' => 'group' ,
            '>' => 'order' ,
            '-' => 'limit'
        ];
    }

    public function parseAll(string $str): self
    {
        $this->setParts($str);
        $this->parseParts($str);
        return $this;
    }

    public function getQueryObject()
    {
        return $this->qo;
    }

    private function setParts(string $str) : void
    {
        $parts = explode('~', $str);

        foreach ($parts as $part) {
            foreach ($this->symbols as $symbol => $key) {
                if ($part[0] === $symbol) {
                    if($key === 'join') {
                        $this->parts[$key][] = substr($part, 1);
                    } else {
                        $this->parts[$key] = substr($part, 1);
                    }
                }
            }
        }
    }


    private function parseParts(string $str)
    {
        list($tb, $tb_alias) = $this->parseTb($this->parts['tb']);
        $this->qo->setTb($tb, $tb_alias);

        // validate $fields: set to * if not available
        $fields = $this->parseFldList($this->parts['fields'], $tb);
        foreach ($fields as $f) {
            $this->qo->setField($f[0], $f[1], $f[2]);
        }

        $joins = $this->parseJoinArr($this->parts['join'] ?? []);
        foreach ($joins as $j) {
            $this->qo->setJoin($j[0], $j[1], $j[2]);
        }
        
        if ($this->parts['where'] && $this->parts['where'] !== '1') {
            list($where, $values, $plg_joins) = $this->parseWhere($this->parts['where'], $tb);
            foreach ($where as $w) {
                $this->qo->setWherePart($w[0], $w[1], $w[2], $w[3]);
            }
            $this->qo->setWhereValues($values);
        }
        
        if (is_array($plg_joins) ){
            foreach ($plg_joins as $j) {
                $this->qo->setJoin($j[0], $j[1], $j[2]);
            }
        }

        $order = $this->parseOrder($this->parts['order'], $tb);
        foreach ($order as $o) {
            $this->qo->setOrderFld($o[0], $o[1]);
        }
        
        list($rows, $offset) = $this->parseLimit($this->parts['limit']);
        if ($rows && $offset) {
            $this->qo->setLimit((int)$rows, (int)$offset);
        }

        $group = $this->parseGroup($this->parts['group'], $tb);
        foreach ($group as $g) {
            $this->qo->setGroupFld($g);
        }

    }

    /**
     * Parses table string and returns array of table and table alias
     *
     * @param string $tb
     * @return array
     */
    private function parseTb( string $tb): array
    {
        list($tb, $alias) = explode(':', $tb);

        // If table name has no prefix, add it
        if($this->prefix && strpos($tb, $this->prefix) === false){
            $tb = $this->prefix . $tb;
        }
        if ($this->validator){
            $this->validator->isValidTable($tb);
        }

        return [$tb, $alias];
    }

    /**
     * Parses string of fields, eg.:
     * null, '*', 'tb.*', 'fld1,fldn', 'tb.fld1,fldn
     * And return aray of array containing for each field:
     *  table name, field name, field alias
     *
     * @param array $fields_arr
     * @param string $tb
     * @return array
     */
    private function parseFldList ( string $fields = null, string $tb ) : array
    {
        if (!$fields || $fields === '*' ){
            return [ [ $tb, '*', null ] ];
        } else {
            $formatted_flds = [];
            $fields_arr = explode(',', $fields);
            foreach ($fields_arr as $f) {
                list($tb, $fld, $alias) = $this->parseFld($f, $tb);
                array_push($formatted_flds, [
                    $tb, $fld, $alias
                ]);
            }
            return $formatted_flds;
            
        }
    }

    /**
     * Parese single field string and returns array of table name, field name, field alias'
     *
     * @param string $fld
     * @param string $tb
     * @return void
     */
    private function parseFld(string $fld, string $tb = null){
        
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
            throw new ShortSqlException("Table name is required");
        }

        // Add prefix and validate table name
        list($tb, $tbAlias) = $this->parseTb( $tb );

        if($this->validator){
            $this->validator->isValidFld( $fld, $tb );
        }
        
        return [$tb, $fld, $alias];
    }

    /**
     * Gets array of JOIN statements and returns array of join arrays containing:
     *      table name, table alias, on statement, on values
     *
     * @param array $join_arr
     * @return array
     */
    private function parseJoinArr(array $join_arr = null): array
    {
        if (!$join_arr){
            return [];
        }

        $ret = [];

        // Loop in JOIN statements
        foreach ($join_arr as $join) {
            // Separate JOIN parts, ie. tb name from ON statement
            $j_parts = explode('||', $join);

            // Assign table name to $tb and remove from join parts
            $tb = array_shift($j_parts);

            // Format table name and validate
            list($tb, $tb_alias) = $this->parseTb($tb);

            // Parse ON statementto SQL and vales
            list($on, $values) = $this->parseWhere(implode('||', $j_parts), $tb, true);

            // Add values to return list
            array_push($ret, [
                $tb, $tb_alias, $on
            ]);
        }

        // Return array of JOIN statements
        // [ tb_name, tb_alias, on (where array), values (array)]
        return $ret;
    }

    /**
     * Gets where sting an returns array with: where statement, array of values, array of join parts
     *
     * @param string $str
     * @param string $tb
     * @param boolean $noValues
     * @return array
     */
    public function parseWhere(string $str = null, string $tb, bool $noValues = false) : array
    {
        $sql_values = [];
        $sql_parts = [];
        $join_parts = [];


        $where_parts = explode('||', $str);

        if (!is_array($where_parts)){
            throw new ShortSqlException("Invalid input string: `$str`");
        }

        foreach ($where_parts as $index => $part) {
            list($p, $v, $j) = $this->parseWherePart($part, $index, $tb, $noValues);
            array_push($sql_parts, $p);
            // If value element is a field name, do not add value to list
            if($v) {
                array_push($sql_values, $v);
            }
            $join_parts = array_merge($join_parts, $j ?: []);
        }

        return [
            $sql_parts, 
            $sql_values,
            $join_parts
        ];
    }

    /**
     * Return array of where statement:
     * [ 
     *  [connector (if index >0 ), fieldname, $operator, $binded], 
     *  $values (array of values),
     *  $auto_join (array of join statements: [tb, tb_alias, [[fld, op, val], [...]]])
     * ]
     *
     * @param string $part
     * @param integer $index
     * @param string $tb
     * @param boolean $noValues
     * @return array
     */
    private function parseWherePart(string $part, int $index = 0, string $tb, bool $noValues = false): array
    {
        // Explode string in parts
        $mini_parts = explode('|', $part);
        if (!is_array($mini_parts) || empty($mini_parts)){
            throw new ShortSqlException("Invalid part: `$part`. Syntax error");
        }
        
        // First block of the where statement must contain 3 parts
        if ($index === 0 && count($mini_parts) !== 3) {
            throw new ShortSqlException("Invalid part: `$part`. First block must contain 3 parts");
        }

        // Block other than forst of the where statement must contain 4 parts, the first being the connector
        if ($index > 0 && count($mini_parts) !== 4) {
            throw new ShortSqlException("Invalid part: `$part`. Blocks other than first must contain 4 parts");
        }

        $connector = null;
        // Set $fld, $operator, $value and $connector (might be false)
        if ($index > 0){
            list($connector, $fld, $operator, $value) = $mini_parts;
            
            if($this->validator){
                $this->validator->isValidConnector( $connector );
            }
            
            // Connectors, as operators, are uppercase for better readability
            $connector = strtoupper($connector);
        } else {
            list($fld, $operator, $value) = $mini_parts;            
        }

        if($this->validator){
            $this->validator->isValidOperator( $operator );
        }

        // Operators, as connectors, are uppercase
        $operator = strtoupper($operator); // Always upper case

        // Set $tb, $fls and possibly $alias
        list($fld_tb, $fld, $alias) = $this->parseFld($fld, $tb);

        // If field-table is different from table name JOIN plugin table
        if( $tb !== $fld_tb ) {
            // Add PREFIX to table name, if not available
            $auto_join[] = [
                $fld_tb,
                null, 
                [
                    [null, "{$fld_tb}.table_link", "=", "'{$tb}'"],
                    ["AND", "{$fld_tb}.id_link", "=", "{$tb}.id"]
                ]
            ];
        }

        if ($this->cfg) {
            // The current field is set as id_from_tb
            $id_from_tb = $this->cfg->get("tables.$fld_tb.fields.$fld.id_from_tb");
        }        
        if( $id_from_tb ){
            // Set unique alias for joined table (might be same as referenced table)
            $alias = uniqid($id_from_tb);
            // Get the id_field of to-join table
            $id_from_tb_id_fld = $this->cfg->get("tables.$id_from_tb.id_field");
            // Set join
            $auto_join[] = [ 
                $id_from_tb, 
                $alias, [ 
                    [ null, "{$fld_tb}.{$fld}", "=", "{$alias}.id"] 
                ]
            ];
            // Change field
            $fld = "$alias.$id_from_tb_id_fld";
        } else {
            // $fld must always contain table name as prefix
            $fld = "$fld_tb.$fld";
        }

        // Set value
        // If the caret is the forst char, the value is not a string: it is a field name
        if ($value[0] === '^') {
            list($binded_tb, $binded_fld, $binded_alias) = $this->parseFld(substr($value, 1));
            $binded = "$binded_tb.$binded_fld";
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


        return([
            [
                $connector, 
                $fld, 
                $operator, 
                $binded
            ],
            $value,
            $auto_join
        ]);
    }

    /**
     * Parses string of comma separated fields to validated array
     * to use for grouping
     *
     * @param string $group_str
     * @param string $tb
     * @return array
     */
    public function parseGroup(string $group_str = null, string $tb) : array
    {
        if (!$group_str){
            return [];
        }
        $formatted_flds = array_map(function($f) use ($tb){
            list($tb, $fld, $alias) = $this->parseFld($f, $tb);
            return "$tb.$fld";
        }, explode(',', $group_str));
        
        return $formatted_flds;
    }

    /**
     * Parses Limit string (total rows:offset) to array:
     * [ total rows, offset] and checks that both values are numeric
     *
     * @param string $limit_str
     * @return void
     */
    public function parseLimit(string $limit_str = null): array
    {
        if (!$limit_str){
            return [];
        }

        list($rows, $offset) = explode(':', $limit_str);

        if (!is_numeric($rows)){
            throw new ShortSqlException("Invalid Limit string `$limit_str`: the first part must be an integer");
        }
        if ($offset && !is_numeric($offset)){
            throw new ShortSqlException("Invalid Limit string `$limit_str`: the second part must be an integer");
        }

        return [$rows, $offset];
    }

    /**
     * Parses array of ordering statemens and return array of arrays for ordering:
     * [ table name, field name, ordering direction (ASC|DESC) ]
     *
     * @param string $order
     * @param string $tb
     * @return array
     */
    public function parseOrder(string $order = null, string $tb): array
    {
        if (!$order){
            return [];
        }

        $sort_arr = explode(',', $order);
        foreach ($sort_arr as $s) {
            list($fld, $order) = explode(':', $s);
            if ( !$order || !in_array(strtolower($order), ['asc', 'desc']) ){
                $order = 'ASC';
            }
            list($tb, $fld, $alias) = $this->parseFld($fld, $tb);
            $sort[] = [ "$tb.$fld", strtoupper($order) ];
        }
        return $sort;
    }

    

}