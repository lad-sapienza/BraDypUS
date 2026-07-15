<?php

declare(strict_types=1);

namespace Tests\Unit;

use Radiocarbon\Calibrator;
use PHPUnit\Framework\TestCase;

/**
 * Unit tests for Radiocarbon\Calibrator against the bundled IntCal20 curve.
 *
 * These are regression tests, not a validation against OxCal: the exact
 * bounding-range values are pinned to the current algorithm's output so any
 * unintended change to the calibration math is caught. See Calibrator's
 * class doc-comment for the documented deviations from full HPD calibration.
 */
class RadiocarbonCalibratorTest extends TestCase
{
    public function testReturnsOneAndTwoSigmaRanges(): void
    {
        $r = Calibrator::calibrate(3200, 40);

        $this->assertArrayHasKey('cal_1s', $r);
        $this->assertArrayHasKey('cal_2s', $r);
        $this->assertCount(2, $r['cal_1s']);
        $this->assertCount(2, $r['cal_2s']);
    }

    public function testTwoSigmaRangeContainsOneSigmaRange(): void
    {
        $cases = [[3200, 40], [3000, 30], [1950, 25], [500, 30], [10000, 60]];

        foreach ($cases as [$bp, $err]) {
            $r = Calibrator::calibrate($bp, $err);
            [$from1, $to1] = $r['cal_1s'];
            [$from2, $to2] = $r['cal_2s'];

            $this->assertLessThanOrEqual($from1, $from2, "2-sigma 'from' must be <= 1-sigma 'from' for BP $bp");
            $this->assertGreaterThanOrEqual($to1, $to2, "2-sigma 'to' must be >= 1-sigma 'to' for BP $bp");
            $this->assertLessThan($to1, $from1, "1-sigma range must be non-degenerate for BP $bp");
        }
    }

    public function testKnownReferencePoint3200(): void
    {
        // Regression pin — see class doc-comment on Calibrator.
        $r = Calibrator::calibrate(3200, 40);
        $this->assertSame([3386, 3450], $r['cal_1s']);
        $this->assertSame([3278, 3486], $r['cal_2s']);
    }

    public function testKnownReferencePointNearHallstattPlateau(): void
    {
        // 3000 BP falls in a well-known "wiggle" region of the curve (Hallstatt
        // plateau) — the calibrated range should be noticeably wider than a
        // date of similar error sitting on a steep part of the curve.
        $r = Calibrator::calibrate(3000, 30);
        [$from2, $to2] = $r['cal_2s'];
        $this->assertGreaterThan(150, $to2 - $from2, 'Plateau region should yield a wide 2-sigma range');
    }

    public function testSmallerErrorYieldsNarrowerOrEqualRange(): void
    {
        $tight = Calibrator::calibrate(500, 15);
        $loose = Calibrator::calibrate(500, 60);

        $tightWidth = $tight['cal_2s'][1] - $tight['cal_2s'][0];
        $looseWidth = $loose['cal_2s'][1] - $loose['cal_2s'][0];

        $this->assertLessThanOrEqual($looseWidth, $tightWidth);
    }

    public function testDefaultCurveIsIntcal20(): void
    {
        $withDefault = Calibrator::calibrate(2000, 30);
        $explicit    = Calibrator::calibrate(2000, 30, 'intcal20');
        $this->assertSame($withDefault, $explicit);
    }

    public function testRejectsNonPositiveError(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        Calibrator::calibrate(2000, 0);
    }

    public function testRejectsNegativeError(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        Calibrator::calibrate(2000, -10);
    }

    public function testRejectsUnsupportedCurve(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        Calibrator::calibrate(2000, 30, 'shcal20');
    }

    public function testRejectsBpOutsideCurveRange(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        Calibrator::calibrate(200000, 30);
    }
}
