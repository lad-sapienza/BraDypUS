<?php

declare(strict_types=1);

namespace Tests\Unit;

use Chrono\Parser;
use PHPUnit\Framework\TestCase;

class ChronoParserTest extends TestCase
{
    // ── Undated ───────────────────────────────────────────────────────────────

    public function testEmptyStringIsUndated(): void
    {
        $r = Parser::parse('');
        $this->assertNull($r['from']);
        $this->assertNull($r['to']);
        $this->assertTrue($r['valid']);
    }

    public function testQuestionMarkIsUndated(): void
    {
        $r = Parser::parse('?');
        $this->assertNull($r['from']);
        $this->assertNull($r['to']);
        $this->assertSame('Undated', $r['label']);
    }

    // ── Full centuries ────────────────────────────────────────────────────────

    public function testFullCenturyBCE(): void
    {
        $r = Parser::parse('c4 BCE');
        $this->assertSame(-400, $r['from']);
        $this->assertSame(-301, $r['to']);
        $this->assertSame('4th cent. BCE', $r['label']);
    }

    public function testFullCenturyCE(): void
    {
        $r = Parser::parse('c4 CE');
        $this->assertSame(301, $r['from']);
        $this->assertSame(400, $r['to']);
        $this->assertSame('4th cent. CE', $r['label']);
    }

    public function testFirstCenturyBCE(): void
    {
        $r = Parser::parse('c1 BCE');
        $this->assertSame(-100, $r['from']);
        $this->assertSame(-1, $r['to']);
    }

    public function testFirstCenturyCE(): void
    {
        $r = Parser::parse('c1 CE');
        $this->assertSame(1, $r['from']);
        $this->assertSame(100, $r['to']);
    }

    // ── Qualifiers BCE ────────────────────────────────────────────────────────

    public function testEarlyCenturyBCE(): void
    {
        $r = Parser::parse('c4e BCE');
        $this->assertSame(-400, $r['from']);
        $this->assertSame(-376, $r['to']);
        $this->assertSame('Early 4th cent. BCE', $r['label']);
    }

    public function testMidCenturyBCE(): void
    {
        $r = Parser::parse('c4m BCE');
        $this->assertSame(-375, $r['from']);
        $this->assertSame(-326, $r['to']);
        $this->assertSame('Mid 4th cent. BCE', $r['label']);
    }

    public function testLateCenturyBCE(): void
    {
        $r = Parser::parse('c4l BCE');
        $this->assertSame(-325, $r['from']);
        $this->assertSame(-301, $r['to']);
        $this->assertSame('Late 4th cent. BCE', $r['label']);
    }

    public function testFirstHalfCenturyBCE(): void
    {
        $r = Parser::parse('c4h1 BCE');
        $this->assertSame(-400, $r['from']);
        $this->assertSame(-351, $r['to']);
        $this->assertSame('First half of 4th cent. BCE', $r['label']);
    }

    public function testSecondHalfCenturyBCE(): void
    {
        $r = Parser::parse('c4h2 BCE');
        $this->assertSame(-350, $r['from']);
        $this->assertSame(-301, $r['to']);
        $this->assertSame('Second half of 4th cent. BCE', $r['label']);
    }

    public function testThirdQuarterCenturyBCE(): void
    {
        $r = Parser::parse('c4q3 BCE');
        $this->assertSame(-350, $r['from']);
        $this->assertSame(-326, $r['to']);
        $this->assertSame('Third quarter of 4th cent. BCE', $r['label']);
    }

    // ── Qualifiers CE ─────────────────────────────────────────────────────────

    public function testMidCenturyCE(): void
    {
        $r = Parser::parse('c3m CE');
        $this->assertSame(226, $r['from']);
        $this->assertSame(275, $r['to']);
        $this->assertSame('Mid 3rd cent. CE', $r['label']);
    }

    public function testSecondHalfThirteenthCenturyCE(): void
    {
        $r = Parser::parse('c13h2 CE');
        $this->assertSame(1251, $r['from']);
        $this->assertSame(1300, $r['to']);
        $this->assertSame('Second half of 13th cent. CE', $r['label']);
    }

