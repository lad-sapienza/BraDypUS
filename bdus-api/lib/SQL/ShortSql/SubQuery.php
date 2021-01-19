<?php
/**
 * @copyright 2007-2021 Julian Bogdani
 * @license AGPL-3.0; see LICENSE
 */

declare(strict_types=1);

namespace SQL\ShortSql;

use SQL\ShortSql\ParseShortSql;


class SubQuery
{

    public static function parse(string $base64url_string, ParseShortSql $parseShortSql ) : array
    {
        //  base64url_decode
        $decoded_string = base64_decode( strtr( $base64url_string, '-_', '+/') . str_repeat('=', 3 - ( 3 + strlen( $base64url_string )) % 4 ));

        $parseShortSql->parseAll($decoded_string);

        return $parseShortSql->getSql();

    }


}