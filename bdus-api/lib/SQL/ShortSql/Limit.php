<?php

/**
 * @copyright 2007-2021 Julian Bogdani
 * @license AGPL-3.0; see LICENSE
 */

declare(strict_types=1);

namespace SQL\ShortSql;

use SQL\SqlException;


class Limit
{
    public static function parse(string $limit_str = null): array
    {
        if (!$limit_str) {
            return [];
        }

        list($rows, $offset) = explode(':', $limit_str);

        if (!is_numeric($rows)) {
            throw new SqlException("Invalid Limit string `$limit_str`: the first part must be an integer");
        }
        if ($offset && !is_numeric($offset)) {
            throw new SqlException("Invalid Limit string `$limit_str`: the second part must be an integer");
        }

        return [
            "rows"  => $rows,
            "offset" => $offset
        ];
    }
}
