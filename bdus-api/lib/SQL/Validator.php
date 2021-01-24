<?php
/**
 * @copyright 2007-2021 Julian Bogdani
 * @license AGPL-3.0; see LICENSE
 */

declare(strict_types=1);

namespace SQL;

use \Config\Config;
use SQL\SqlException;
use SQL\QueryObject;


class Validator
{   

    private $cfg;
    private $qo;
    private $valid_operators = [ 
        '=',
        '!=',
        'like',
        'not like',
        '<',
        '<=',
        '>',
        '>=',
        'is null',
        'is not null',
        'in'
    ];
    private $valid_connectors = ['and', 'or'];
    private $valid_functions = [ 'avg', 'count', 'max', 'min', 'sum', 'group_concat' ];


    public function __construct(Config $cfg)
    {
        $this->cfg = $cfg;
    }


    public function validateQueryObject(QueryObject $qo): bool
    {
        $this->qo = $qo;

        $this->isValidTable();

        // 2. Validate single fields
        $fields = $qo->get('fields');
        foreach ($fields as $fld) {
            if($fld['fld'] && !$fld['subQuery']) {
                $this->isValidFld($fld['fld'], $fld['tb'] );
            } else if(!$fld['fld'] && $fld['subQuery']) {
                // TODO: call self
            }
            if($fld['fn']) {
                if (!in_array(strtolower($fld['fn']), $this->valid_functions)) {
                    throw new SqlException("Function `{$fld['fn']}` non valid. Only " . implode(", ", $this->valid_functions) . " are allowed");
                }
            }
            
        }
        unset($fields);

        $joins = $qo->get('joins');
        if (\is_array($joins)){
            foreach ($joins as $join) {
                $this->isValidJoin($join);
            }
        }
        unset($joins);

        $where = $qo->get('where');
        $this->isValidWhere($where);
        unset($where);
        
        $group = $qo->get('group');
        $this->isValidGroup($group);
        unset($group);

        $order = $qo->get('order');
        $this->isValidOrder($order);
        unset($order);

        $limit = $qo->get('limit');
        $this->isValidLimit($limit);
        unset($limit);

        $values = $qo->get('values');
        $this->isValidValues($values);
        unset($values);

        return true;
    }


    private function isValidWhere( array $where) : bool
    {
        foreach ($where as $index => $wp) {
            $error_in = "index `{$index}` of " . json_encode($where, JSON_PRETTY_PRINT);
            if(!\is_array($wp)) {
                throw new SqlException("Where part should be an array in {$error_in}");
            }
            if ($index > 0 && !in_array(strtolower($$wp['connector']), $this->valid_connectors)) {
                throw new SqlException("Connector `{$$wp['connector']}` non valid. Only " . implode(", ", $this->valid_connectors) . " are allowed");
            }
            if ($wp['opened_bracket'] && $wp['opened_bracket'] !== "(") {
                throw new SqlException("If available, open bracket should be `(` in {$error_in}");
            }
            if ($wp['closed_bracket'] && $wp['closed_bracket'] !== ")") {
                throw new SqlException("If available, close bracket should be `)` in {$error_in}");
            }
            if (!$wp['fld']) {
                throw new SqlException("Index `fld` is required in {$error_in}");
            }
            $this->isValidFld($wp['fld']);
            
            if (!in_array(strtolower($wp['operator']), $this->valid_operators)) {
                throw new SqlException("Operator `{$wp['operator']}` non valid. Only " . implode(", ", $this->valid_operators) . " are allowed");
            }
            
            if (!$wp['binded']) {
                throw new SqlException("Index `binded` is required in {$error_in}");
            }
        }
        return true;
    }


    private function isValidJoin( array $join) : bool
    {
        if (!isset($join['tb'])){
            throw new SqlException("Missing required index `tb` in join: " . json_encode($join));
        }
        if (!isset($join['on']) || !\is_array($join['on'])){
            throw new SqlException("Missing required index `on` in join: " . json_encode($join));
        }
        // Validate $tb
        $this->isValidTable($join['tb'], $join['alias']);
        $this->isValidWhere($join['on']);

        return true;
    }


