<?php

class SafeQuery
{
    public static function encode( string $sql, array $values= [] ): string
    {
        $json = json_encode([ $sql, $values ]);
        return str_replace(['+','/','='], ['-','_',''], base64_encode($json));
    }

    public static function decode(string $string): array
    {
        $json_str = base64_decode(str_replace(['-','_'], ['+','/'], $string));
        return json_decode( $json_str, true );
    }
}