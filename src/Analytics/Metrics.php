<?php

declare(strict_types=1);

namespace TradingPlatform\Analytics;

/**
 * Risk-adjusted performance metrics computed from an equity/return series.
 *
 *  - Sharpe: excess return per unit of total volatility.
 *  - Sortino: excess return per unit of *downside* volatility (penalises only
 *    losses, which is what most traders actually care about).
 *  - Max drawdown: worst peak-to-trough decline of the equity curve.
 */
final class Metrics
{
    /**
     * Annualised Sharpe ratio.
     *
     * @param list<float> $returns per-period returns
     * @param float $rfPerPeriod risk-free rate per period (default 0)
     * @param float $periodsPerYear for annualisation (e.g. 252 daily)
     */
    public static function sharpe(array $returns, float $rfPerPeriod = 0.0, float $periodsPerYear = 252.0): float
    {
        $n = count($returns);
        if ($n < 2) {
            return 0.0;
        }
        $excess = array_map(static fn (float $r): float => $r - $rfPerPeriod, $returns);
        $mean = array_sum($excess) / $n;
        $std = self::stddev($excess);
        if ($std <= 1e-12) {
            return 0.0;
        }

        return ($mean / $std) * sqrt($periodsPerYear);
    }

    /**
     * Annualised Sortino ratio (downside deviation in the denominator).
     *
     * @param list<float> $returns
     */
    public static function sortino(array $returns, float $rfPerPeriod = 0.0, float $periodsPerYear = 252.0): float
    {
        $n = count($returns);
        if ($n < 2) {
            return 0.0;
        }
        $excess = array_map(static fn (float $r): float => $r - $rfPerPeriod, $returns);
        $mean = array_sum($excess) / $n;
        $downSq = 0.0;
        $downCount = 0;
        foreach ($excess as $e) {
            if ($e < 0) {
                $downSq += $e * $e;
                $downCount++;
            }
        }
        if ($downCount === 0) {
            return 0.0;
        }
        $downDev = sqrt($downSq / $n);
        if ($downDev <= 1e-12) {
            return 0.0;
        }

        return ($mean / $downDev) * sqrt($periodsPerYear);
    }

    /**
     * Maximum drawdown of an equity curve, as a positive fraction (0..1).
     *
     * @param list<float> $equity
     */
    public static function maxDrawdown(array $equity): float
    {
        $peak = -INF;
        $maxDd = 0.0;
        foreach ($equity as $e) {
            $peak = max($peak, $e);
            if ($peak > 0) {
                $dd = ($peak - $e) / $peak;
                $maxDd = max($maxDd, $dd);
            }
        }

        return $maxDd;
    }

    /** @param list<float> $xs */
    public static function stddev(array $xs): float
    {
        $n = count($xs);
        if ($n < 2) {
            return 0.0;
        }
        $mean = array_sum($xs) / $n;
        $var = 0.0;
        foreach ($xs as $x) {
            $var += ($x - $mean) ** 2;
        }

        return sqrt($var / ($n - 1));
    }
}