    private function isValidValues(array $values): bool
    {
        if (empty($values)){
            return true;
        }

        foreach ($values as $key => $value) {
            if (!is_int($key)){
                throw new SqlException("Values should be entered as indexed arrays: " . var_export($key, true));
            }
            if (\is_array($value)){
                throw new SqlException("Values should be a bi-dimentional array");
            }
        }

        return true;
    }


    private function isValidLimit(array $limit): bool
    {
        if (empty($limit)){
            return true;
        }
        if (!$limit['tot']){
            throw new SqlException("Missing `tot` index for limit  statement");
        }
        if (!is_numeric($limit['tot'])){
            throw new SqlException("Index `tot` for limit  statement is not numeric: ". var_export($limit, true));
        }
        if ($limit['offset'] !== 0 && !$limit['offset']){
            throw new SqlException("Missing index `offset` for limit  statement: " . var_export($limit, true));
        }
        if (!is_numeric($limit['offset'])){
            throw new SqlException("Index `offset` for limit  statement is not numeric: " . var_export($limit, true));
        }

        return true;
    }


    private function isValidOrder(array $order): bool
    {
        if (empty($order)){
            return true;
        }
        foreach ($order as $index => $or) {
            if (!$or['fld']){
                throw new SqlException("Missing `fld` index for order index " . ($index+1) . " statement");
            }
            if (!$or['dir']){
                throw new SqlException("Missing `dir` index for order index " . ($index+1) . " statement");
            }
            if (!\in_array(\strtolower($or['dir']), ['asc', 'desc'])) {
                throw new SqlException("Invalid order direction `{$od['dir']}` for order index " . ($index+1) . " statement");
            }
            if (\strpos($or['fld'], '.') !== false ){
                list($tb, $or['fld']) = explode('.', $or['fld']);
            } else {
                $tb = $this->qo->get('tb')['name'];
            }
            $this->isValidFld( $or['fld'], $tb );
        }
        return true;
    }


    private function isValidGroup(array $group): bool
    {
        if (empty($group)) {
            return true;
        }
        foreach ($group as $fld) {
            if (\strpos($fld, '.') !== false ){
                list($tb, $fld) = explode('.', $fld);
            } else {
                $tb = $this->qo->get('tb')['name'];
            }
            $this->isValidFld( $fld, $tb );
        }
        return true;
    }

    /**
     * Checks if table is available in cfg
     *
     * @throws SqlException     On Error
     * @return true             On Success
     */
    private function isValidTable ( string $tb = null, string $alias = null) : bool
    {
        $tb     = $tb ?? $this->qo->get('tb')['name'];
        $alias  = $alias ?? $this->qo->get('tb')['alias'];

        $all_tbs = array_keys($this->cfg->get('tables.*.name'));

        if (!in_array($tb, $all_tbs)){
            throw new SqlException("Not valid table: $tb");
        }

        if(!is_null($alias) && !is_string($alias)){
            throw new SqlException("Not valid table alias `$alias`. Should be string or null");
        }
        return true;
    }

    /**
     * Checks if fld is available in cfg
     *
     * @param string $fld   Field name (without table name)
     * @param string $tb    Full table name
     * @throws SqlException    On error
     * @return bool
     */
    public function isValidFld ( string $fld, string $tb = null ) : bool
    {
        /*
        Is valid if:
            is *
            $tb (core or plugin) has column $fld in cfg
            $tb is alias for table and table has $fld in cfg
        */
        if ($fld === '*'){
            return true;
        }
        if (\strpos($fld, '.') !== false ) {
            list($tb, $fld) = \explode('.', $fld);
        }
        if (!$tb){
            throw new SqlException("Cannot validate field `{$fld}` without table name");
        }

        // Get list of fields from configuration files: $tb is valid core|plugin table
        $flds = array_keys($this->cfg->get("tables.$tb.fields.*.name"));
        
        if (!$flds || !empty($flds)){
            // Not valid table: maybe an alias?
        }

        // Add system fields, not available usually on cfg files
        array_push($flds, 'table_link');
        array_push($flds, 'id_link');

        // proved field must be in fields array; no alien fields are supported
        if (!in_array($fld, $flds)){
            throw new SqlException("The field `$fld` is not available for table `$tb`");
        }
        return true;
    }

}