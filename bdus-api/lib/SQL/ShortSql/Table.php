<?php
/**
 * @copyright 2007-2021 Julian Bogdani
 * @license AGPL-3.0; see LICENSE
 */

declare(strict_types=1);

namespace SQL\ShortSql;

class Table
{

    public static function parse( string $prefix, string $tb ) : array
    {
        list($tb, $alias) = explode(':', $tb);

        // If table name has no prefix, add it
        if (strpos($tb, $prefix) === false) {
            $tb = $prefix . $tb;
        }

        return [
            "tb"    => $tb, 
            "alias" => $alias
        ];
    }


}