<?php

declare(strict_types=1);

namespace TradingPlatform\Analytics;

/**
 * Real-time P&L recorder and equity-curve store. Feeds the Sharpe/Sortino/
 * drawdown metrics and the dashboard's equity chart.
 */
final class PnL
{
    /** @var list<float> equity curve */
    private array $equity = [];
    /** @var list<float> per-step P&L deltas */
    private array $stepPnl = [];
    private float $lastEquity;

    public function __construct(private readonly float $startingEquity)
    {
        $this->lastEquity = $startingEquity;
        $this->equity[] = $startingEquity;
    }

    public function record(float $equity): void
    {
        $this->stepPnl[] = $equity - $this->lastEquity;
        $this->equity[] = $equity;
        $this->lastEquity = $equity;
        // Keep memory bounded for a long-running session.
        if (count($this->equity) > 5000) {
            array_shift($this->equity);
            array_shift($this->stepPnl);
        }
    }

    /** @return list<float> simple period returns of the equity curve */
    public function returns(): array
    {
        $out = [];
        $n = count($this->equity);
        for ($i = 1; $i < $n; $i++) {
            if ($this->equity[$i - 1] != 0.0) {
                $out[] = ($this->equity[$i] - $this->equity[$i - 1]) / $this->equity[$i - 1];
            }
        }

        return $out;
    }

    /** @return list<float> */
    public function equityCurve(): array
    {
        return $this->equity;
    }

    /** @return list<float> */
    public function stepPnl(): array
    {
        return $this->stepPnl;
    }

    public function totalPnl(): float
    {
        return $this->lastEquity - $this->startingEquity;
    }

    public function totalReturnPct(): float
    {
        return $this->startingEquity > 0 ? ($this->lastEquity / $this->startingEquity - 1) * 100 : 0.0;
    }

    /** @return array<string,float> */
    public function summary(): array
    {
        $returns = $this->returns();

        return [
            'equity' => $this->lastEquity,
            'totalPnl' => $this->totalPnl(),
            'totalReturnPct' => $this->totalReturnPct(),
            'sharpe' => Metrics::sharpe($returns),
            'sortino' => Metrics::sortino($returns),
            'maxDrawdown' => Metrics::maxDrawdown($this->equity),
        ];
    }
}
