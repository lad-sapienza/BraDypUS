<?php
/**
 * @copyright 2007-2021 Julian Bogdani
 * @license AGPL-3.0; see LICENSE
 */

declare(strict_types=1);

namespace SQL\ShortSql;

use SQL\SqlException;
use SQL\ShortSql\Field;


class Group
{


    /**
     * Parses array of ordering statemens and return array of arrays for ordering:
     * [ table-name.field-name, ... ]
     *
     * @param string $order
     * @param string $tb
     * @return array
     */
    public static function parse(string $prefix, string $group_str = null, string $tb) : array
    {
        if (!$group_str){
            return [];
        }
        $formatted_flds = array_map(function($f) use ($tb, $prefix){
            $parsedFld = Field::parse($prefix, $f, $tb);
            return $parsedFld['tb'] . '.' . $parsedFld['fld'];
        }, explode(',', $group_str));
        
        return $formatted_flds;
    }

    

}