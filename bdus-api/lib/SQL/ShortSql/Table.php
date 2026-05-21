<?php

/**
 * @copyright 2007-2022 Julian Bogdani
 * @license AGPL-3.0; see LICENSE
 */

declare(strict_types=1);

namespace SQL\ShortSql;

class Table
{

    public static function parse(string $tb): array
    {
        list($tb, $alias) = explode(':', $tb);

        return [
            "tb"    => $tb,
            "alias" => $alias
        ];
    }
}
