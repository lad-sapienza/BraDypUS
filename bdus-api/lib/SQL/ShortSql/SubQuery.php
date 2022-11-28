<?php

/**
 * @copyright 2007-2022 Julian Bogdani
 * @license AGPL-3.0; see LICENSE
 */

declare(strict_types=1);

namespace SQL\ShortSql;

use SQL\ShortSql\ParseShortSql;


class SubQuery
{

    public static function parse(
        string $base64url_string,
        ParseShortSql $parseShortSql,
        bool $disable_auto_join = false
    ): array {
        if ($base64url_string[0] === '!') {
            $base64url_string = \substr($base64url_string, 1);
            $disable_auto_join = true;
        }
        //  https://www.php.net/manual/en/function.base64-encode.php#123098
        $decoded_string = base64_decode(str_replace(['-', '_'], ['+', '/'], $base64url_string));
        // str_replace(['+','/','='], ['-','_',''], base64_encode($string));

        $parseShortSql->parseAll($decoded_string, $disable_auto_join);

        return $parseShortSql->getSql();
    }
}
