<?php

declare(strict_types=1);

namespace TradingPlatform\Risk;

/**
 * Value-at-Risk and related tail metrics.
 *
 *  - Historical VaR: the empirical quantile of the P&L distribution.
 *  - Parametric VaR: mean - z*sigma under a normal assumption.
 *  - CVaR / Expected Shortfall: the average loss *beyond* the VaR threshold —
 *    the number that actually tells you how bad the tail is.
 *
 * Convention: VaR is reported as a positive loss magnitude at a confidence level
 * (e.g. 95%), expressed in the currency of the P&L series.
 */
final class RiskMetrics
{
    /**
     * Historical VaR at confidence `conf` (e.g. 0.95) from a P&L series.
     *
     * @param list<float> $pnls per-period P&L (currency)
     */
    public static function historicalVaR(array $pnls, float $conf = 0.95): float
    {
        if (count($pnls) < 2) {
            return 0.0;
        }
        sort($pnls);
        $idx = (int) floor((1 - $conf) * count($pnls));
        $idx = max(0, min($idx, count($pnls) - 1));
        $q = $pnls[$idx];

        return $q < 0 ? -$q : 0.0;
    }

    /**
     * Conditional VaR (Expected Shortfall): mean of losses at or beyond the VaR
     * quantile.
     *
     * @param list<float> $pnls
     */
    public static function conditionalVaR(array $pnls, float $conf = 0.95): float
    {
        if (count($pnls) < 2) {
            return 0.0;
        }
        sort($pnls);
        $cut = (int) floor((1 - $conf) * count($pnls));
        $cut = max(1, $cut);
        $tail = array_slice($pnls, 0, $cut);
        $avg = array_sum($tail) / count($tail);

        return $avg < 0 ? -$avg : 0.0;
    }

    /**
     * Parametric (variance-covariance) VaR: z-score of the confidence level times
     * the P&L standard deviation, minus the mean.
     *
     * @param list<float> $pnls
     */
    public static function parametricVaR(array $pnls, float $conf = 0.95): float
    {
        $n = count($pnls);
        if ($n < 2) {
            return 0.0;
        }
        $mean = array_sum($pnls) / $n;
        $var = 0.0;
        foreach ($pnls as $p) {
            $var += ($p - $mean) ** 2;
        }
        $std = sqrt($var / ($n - 1));
        $z = self::zScore($conf);
        $v = $z * $std - $mean;

        return $v > 0 ? $v : 0.0;
    }

    /** Inverse standard normal CDF (Acklam's approximation). */
    public static function zScore(float $conf): float
    {
        $p = $conf; // one-sided
        // Coefficients for the rational approximation.
        $a = [-3.969683028665376e+01, 2.209460984245205e+02, -2.759285104469687e+02, 1.383577518672690e+02, -3.066479806614716e+01, 2.506628277459239e+00];
        $b = [-5.447609879822406e+01, 1.615858368580409e+02, -1.556989798598866e+02, 6.680131188771972e+01, -1.328068155288572e+01];
        $c = [-7.784894002430293e-03, -3.223964580411365e-01, -2.400758277161838e+00, -2.549732539343734e+00, 4.374664141464968e+00, 2.938163982698783e+00];
        $d = [7.784695709041462e-03, 3.224671290700398e-01, 2.445134137142996e+00, 3.754408661907416e+00];
        $plow = 0.02425;
        $phigh = 1 - $plow;
        if ($p < $plow) {
            $q = sqrt(-2 * log($p));

            return ((((($c[0] * $q + $c[1]) * $q + $c[2]) * $q + $c[3]) * $q + $c[4]) * $q + $c[5]) / (((($d[0] * $q + $d[1]) * $q + $d[2]) * $q + $d[3]) * $q + 1);
        }
        if ($p <= $phigh) {
            $q = $p - 0.5;
            $r = $q * $q;

            return ((((($a[0] * $r + $a[1]) * $r + $a[2]) * $r + $a[3]) * $r + $a[4]) * $r + $a[5]) * $q / ((((($b[0] * $r + $b[1]) * $r + $b[2]) * $r + $b[3]) * $r + $b[4]) * $r + 1);
        }
        $q = sqrt(-2 * log(1 - $p));

        return -((((($c[0] * $q + $c[1]) * $q + $c[2]) * $q + $c[3]) * $q + $c[4]) * $q + $c[5]) / (((($d[0] * $q + $d[1]) * $q + $d[2]) * $q + $d[3]) * $q + 1);
    }
}
