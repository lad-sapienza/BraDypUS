<?php

/**
 * @copyright 2007-2022 Julian Bogdani
 * @license AGPL-3.0; see LICENSE
 */

declare(strict_types=1);

namespace SQL\ShortSql;

use SQL\SqlException;
use SQL\ShortSql\Where;
use SQL\ShortSql\Table;
use SQL\ShortSql\ParseShortSql;

use \Config\Config;


class Join
{

    /**
     * Gets array of JOIN statements and returns array of join arrays containing:
     *      table name, table alias, on statement
     *
     * ON conditions are always rendered without PDO placeholders (noValues = true).
     * This is intentional: JOIN clauses precede the WHERE clause in the assembled SQL,
     * so mixing parameterised ON values with WHERE values would require the caller to
     * split and reorder the PDO binding array. Inlining literals in JOIN ON avoids
     * that complexity. Subqueries inside JOIN ON are an unsupported edge case — if
     * ever needed they must be handled with a dedicated inline-binding strategy.
     *
     * @param array $join_arr
     * @return array
     */
    public static function parse(
        array $join_arr = null,
        Config $cfg,
        ParseShortSql $parseShortSql,
        array $added_fld_aliases = []
    ): array {
        if (!$join_arr) {
            return [];
        }

        $ret = [];

        // Loop in JOIN statements
        foreach ($join_arr as $join) {
            // Separate JOIN parts, ie. tb name from ON statement
            $j_parts = explode('||', $join);

            // Assign table name to $tb and remove from join parts
            $tb = array_shift($j_parts);

            // Format table name
            $parsedTb = Table::parse($tb);
            $tb = $parsedTb['tb'];
            $tb_alias = $parsedTb['alias'];
            unset($parsedTb);

            // Parse ON statement to SQL and values
            $parsedWhere = Where::parse(
                $cfg,
                implode('||', $j_parts),
                $tb,
                true,
                $added_fld_aliases,
                $parseShortSql
            );
            $on = $parsedWhere['sql_parts'];
            // sql_values is always empty here because noValues = true is passed to
            // Where::parse() above — literals are inlined, not parameterised.
            unset($parsedWhere);

            // Add values to return list
            array_push($ret, [
                "tb" => $tb,
                "alias" => $tb_alias,
                "on" => $on
            ]);
        }

        // Return array of JOIN statements: [ tb_name, tb_alias, on (where array) ]
        return $ret;
    }
}
