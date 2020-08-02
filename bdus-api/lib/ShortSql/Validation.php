<?php
/**
 * @uses \cfg
 * @uses PREFIX
 */
namespace ShortSql;


class Validation
{   

    /**
     * Checks if operator is valid
     *
     * @param string $operator  Operator to check
     * @throws \Exception        On errors
     * @return boolean
     */
    public static function isValidOperator($operator)
    {
        $valid_operators = [ '=', '!=', 'like', 'not like', '<', '<=', '>', '>=', 'is null', 'is not null'];
        // Validate connector
        if (!in_array(strtolower($operator), $valid_operators)) {
            throw new \Exception("Operator `{$operator}` non valid. Only " . implode(", ", $valid_operators) . " are allowed");
        }
        return true;
    }


    /**
     * Checks if connector is valid
     *
     * @param string $connector Connector to check
     * @throws \Exception        On errors
     * @return boolean
     */
    public static function isValidConnector($connector)
    {
        $valid_connectors = ['and', 'or'];
        // Validate connector
        if (!in_array(strtolower($connector), $valid_connectors)) {
            throw new \Exception("Connector `{$connector}` non valid. Only " . implode(", ", $valid_connectors) . " are allowed");
        }
        return true;
    }


    /**
     * Checks if fld is available in cfg
     *
     * @param String $fld   Firld name
     * @param String $tb    Full table name
     * @throws \Exception    On error
     * @return true         On success
     */
    public static function isValidFld ( $fld, $tb )
    {
        // Get list of fields from configuration files
        $flds = array_keys(\cfg::fldEl($tb, 'all', 'name'));

        // Add system fields, not available usually on cfg files
        array_push($flds, 'table_link');
        array_push($flds, 'id_link');

        // proved field must be in fields array; no alien fields are supported
        if (!in_array($fld, $flds)){
            throw new \Exception("The field $fld is not available for table $tb");
        }
        return true;
    }


    /**
     * Checks if table is available in cfg
     *
     * @param String $tb    Complete table name (with prefix)
     * @throws \Exception    On Error
     * @return true         On Success
     */
    public static function isValidTable ( $tb )
    {
        $all_tbs = array_keys(\cfg::tbEl('all', 'name'));

        if (!in_array($tb, $all_tbs)){
            throw new \Exception("Not valid table: $tb");
        }
        return true;
    }
}