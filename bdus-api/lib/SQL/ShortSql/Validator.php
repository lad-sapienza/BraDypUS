<?php
/**
 * @copyright 2007-2021 Julian Bogdani
 * @license AGPL-3.0; see LICENSE
 */

declare(strict_types=1);

namespace SQL\ShortSql;

use \Config\Config;
use SQL\ShortSql\ShortSqlException;


class Validator
{   

    private $cfg;

    public function __construct(Config $cfg)
    {
        $this->cfg = $cfg;
    }

    /**
     * Checks if table is available in cfg
     *
     * @param string $tb    Complete table name (with prefix)
     * @throws ShortSqlException    On Error
     * @return true         On Success
     */
    public function isValidTable ( string $tb ) : bool
    {
        $all_tbs = array_keys($this->cfg->get('tables.*.name'));

        if (!in_array($tb, $all_tbs)){
            throw new ShortSqlException("Not valid table: $tb");
        }
        return true;
    }

    /**
     * Checks if fld is available in cfg
     *
     * @param string $fld   Firld name
     * @param string $tb    Full table name
     * @throws ShortSqlException    On error
     * @return bool
     */
    public function isValidFld ( $fld, $tb ) : bool
    {
        if ($fld === '*'){
            return true;
        }

        // Get list of fields from configuration files
        $flds = array_keys($this->cfg->get("tables.$tb.fields.*.name"));

        // Add system fields, not available usually on cfg files
        array_push($flds, 'table_link');
        array_push($flds, 'id_link');

        // proved field must be in fields array; no alien fields are supported
        if (!in_array($fld, $flds)){
            throw new ShortSqlException("The field $fld is not available for table $tb");
        }
        return true;
    }

    /**
     * Checks if operator is valid
     *
     * @param string $operator  Operator to check
     * @throws ShortSqlException        On errors
     * @return boolean
     */
    public function isValidOperator($operator)
    {
        $valid_operators = [ 
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
        // Validate connector
        if (!in_array(strtolower($operator), $valid_operators)) {
            throw new ShortSqlException("Operator '{$operator}' non valid. Only " . implode(", ", $valid_operators) . " are allowed");
        }
        return true;
    }


    /**
     * Checks if connector is valid
     *
     * @param string $connector Connector to check
     * @throws ShortSqlException        On errors
     * @return boolean
     */
    public function isValidConnector($connector)
    {
        $valid_connectors = ['and', 'or'];
        // Validate connector
        if (!in_array(strtolower($connector), $valid_connectors)) {
            throw new ShortSqlException("Connector '{$connector}' non valid. Only " . implode(", ", $valid_connectors) . " are allowed");
        }
        return true;
    }


    


    
}