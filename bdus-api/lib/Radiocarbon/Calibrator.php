<?php

/**
 * @copyright 2007-2025 Julian Bogdani
 * @license AGPL-3.0; see LICENSE
 *
 * Calibrates a radiocarbon (BP) determination against a published calibration
 * curve, returning bounding calendar-year ranges (calBP) at 1-sigma (68.2%)
 * and 2-sigma (95.4%) confidence.
 *
 * Algorithm (standard radiocarbon calibration, e.g. Bronk Ramsey 2009):
 *   1. Locate the curve segments whose 14C age is plausibly close to the
 *      sample's BP (within a wide margin), to bound the interpolation window.
 *      Searching the whole curve (not just a contiguous index range) matters
 *      because the curve is not strictly monotonic — separate cal BP regions
 *      can share similar 14C ages ("plateaus"), which is exactly what makes
 *      calibrated distributions multimodal.
 *   2. Linearly interpolate the curve onto a 1-calendar-year grid within that
 *      window.
 *   3. At each grid point, combine the sample's error with the curve's own
 *      uncertainty (quadrature sum) and compute the Gaussian probability
 *      density of the observed BP against the curve's 14C age.
 *   4. Normalise the densities, sort by density descending, and accumulate
 *      grid points until the target confidence mass is reached.
 *
 * Note: step 4 reports the *bounding* range (min/max cal BP among the
 * included points), not the exact (possibly disjoint) HPD regions. This is a
 * deliberate simplification — see plan/feature notes — good enough for
 * numeric search/filter but not a substitute for a full probability plot.
 */

declare(strict_types=1);

namespace Radiocarbon;

final class Calibrator
{
    private const CONFIDENCE_1SIGMA = 0.682689492137;
    private const CONFIDENCE_2SIGMA = 0.954499736104;

    private const SUPPORTED_CURVES = ['intcal20'];

    /** @var array<string, array<int, array{0:int,1:int,2:int}>> */
    private static array $curveCache = [];

    /**
     * @return array{cal_1s: array{0:int,1:int}, cal_2s: array{0:int,1:int}}
     */
    public static function calibrate(int $bp, int $bpError, string $curve = 'intcal20'): array
    {
        if ($bpError <= 0) {
            throw new \InvalidArgumentException('bp_error must be a positive integer');
        }
        if (!in_array($curve, self::SUPPORTED_CURVES, true)) {
            throw new \InvalidArgumentException("Unsupported calibration curve: '$curve'");
        }

        $curveData = self::loadCurve($curve);
        [$calFrom, $calTo] = self::findWindow($curveData, $bp, $bpError);

        $grid = self::interpolate($curveData, $calFrom, $calTo);

        $densities = [];
        $total     = 0.0;
        foreach ($grid as [$calBp, $c14Bp, $curveSigma]) {
            $combinedSigma = sqrt(($bpError ** 2) + ($curveSigma ** 2));
            $z = ($bp - $c14Bp) / $combinedSigma;
            $d = exp(-0.5 * $z * $z) / ($combinedSigma * sqrt(2 * M_PI));
            $densities[] = [$calBp, $d];
            $total += $d;
        }

        if ($total <= 0.0) {
            throw new \InvalidArgumentException('Unable to calibrate: zero probability mass in range');
        }

        return [
            'cal_1s' => self::boundingRange($densities, $total, self::CONFIDENCE_1SIGMA),
            'cal_2s' => self::boundingRange($densities, $total, self::CONFIDENCE_2SIGMA),
        ];
    }

    /**
     * @return array<int, array{0:int,1:int,2:int}>
     */
    private static function loadCurve(string $curve): array
    {
        if (!isset(self::$curveCache[$curve])) {
            self::$curveCache[$curve] = require __DIR__ . "/data/{$curve}.php";
        }
        return self::$curveCache[$curve];
    }

    /**
     * Finds the [calFrom, calTo] window (calendar years BP) covering every
     * curve point whose 14C age is plausibly compatible with the sample.
     *
     * @param array<int, array{0:int,1:int,2:int}> $curveData
     * @return array{0:int,1:int}
     */
    private static function findWindow(array $curveData, int $bp, int $bpError): array
    {
        $margin = max(6 * $bpError, 300);
        $minCal = null;
        $maxCal = null;

        foreach ($curveData as [$calBp, $c14Bp, $curveSigma]) {
            $combinedSigma = sqrt(($bpError ** 2) + ($curveSigma ** 2));
            if (abs($c14Bp - $bp) <= $margin + 4 * $combinedSigma) {
                $minCal = $minCal === null ? $calBp : min($minCal, $calBp);
                $maxCal = $maxCal === null ? $calBp : max($maxCal, $calBp);
            }
        }

        if ($minCal === null) {
            throw new \InvalidArgumentException('bp value is outside the calibration curve range');
        }

        $firstCal = $curveData[0][0];
        $lastCal  = $curveData[count($curveData) - 1][0];

        return [
            max($firstCal, $minCal - 20),
            min($lastCal, $maxCal + 20),
        ];
    }

    /**
     * Linearly interpolates the curve onto a 1-calendar-year grid within
     * [$calFrom, $calTo].
     *
     * @param array<int, array{0:int,1:int,2:int}> $curveData
     * @return array<int, array{0:int,1:float,2:float}>
     */
    private static function interpolate(array $curveData, int $calFrom, int $calTo): array
    {
        $grid = [];
        $n    = count($curveData);
        $idx  = 0;

        for ($cal = $calFrom; $cal <= $calTo; $cal++) {
            while ($idx < $n - 2 && $curveData[$idx + 1][0] < $cal) {
                $idx++;
            }

            [$cal0, $c140, $sigma0] = $curveData[$idx];
            [$cal1, $c141, $sigma1] = $curveData[min($idx + 1, $n - 1)];

            if ($cal1 === $cal0) {
                $c14   = $c140;
                $sigma = $sigma0;
            } else {
                $t     = ($cal - $cal0) / ($cal1 - $cal0);
                $c14   = $c140 + $t * ($c141 - $c140);
                $sigma = $sigma0 + $t * ($sigma1 - $sigma0);
            }

            $grid[] = [$cal, $c14, $sigma];
        }

        return $grid;
    }

    /**
     * @param array<int, array{0:int,1:float}> $densities
     * @return array{0:int,1:int}
     */
    private static function boundingRange(array $densities, float $total, float $confidence): array
    {
        usort($densities, fn($a, $b) => $b[1] <=> $a[1]);

        $cum      = 0.0;
        $included = [];
        foreach ($densities as [$calBp, $d]) {
            $cum += $d / $total;
            $included[] = $calBp;
            if ($cum >= $confidence) {
                break;
            }
        }

        return [(int) min($included), (int) max($included)];
    }
}
