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
        // Get table info
        $tb = $qo->get('tb')['name'];
        
        // 1. Validates tabe name
        $this->isValidTable($tb);

        // 2. Validate single fields
        $fields = $qo->get('fields');
        foreach ($fields as $fld) {
            if($fld['fld'] && !$fld['subQuery']) {
                $this->isValidFld($fld['fld'], $fld['tb'] );
            } else if(!$fld['fld'] && $fld['subQuery']) {
                // TODO: call self
            }
            if($fld['fn']) {
                $this->isValidFunction($fld['fn']);
            }
            
        }
        unset($fields);
    }

    /**
     * Checks if table is available in cfg
     *
     * @param string $tb    Complete table name (with prefix)
     * @throws SqlException    On Error
     * @return true         On Success
     */
    public function isValidTable ( string $tb ) : bool
    {
        $all_tbs = array_keys($this->cfg->get('tables.*.name'));

        if (!in_array($tb, $all_tbs)){
            throw new SqlException("Not valid table: $tb");
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
    public function isValidFld ( $fld, $tb ) : bool
    {
        /*
        Id valid if:
            is *
            $tb (core or plugin) has column $fld in cfg
            $tb is alias for table and table has $fld in cfg
        */
        if ($fld === '*'){
            return true;
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

    /**
     * Checks if operator is valid
     *
     * @param string $operator  Operator to check
     * @throws SqlException        On errors
     * @return boolean
     */
    public function isValidOperator($operator)
    {
        // Validate connector
        if (!in_array(strtolower($operator), $this->valid_operators)) {
            throw new SqlException("Operator `{$operator}` non valid. Only " . implode(", ", $this->valid_operators) . " are allowed");
        }
        return true;
    }


    /**
     * Checks if connector is valid
     *
     * @param string $connector Connector to check
     * @throws SqlException        On errors
     * @return bool
     */
    public function isValidConnector( string $connector): bool
    {
        // Validate connector
        if (!in_array(strtolower($connector), $this->valid_connectors)) {
            throw new SqlException("Connector `{$connector}` non valid. Only " . implode(", ", $this->valid_connectors) . " are allowed");
        }
        return true;
    }

    /**
     * Checks if $function is valid function
     *
     * @param string $function  Function name to check
     * @throws SqlException on errors
     * @return boolean
     */
    public function isValidFunction( string $function ) : bool
    {
        if (!in_array(strtolower($function), $this->valid_functions)) {
            throw new SqlException("Function `{$function}` non valid. Only " . implode(", ", $this->valid_functions) . " are allowed");
        }
        return true;
    }

}