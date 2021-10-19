<?php

/**
 * @copyright 2007-2021 Julian Bogdani
 * @license AGPL-3.0; see LICENSE
 */

declare(strict_types=1);

namespace SQL\ShortSql;

use SQL\SqlException;
use SQL\ShortSql\Field;


class Order
{


    /**
     * Parses array of ordering statemens and return array of arrays for ordering:
     * [ table name, field name, ordering direction (ASC|DESC) ]
     *
     * @param string $order
     * @param string $tb
     * @return array
     */
    public static function parse(
        string $prefix,
        string $order = null,
        string $tb = null
    ): array {
        $ret_sort = [];

        if (!$order) {
            return $ret_sort;
        }

        $sort_arr = explode(',', $order);
        foreach ($sort_arr as $s) {
            list($fld, $order) = explode(':', $s);
            if (!$order || !in_array(strtolower($order), ['asc', 'desc'])) {
                $order = 'ASC';
            }
            $parsefFld = Field::parse($prefix, $fld, $tb);

            $tb = $parsefFld['tb'];
            $fld = $parsefFld['fld'];
            $alias = $parsefFld['alias'];

            if (!$tb) {
                throw new SqlException("Cannot get table name for column `{$fld}` used in ORDER");
            }
            \array_push($ret_sort, [
                "fld"   => "$tb.$fld",
                "dir"   => strtoupper($order)
            ]);
        }
        return $ret_sort;
    }
}
