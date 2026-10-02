<?php

namespace Tests\Unit;

use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use SQL\ExportSearch;

/**
 * SQL\ExportSearch::fromParams() — what an export request's filter/qt/q mean,
 * and that anything else is an error rather than "the whole table" (#75).
 */
class ExportSearchTest extends TestCase
{
    private const FILTER = ['sigla' => ['_eq' => 'T002']];

    public function testNoSearchMeansAll(): void
    {
        $this->assertSame(['type' => 'all'], ExportSearch::fromParams(null, null, null));
        // empty values are the same as absent ones
        $this->assertSame(['type' => 'all'], ExportSearch::fromParams('', '', ''));
    }

    public function testFilterParameterWinsAndAcceptsArrayOrJson(): void
    {
        $expected = ['type' => 'filter', 'filter' => self::FILTER];
        $this->assertSame($expected, ExportSearch::fromParams(self::FILTER, null, null));
        $this->assertSame($expected, ExportSearch::fromParams(json_encode(self::FILTER), null, null));
        // ...even when qt/q are present too
        $this->assertSame($expected, ExportSearch::fromParams(self::FILTER, 'fast', 'x'));
    }

    public function testFast(): void
    {
        $this->assertSame(['type' => 'fast', 'string' => 'US01'], ExportSearch::fromParams(null, 'fast', 'US01'));
    }

    public function testExpert(): void
    {
        $this->assertSame(
            ['type' => 'sqlExpert', 'querytext' => "tipo = 'Taglio'", 'join' => ''],
            ExportSearch::fromParams(null, 'expert', "tipo = 'Taglio'")
        );
    }

    public function testQtFilter(): void
    {
        $this->assertSame(
            ['type' => 'filter', 'filter' => self::FILTER],
            ExportSearch::fromParams(null, 'filter', json_encode(self::FILTER))
        );
    }

    public function testAdvancedTakesTheFilterKeyOfEitherPayload(): void
    {
        $expected = ['type' => 'filter', 'filter' => self::FILTER];
        $tree = json_encode(['tree' => ['t' => 'g', 'op' => 'AND', 'c' => []], 'filter' => self::FILTER]);
        $rows = json_encode(['rows' => [['fld' => 'x:sigla']], 'filter' => self::FILTER]);
        $this->assertSame($expected, ExportSearch::fromParams(null, 'advanced', $tree));
        $this->assertSame($expected, ExportSearch::fromParams(null, 'advanced', $rows));
    }

    /** @return array<string, array{0: mixed, 1: mixed, 2: mixed, 3: string}> */
    public static function badParams(): array
    {
        return [
            'filter not json'             => ['{nope', null, null, 'filter: not valid JSON'],
            'filter scalar'               => ['"abc"', null, null, 'filter: expected a filter object'],
            'filter json scalar number'   => ['5', null, null, 'filter: expected a filter object'],
            'q without qt'                => [null, null, 'x', 'q was given without qt'],
            'unknown qt'                  => [null, 'bogus', 'x', "qt: unknown value 'bogus'"],
            'qt as array'                 => [null, ['fast'], 'x', 'qt: expected a string'],
            'q as array'                  => [null, 'fast', ['x'], 'q: expected a string'],
            'qt without q'                => [null, 'fast', null, 'q is required'],
            'qt with empty q'             => [null, 'expert', '', 'q is required'],
            'qt=filter not json'          => [null, 'filter', 'not json', 'q (qt=filter): not valid JSON'],
            'qt=filter scalar'            => [null, 'filter', '123', 'q (qt=filter): expected a filter object'],
            'advanced not json'           => [null, 'advanced', 'not json', 'q (qt=advanced)'],
            'advanced without filter key' => [null, 'advanced', '{"tree":{}}', 'q (qt=advanced)'],
            'advanced filter not object'  => [null, 'advanced', '{"filter":"x"}', 'expected a filter object'],
        ];
    }

    #[DataProvider('badParams')]
    public function testBadParametersAreErrorsNotFallbacks(mixed $filter, mixed $qt, mixed $q, string $message): void
    {
        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessage($message);
        ExportSearch::fromParams($filter, $qt, $q);
    }
}
