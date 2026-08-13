<?php

declare(strict_types=1);

namespace TradingPlatform\Perf;

/**
 * Latency histogram with percentile estimation.
 *
 * Tail latency (p99, p99.9) is what matters in trading — an average hides the
 * pauses that cost you fills. We keep a bounded reservoir of recent samples (in
 * microseconds) and derive p50/p95/p99, plus a simple alert when p99 crosses a
 * budget.
 */
final class LatencyMonitor
{
    /** @var list<float> recent samples in microseconds */
    private array $samples = [];
    private float $sum = 0.0;
    private float $max = 0.0;
    private int $total = 0;

    public function __construct(
        private readonly int $window = 2048,
        private readonly float $p99BudgetUs = 1000.0, // 1ms budget
    ) {}

    /** Record one measurement in microseconds. */
    public function record(float $micros): void
    {
        $this->samples[] = $micros;
        $this->sum += $micros;
        $this->total++;
        $this->max = max($this->max, $micros);
        if (count($this->samples) > $this->window) {
            $old = array_shift($this->samples);
            $this->sum -= $old;
        }
    }

    /** Time a callable and record its latency; returns the callable's result. */
    public function time(callable $fn): mixed
    {
        $start = hrtime(true);
        $result = $fn();
        $this->record((hrtime(true) - $start) / 1000.0);

        return $result;
    }

    public function percentile(float $p): float
    {
        if ($this->samples === []) {
            return 0.0;
        }
        $sorted = $this->samples;
        sort($sorted);
        $idx = (int) ceil($p / 100 * count($sorted)) - 1;
        $idx = max(0, min($idx, count($sorted) - 1));

        return $sorted[$idx];
    }

    public function mean(): float
    {
        return $this->samples === [] ? 0.0 : $this->sum / count($this->samples);
    }

    public function overBudget(): bool
    {
        return $this->percentile(99) > $this->p99BudgetUs;
    }

    /** @return array<string,float|int|bool> */
    public function snapshot(): array
    {
        return [
            'count' => $this->total,
            'meanUs' => round($this->mean(), 2),
            'p50Us' => round($this->percentile(50), 2),
            'p95Us' => round($this->percentile(95), 2),
            'p99Us' => round($this->percentile(99), 2),
            'maxUs' => round($this->max, 2),
            'budgetUs' => $this->p99BudgetUs,
            'overBudget' => $this->overBudget(),
        ];
    }
}
