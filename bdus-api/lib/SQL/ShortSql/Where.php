<?php
/**
 * @copyright 2007-2021 Julian Bogdani
 * @license AGPL-3.0; see LICENSE
 */

declare(strict_types=1);

namespace SQL\ShortSql;

use SQL\SqlException;
use SQL\ShortSql\Field;
use SQL\ShortSql\SubQuery;
use SQL\ShortSql\ParseShortSql;

use \Config\Config;


class Where
{
    static private $cfg;
    static private $field_aliases;
    static private $parseShortSql;
    static private $prefix;

    /**
     * Undocumented function
     *
     * @param Config $cfg
     * @param string $str
     * @param string $tb
     * @param boolean $noValues
     * @param array $field_aliases
     * @param ParseShortSql $parseShortSql
     * @param string $prefix
     * @return array
     */
    public static function parse(Config $cfg, string $str = null, string $tb, bool $noValues = false, array $field_aliases = [], ParseShortSql $parseShortSql, string $prefix ) : array
    {
        self::$cfg = $cfg;
        self::$field_aliases = $field_aliases;
        self::$parseShortSql = $parseShortSql;
        self::$prefix = $prefix;

        $sql_values = [];
        $sql_parts = [];
        
        $where_parts = explode('||', $str);

        if (!is_array($where_parts)){
            throw new SqlException("Invalid input string: `$str`");
        }

        foreach ($where_parts as $index => $part) {
            list($p, $v) = self::parsePart($part, $index, $tb, $noValues);
            array_push($sql_parts, $p);
            // If value element is a field name, do not add value to list
            if(isset($v)) {
                if (\is_string($v)) {
                    array_push($sql_values, $v);
                } else {
                    $sql_values = array_merge($sql_values, $v);
                }
            }
        }
        return [
            "sql_parts"  => $sql_parts, 
            "sql_values" => $sql_values
        ];
    }

    private static function parsePart (string $part, int $index = 0, string $tb, bool $noValues = false ) : array
    {
        // Explode string in parts
        $mini_parts = explode('|', $part);
        if (!is_array($mini_parts) || empty($mini_parts)){
            throw new SqlException("Invalid part: `$part`. Syntax error");
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
            throw new SqlException("Invalid part: `$part`. First block must contain 3 parts");
        }

        // Block other than first of the where statement must contain 4 parts, the first being the connector
        if ($index > 0 && count($mini_parts) !== 4) {
            throw new SqlException("Invalid part: `$part`. Blocks other than first must contain 4 parts");
        }

        $connector = null;
        // Set $fld, $operator, $value and $connector (might be false)
        if ($index > 0){
            list($connector, $fld, $operator, $value) = $mini_parts;
            
            // Connectors, as operators, are uppercase for better readability
            $connector = strtoupper($connector);
        } else {
            list($fld, $operator, $value) = $mini_parts;
        }

        // Operators, as connectors, are uppercase
        $operator = strtoupper($operator); // Always upper case

        // Set $tb, $fld and possibly $alias
        $parsedFld = Field::parse(self::$prefix, $fld, $tb);
        $fld_tb = $parsedFld['tb'];
        $fld    = $parsedFld['fld'];
        $alias  = $parsedFld['alias'];
        $function = $parsedFld['fn'];
        unset($parsedFld);

        if (!\in_array($fld, self::$field_aliases)) {
            $fld = "$fld_tb.$fld";
        }
        
        // Set value
        // If the caret is the first char, the value is not a string: it is a field name
        if ($value[0] === '^') {
            if (\is_numeric(substr($value, 1))){
                $binded = substr($value, 1);
            } else {
                $parsedFld = Field::parse(self::$prefix, substr($value, 1));
                $binded_tb = $parsedFld['tb'];
                $binded_fld    = $parsedFld['fld'];
                $binded_alias  = $parsedFld['alias'];
                $binded = "$binded_tb.$binded_fld";
                unset($binded_tb);
                unset($binded_fld);
                unset($parsedFld);
            }
            unset($value);
        } else if (($value[0] === '<')) {
            list ($sub_query, $sub_values) = SubQuery::parse(substr($value, 1), self::$parseShortSql);
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
            $value
        ]);
    }


}