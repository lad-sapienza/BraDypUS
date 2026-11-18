<?php

namespace Tests\Unit;

use Config\CharPalette;
use PHPUnit\Framework\Attributes\DataProvider;
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

    // ── Names: custom and built-in char maps ─────────────────────────────────

    public function testANameResolvesToTheApplicationsOwnMap(): void
    {
        $custom = ['epigrafia' => 'Α Β Γ', 'segni' => ['alpha' => 'α']];

        $this->assertSame([['Α', 'Α'], ['Β', 'Β'], ['Γ', 'Γ']], self::pairs(CharPalette::normalize('epigrafia', $custom)));
        $this->assertSame([['alpha', 'α']], self::pairs(CharPalette::normalize(' segni ', $custom)));
    }

    public function testAnUnknownNameShowsNothingInsteadOfAButtonWithTheName(): void
    {
        $this->assertNull(CharPalette::normalize('gone-map'));
        $this->assertNull(CharPalette::normalize('gone-map', ['other' => 'α']));
    }

    public function testInlineCharactersAndMapsStillWork(): void
    {
        $this->assertSame([['α', 'α'], ['β', 'β']], self::pairs(CharPalette::normalize('α β', ['x' => 'z'])));
        $this->assertSame([['a=', 'ᾱ']], self::pairs(CharPalette::normalize(['a=' => 'ᾱ'], [])));
    }

    public function testACustomMapIsNotFollowedAsAReference(): void
    {
        // custom "a-b" holding the text "c-d" is characters, and "c-d" is not looked up in the custom maps
        $this->assertNull(CharPalette::normalize('a-b', ['a-b' => 'c-d', 'c-d' => 'α']));
    }

    public function testSystemMapsAreExpandedIntoButtons(): void
    {
        $maps = CharPalette::systemMaps();

        $this->assertArrayHasKey('greek-polytonic', $maps);
        $this->assertContains('ῆ', array_column($maps['greek-polytonic'], 'value'));
    }

    // ── validateCustom ───────────────────────────────────────────────────────

    public function testValidCustomMapsAreKept(): void
    {
        $in = ['epigrafia' => ' Α Β ', 'segni' => ['alpha' => 'α'], 'lista-1' => ['α', ['label' => 'η', 'value' => 'ῆ']]];

        $this->assertSame(['epigrafia' => 'Α Β', 'segni' => ['alpha' => 'α'], 'lista-1' => $in['lista-1']], CharPalette::validateCustom($in));
        $this->assertSame([], CharPalette::validateCustom([]));
    }

    #[DataProvider('badCustomMaps')]
    public function testBadCustomMapsAreRefused(array $maps, string $needle): void
    {
        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessageMatches('/' . preg_quote($needle, '/') . '/');
        CharPalette::validateCustom($maps);
    }

    public static function badCustomMaps(): array
    {
        return [
            'name with capitals'     => [['Greek' => 'α'], 'Greek'],
            'name too short'         => [['a' => 'α'], 'a:'],
            'name with a space'      => [['my map' => 'α'], 'my map'],
            'name of a built-in one' => [['greek-polytonic' => 'α'], 'built-in'],
            'nothing usable'         => [['vuota' => '   '], 'no usable'],
            'empty list'             => [['vuota' => []], 'no usable'],
            'a name instead of text' => [['alias' => 'greek-polytonic'], 'not the name'],
            'not a string or array'  => [['num' => 5], 'no usable'],
            'too many'               => [array_combine(array_map(fn($i) => "map-$i", range(1, 51)), array_fill(0, 51, 'α')), 'too many'],
        ];
    }
}
