<?php

namespace Tests\Unit;

use Config\Identifier;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

class IdentifierTest extends TestCase
{
    #[DataProvider('names')]
    public function testIsValid(mixed $name, bool $expected): void
    {
        $this->assertSame($expected, Identifier::isValid($name));
    }

    public static function names(): array
    {
        return [
            'short'              => ['ab', true],
            'underscore'         => ['site_name', true],
            'digits'             => ['data_c14', true],
            'old 15 limit'       => ['a_name_16_chars_', true],
            '63 chars'           => [str_repeat('a', 63), true],
            '64 chars'           => [str_repeat('a', 64), false],
            'one char'           => ['a', false],
            'starts with digit'  => ['1abc', false],
            'starts with _'      => ['_abc', false],
            'uppercase'          => ['Sites', false],
            'space'              => ['my site', false],
            'hyphen'             => ['my-site', false],
            'empty'              => ['', false],
            'not a string'       => [null, false],
        ];
    }
}
