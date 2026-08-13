<?php

declare(strict_types=1);

namespace TradingPlatform\Analytics;

/**
 * Minimal event-driven backtesting harness. Replays a price series through a
 * strategy callback that returns a signed target position each step; the harness
 * marks-to-market, tracks the equity curve, and reports risk-adjusted metrics.
 *
 * The same strategy signature can be driven live by the trading engine, so a
 * backtested strategy is deployable without rewriting.
 */
final class Backtest
{
    /**
     * @param list<float> $prices           mark price per step
     * @param callable(int,float,float):float $strategy (step, price, position) => target position
     * @param float $feeRate                per-notional trading fee
     * @return array<string,mixed>
     */
    public static function run(array $prices, callable $strategy, float $startingCash = 100_000.0, float $feeRate = 0.0002): array
    {
        $cash = $startingCash;
        $position = 0.0;
        $pnl = new PnL($startingCash);
        $trades = 0;

        foreach ($prices as $step => $price) {
            $target = (float) $strategy($step, $price, $position);
            $delta = $target - $position;
            if (abs($delta) > 1e-9) {
                $cost = $delta * $price;
                $fee = abs($cost) * $feeRate;
                $cash -= $cost + $fee;
                $position = $target;
                $trades++;
            }
            $equity = $cash + $position * $price;
            $pnl->record($equity);
        }

        $summary = $pnl->summary();
        $summary['trades'] = $trades;
        $summary['finalPosition'] = $position;
        $summary['equityCurve'] = $pnl->equityCurve();

        return $summary;
    }
}