    // ── Year tokens ───────────────────────────────────────────────────────────

    public function testNegativeYear(): void
    {
        $r = Parser::parse('-350');
        $this->assertSame(-350, $r['from']);
        $this->assertSame(-350, $r['to']);
        $this->assertSame('350 BCE', $r['label']);
    }

    public function testPositiveYearWithBCE(): void
    {
        $r = Parser::parse('350 BCE');
        $this->assertSame(-350, $r['from']);
        $this->assertSame(-350, $r['to']);
    }

    public function testPositiveYearWithCE(): void
    {
        $r = Parser::parse('300 CE');
        $this->assertSame(300, $r['from']);
        $this->assertSame(300, $r['to']);
        $this->assertSame('300 CE', $r['label']);
    }

    public function testBarePositiveYearIsCE(): void
    {
        $r = Parser::parse('4');
        $this->assertSame(4, $r['from']);
        $this->assertSame(4, $r['to']);
        $this->assertSame('4 CE', $r['label']);
    }

    public function testSmallYearWithBCEIsNotCentury(): void
    {
        // '4 BCE' = year 4 BCE, not 4th century BCE (no 'c' prefix)
        $r = Parser::parse('4 BCE');
        $this->assertSame(-4, $r['from']);
        $this->assertSame(-4, $r['to']);
    }

    // ── Ante / post quem ─────────────────────────────────────────────────────

    public function testAnteQuemCentury(): void
    {
        $r = Parser::parse('?/c4q3 BCE');
        $this->assertNull($r['from']);
        $this->assertSame(-326, $r['to']);
        $this->assertStringContainsString('Ante quem', $r['label']);
    }

    public function testAnteQuemYear(): void
    {
        $r = Parser::parse('?/-300');
        $this->assertNull($r['from']);
        $this->assertSame(-300, $r['to']);
    }

    public function testPostQuemCentury(): void
    {
        $r = Parser::parse('c4l BCE/?');
        $this->assertSame(-325, $r['from']);
        $this->assertNull($r['to']);
        $this->assertStringContainsString('Post quem', $r['label']);
    }

    public function testDoubleQuestionMark(): void
    {
        $r = Parser::parse('?/?');
        $this->assertNull($r['from']);
        $this->assertNull($r['to']);
        $this->assertSame('Undated', $r['label']);
    }

    // ── Explicit ranges ───────────────────────────────────────────────────────

    public function testYearRange(): void
    {
        $r = Parser::parse('-350/-300');
        $this->assertSame(-350, $r['from']);
        $this->assertSame(-300, $r['to']);
    }

    public function testCenturyRange(): void
    {
        $r = Parser::parse('c4l BCE/c3m CE');
        $this->assertSame(-325, $r['from']);
        $this->assertSame(275, $r['to']);
        $this->assertSame('Late 4th cent. BCE – Mid 3rd cent. CE', $r['label']);
    }

    // ── format() helper ───────────────────────────────────────────────────────

    public function testFormatUndated(): void
    {
        $this->assertSame('Undated', Parser::format(null, null));
    }

    public function testFormatAnteQuem(): void
    {
        $this->assertSame('Ante quem: 300 BCE', Parser::format(null, -300));
    }

    public function testFormatPostQuem(): void
    {
        $this->assertSame('Post quem: 350 BCE', Parser::format(-350, null));
    }

    public function testFormatPoint(): void
    {
        $this->assertSame('300 BCE', Parser::format(-300, -300));
    }

    public function testFormatRange(): void
    {
        $this->assertSame('350 BCE – 300 BCE', Parser::format(-350, -300));
    }

    // ── Invalid input ─────────────────────────────────────────────────────────

    public function testInvalidTokenReturnsInvalid(): void
    {
        $r = Parser::parse('foobar');
        $this->assertFalse($r['valid']);
        $this->assertNotNull($r['error']);
    }

    public function testCaseInsensitive(): void
    {
        $r = Parser::parse('c4l bce');
        $this->assertTrue($r['valid']);
        $this->assertSame(-325, $r['from']);
    }
}
