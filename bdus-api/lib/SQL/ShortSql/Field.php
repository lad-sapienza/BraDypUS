<?php
/**
 * @copyright 2007-2021 Julian Bogdani
 * @license AGPL-3.0; see LICENSE
 */

declare(strict_types=1);

namespace SQL\ShortSql;

use SQL\SqlException;
use SQL\ShortSql\Table;
use SQL\ShortSql\SubQuery;
use SQL\ShortSql\ParseShortSql;


class Field
{
    public static function parse(string $prefix, string $fld, string $tb = null, ParseShortSql $parseShortSql = null): array
    {

        $ret = [
            "subQuery" => null,
            "fld"   => null,
            "alias" => null,
            "tb"    => null,
            "fn"    => null
        ];

        $alias = null;
        // If alias is provided as colon-separated postfix, get it
        if (strpos($fld, ':') !== false) {
            list($fld, $alias) = explode(':', $fld);
            $ret['alias']   = $alias;
        }
        

        $fn = null;
        // if function name is provided pipe-separated, get it
        if (strpos($fld, '|') !== false){
            list($fld, $fn) = explode('|', $fld);
            $ret['fn']      = $fn;
        }

        // Support for subquery as column
        if ( $fld[0] === '<' ) {
            list($sub_query, $sub_values) = SubQuery::parse(substr($fld, 1), $parseShortSql);
            
            $ret['subQuery']   = $sub_query;
            $ret['values']     = $sub_values;
            return $ret;

        } elseif (strpos($fld, '.') !== false){
            // if table name is provided as dot-separated prefix, get it
            list($tb, $fld) = explode('.', $fld);
        }

        if (!$tb){
            throw new SqlException("Cannot get table name for column {$fld}");
        }

        // Add prefix to table name
        $parsedTb = Table::parse( $prefix,  $tb );
        $ret['tb']      = $parsedTb['tb'];
        $ret['fld']     = $fld;
        
        
        return $ret;
    }


}