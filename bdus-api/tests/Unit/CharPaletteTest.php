<?php

namespace Tests\Unit;

use Config\CharPalette;
use PHPUnit\Framework\TestCase;

class CharPaletteTest extends TestCase
{
    private static function pairs(?array $items): array
    {
        return array_map(fn($i) => [$i['label'], $i['value']], $items ?? []);
    }

    public function testASpaceSeparatedStringBecomesOneButtonPerCharacter(): void
    {
        $this->assertSame([['α', 'α'], ['β', 'β']], self::pairs(CharPalette::normalize(' α  β ')));
    }

    public function testAListTakesStringsAndLabelledItems(): void
    {
        $out = CharPalette::normalize(['α', ['label' => 'eta', 'value' => 'η'], ['value' => 'ω']]);

        $this->assertSame([['α', 'α'], ['eta', 'η'], ['ω', 'ω']], self::pairs($out));
    }

    public function testAMapIsLabelToValue(): void
    {
        $this->assertSame([['alpha', 'α'], ['a=', 'ᾱ']], self::pairs(CharPalette::normalize(['alpha' => 'α', 'a=' => 'ᾱ'])));
    }

    public function testAPresetIsExpandedByName(): void
    {
        $out = CharPalette::normalize('greek-polytonic');

        $this->assertGreaterThan(80, count($out));
        $this->assertContains('ῆ', array_column($out, 'value'));
    }

    public function testNothingUsableMeansNull(): void
    {
        foreach ([null, '', '   ', [], 5, true, [['label' => 'x']], ['a' => ''], [['value' => '']]] as $raw) {
            $this->assertNull(CharPalette::normalize($raw), var_export($raw, true));
        }
    }

    public function testJunkItemsAreSkippedAndTheRestKept(): void
    {
        $out = CharPalette::normalize(['α', 3, null, ['value' => ['x']], str_repeat('x', 200), 'β']);

        $this->assertSame([['α', 'α'], ['β', 'β']], self::pairs($out));
    }

    public function testTheListIsBounded(): void
    {
        $out = CharPalette::normalize(implode(' ', array_map(fn($i) => "c$i", range(1, 1000))));

        $this->assertCount(CharPalette::MAX_ITEMS, $out);
    }
}
