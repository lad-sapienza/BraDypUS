<?php
/**
 * @copyright 2007-2021 Julian Bogdani
 * @license AGPL-3.0; see LICENSE
 */

declare(strict_types=1);

namespace SQL\ShortSql;

use SQL\ShortSql\Validator;
use SQL\ShortSql\ShortSqlException;

use SQL\QueryObject;
use Config\Config;


class ParseShortSql
{
    private $parts;

    private $added_aliases = [];

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

    /**
     * Parses sub quary from base64url encoded string
     *
     * @param string $base64url_string
     * @return array    first element is SQL text, second element array of values
     */
    private function parseSubQuery(string $base64url_string) : array
    {
        //  base64url_decode
        $decoded_string = base64_decode( strtr( $base64url_string, '-_', '+/') . str_repeat('=', 3 - ( 3 + strlen( $base64url_string )) % 4 ));

        $self = new self($this->prefix, $this->cfg, $this->validator);
        $self->parseAll($decoded_string);

        return $self->getSql();

    }

    public function parseAll(string $str): self
    {
        $this->setParts($str);
        $this->parseParts();
        return $this;
    }
    
    public function getQueryObject()
    {
        return $this->qo;
    }

    /**
     * Alias of QueryObject::getSql
     * Returns array containing as
     *  first element the SQL statement, if $onlyWhere is truw, only the WHERE parte will be returned
     *  second element, an array with values
     * @param bool $onlyWhere
     * @return array
     */
    public function getSql(bool $onlyWhere = false) : array
    {
        return $this->qo->getSql($onlyWhere);
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


    private function parseParts()
    {
        list($tb, $tb_alias) = $this->parseTb($this->parts['tb']);
        
        $this->qo->setTb($tb, $tb_alias);

        $group = $this->parseGroup($this->parts['group'], $tb);
        if (!empty($group)){
            foreach ($group as $g) {
                // If grouping is active, fields are set from grouping
                $this->qo->setField($g);
                $this->qo->setGroupFld($g);
            }
        } else {
            // Fields are set only if grouping is off
            // validate $fields: set to * if not available
            list ($fields, $join_by_fld) = $this->parseFldList($this->parts['fields'], $tb);
            foreach ($fields as $f) {
                $this->qo->setField($f[1], $f[2], $f[0]);
            }
            foreach ($join_by_fld as $j) {
                $this->qo->setJoin( $j[0], $j[1], $j[2]);
            }
        }
        

        

        $joins = $this->parseJoinArr($this->parts['join'] ?? []);
        foreach ($joins as $j) {
            $this->qo->setJoin($j[0], $j[1], $j[2]);
        }
        
        if ($this->parts['where'] && $this->parts['where'] !== '1') {
            list($where, $values, $plg_joins) = $this->parseWhere($this->parts['where'], $tb);
            foreach ($where as $w) {
                /*
                connector
                opened_bracket
                fld
                operator
                binded
                closed_bracket
                */
                $this->qo->setWherePart(
                    $w['connector'], 
                    $w['opened_bracket'], 
                    $w['fld'], 
                    $w['operator'], 
                    $w['binded'], 
                    $w['closed_bracket']
                );
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
        if (isset($rows) && isset($offset)) {
            $this->qo->setLimit((int)$rows, (int)$offset);
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

        if (!in_array($tb, $this->added_aliases)) {
        
            // If table name has no prefix, add it
            if ($this->prefix && strpos($tb, $this->prefix) === false) {
                $tb = $this->prefix . $tb;
            }
            // Validator is not triggered if table name is a valid alias
            if (!in_array($tb, $this->added_aliases) && $this->validator) {
                $this->validator->isValidTable($tb);
            }

            array_push($this->added_aliases, $alias);
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
            return [
                [ [ $tb, '*', null ] ],
                []
            ];
        }

        $formatted_flds = [];
        $join_by_fld = [];
        $fields_arr = explode(',', $fields);
        foreach ($fields_arr as $f) {
            list($tbf, $fld, $alias) = $this->parseFld($f, $tb);
            array_push($formatted_flds, [
                $tbf, $fld, $alias
            ]);

            if (
                $tbf !== $tb &&
                (
                    $tbf === $this->prefix . 'geodata' ||
                    (
                        $this->cfg && 
                        \is_array($this->cfg->get("tables.$tb.plugin")) && 
                        \in_array($tbf, $this->cfg->get("tables.$tb.plugin"))
                    )
                )
            ){
                // This is a plugin column!
                array_push ($join_by_fld , [
                    $tbf, null, [
                        [
                            "connector"         => null, 
                            "opened_bracket"    => null, 
                            "fld"               => "$tbf.table_link", 
                            "operator"          => "=", 
                            "binded"            => "'{$tb}'",
                            "closed_bracket"
                        ],
                        [
                            "connector"         => 'AND', 
                            "opened_bracket"    => null, 
                            "fld"               => "$tbf.id_link", 
                            "operator"          => "=", 
                            "binded"            => "{$tb}.id",
                            "closed_bracket"
                        ]
                    ]
                ]);
            }
        }
        return [$formatted_flds, $join_by_fld];
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

        if( !in_array($tb, $this->added_aliases) && $this->validator){
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
     * Gets where ShortSQL string an returns array with: where statement, array of values, array of join parts
     *
     * @param string $str
     * @param string $tb
     * @param boolean $noValues
     * @return array    The returned array contains:
     *                  As first element an array arrays of validated parts 
     *                      Each array follows the pattern:
     *                          connector:          [connector], 
     *                          opened_bracket:     [opened_bracket], 
     *                          fld:                [field]
     *                          operator:           [operator]
     *                          binded:             [binded]
     *                          closed_bracket:     [closed_bracket], 
     *                  As second element array values to bind to the query
     *                  As third, optional, element an array with automatic join statements
     */
    private function parseWhere(string $str = null, string $tb, bool $noValues = false) : array
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
                if (\is_string($v)) {
                    array_push($sql_values, $v);
                } else {
                    $sql_values = array_merge($sql_values, $v);
                }
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
        $opened_bracket = null;
        $closed_bracket = null;

        // Open bracket is present (first block)
        if ($mini_parts[0] === '('){
            $opened_bracket = '(';
            array_shift($mini_parts);
        }

        // Open bracket is present (other than first block)
        if ($mini_parts[1] === '('){
            $opened_bracket = '(';
            array_splice($mini_parts, 1, 1);
        }
        

        // Close bracket is present
        if ($mini_parts[count($mini_parts)-1] === ')'){
            $closed_bracket = ')';
            array_pop($mini_parts);
        }
        // It makes no sense to have open and close bracket in the same block: completely remove brackets
        if ($opened_bracket && $closed_bracket) {
            $opened_bracket = null;
            $closed_bracket = null;
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
        if( $tb !== $fld_tb && !\in_array($fld_tb, $this->added_aliases)) {
            // Add PREFIX to table name, if not available
            $auto_join[] = [
                $fld_tb,
                null, 
                [
                    [
                        "connector"         => null, 
                        "opened_bracket"    => null, 
                        "fld"               => "{$fld_tb}.table_link", 
                        "operator"          => "=", 
                        "binded"            => "'{$tb}'",
                        "closed_bracket"    => null
                    ],
                    [
                        "connector"         => "AND", 
                        "opened_bracket"    => null, 
                        "fld"               => "{$fld_tb}.id_link", 
                        "operator"          => "=", 
                        "binded"            => "{$tb}.id"],
                        "closed_bracket"    => null
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
            unset($value);
        } else if (($value[0] === ']')) {
            list ($sub_query, $sub_values) = $this->parseSubQuery(substr($value, 1));
            $binded = " ( {$sub_query} ) ";
            $value = $sub_values;
        } else if (in_array($operator, ['IS NULL', 'IS NOT NULL'])) {
            $binded = '';
            unset($value);
        } else if ($noValues ) {
            $binded = "'" . str_replace("'", "\'", $value) . "'";
            unset($value);
        } else if ($operator === "IN" ) {
            $binded = $value;
            unset($value);
        } else {
            $binded = '?';
        }


        return([
            [
                "connector" => $connector, 
                "opened_bracket" => $opened_bracket,
                "fld" => $fld, 
                "operator" => $operator, 
                "binded" => $binded,
                "closed_bracket" => $closed_bracket
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
    private function parseGroup(string $group_str = null, string $tb) : array
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
    private function parseLimit(string $limit_str = null): array
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
    private function parseOrder(string $order = null, string $tb): array
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