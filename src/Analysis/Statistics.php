<?php

declare(strict_types=1);

namespace TradingPlatform\Analysis;

/**
 * Rolling market statistics: realized volatility and cross-asset correlation.
 * These feed both the market-analysis engine (M1) and portfolio risk (M3),
 * which is why correlation lives here and is reused by the risk layer.
 */
final class Statistics
{
    /**
     * Realized volatility = stddev of simple returns over the window, optionally
     * annualized by sqrt(periodsPerYear).
     *
     * @param list<float> $prices
     */
    public static function realizedVolatility(array $prices, float $annualizeFactor = 1.0): float
    {
        $returns = self::returns($prices);
        if (count($returns) < 2) {
            return 0.0;
        }

        return self::stddev($returns) * sqrt($annualizeFactor);
    }

    /**
     * Pearson correlation between two equal-length return series.
     *
     * @param list<float> $a
     * @param list<float> $b
     */
    public static function correlation(array $a, array $b): float
    {
        $n = min(count($a), count($b));
        if ($n < 2) {
            return 0.0;
        }
        $a = array_slice($a, -$n);
        $b = array_slice($b, -$n);
        $ma = array_sum($a) / $n;
        $mb = array_sum($b) / $n;
        $cov = $va = $vb = 0.0;
        for ($i = 0; $i < $n; $i++) {
            $da = $a[$i] - $ma;
            $db = $b[$i] - $mb;
            $cov += $da * $db;
            $va += $da * $da;
            $vb += $db * $db;
        }
        if ($va <= 1e-12 || $vb <= 1e-12) {
            return 0.0;
        }

        return $cov / sqrt($va * $vb);
    }

    /**
     * Correlation matrix across a set of named return series.
     *
     * @param array<string,list<float>> $series
     * @return array<string,array<string,float>>
     */
    public static function correlationMatrix(array $series): array
    {
        $keys = array_keys($series);
        $out = [];
        foreach ($keys as $i) {
            foreach ($keys as $j) {
                $out[$i][$j] = $i === $j ? 1.0 : round(self::correlation($series[$i], $series[$j]), 4);
            }
        }

        return $out;
    }

    /**
     * @param list<float> $prices
     * @return list<float> simple returns
     */
    public static function returns(array $prices): array
    {
        $out = [];
        $n = count($prices);
        for ($i = 1; $i < $n; $i++) {
            if ($prices[$i - 1] != 0.0) {
                $out[] = ($prices[$i] - $prices[$i - 1]) / $prices[$i - 1];
            }
        }

        return $out;
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

    /** @param list<float> $xs */
    public static function mean(array $xs): float
    {
        return $xs === [] ? 0.0 : array_sum($xs) / count($xs);
    }
}
