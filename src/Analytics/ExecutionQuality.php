<?php

declare(strict_types=1);

namespace TradingPlatform\Analytics;

/**
 * Trade execution quality metrics (M4).
 *
 * Measures how well orders were executed relative to a reference (arrival) price:
 *
 *  - Slippage (bps): adverse price movement between decision and execution.
 *  - Fill rate: filled quantity vs requested.
 *  - Maker / taker mix: how much liquidity we provided vs took.
 *  - Implementation shortfall: total cost of execution vs the arrival price.
 */
final class ExecutionQuality
{
    private float $slippageBpsSum = 0.0;
    private float $requested = 0.0;
    private float $filled = 0.0;
    private int $maker = 0;
    private int $taker = 0;
    private int $count = 0;
    private float $shortfall = 0.0;

    /**
     * @param string $side BUY or SELL
     * @param float  $refPrice arrival/decision price
     * @param string $liquidity MAKER or TAKER
     */
    public function record(string $side, float $refPrice, float $execPrice, float $filledQty, float $requestedQty, string $liquidity): void
    {
        if ($refPrice <= 0) {
            return;
        }
        $sign = $side === 'BUY' ? 1.0 : -1.0;
        // Positive slippage = we paid worse than the reference.
        $slipBps = (($execPrice - $refPrice) / $refPrice) * 10_000 * $sign;

        $this->slippageBpsSum += $slipBps * $filledQty;
        $this->shortfall += ($execPrice - $refPrice) * $sign * $filledQty;
        $this->requested += $requestedQty;
        $this->filled += $filledQty;
        $liquidity === 'MAKER' ? $this->maker++ : $this->taker++;
        $this->count++;
    }

    /** @return array<string,float|int> */
    public function summary(): array
    {
        $totalLiq = max(1, $this->maker + $this->taker);

        return [
            'fills' => $this->count,
            'avgSlippageBps' => $this->filled > 0 ? round($this->slippageBpsSum / $this->filled, 3) : 0.0,
            'fillRatePct' => $this->requested > 0 ? round($this->filled / $this->requested * 100, 2) : 0.0,
            'makerPct' => round($this->maker / $totalLiq * 100, 1),
            'takerPct' => round($this->taker / $totalLiq * 100, 1),
            'implShortfall' => round($this->shortfall, 2),
        ];
    }

    /** @return array<string,float|int> */
    public function toState(): array
    {
        return ['slip' => $this->slippageBpsSum, 'req' => $this->requested, 'fil' => $this->filled,
            'mk' => $this->maker, 'tk' => $this->taker, 'n' => $this->count, 'sf' => $this->shortfall];
    }

    /** @param array<string,mixed> $s */
    public static function fromState(array $s): self
    {
        $q = new self();
        $q->slippageBpsSum = (float) ($s['slip'] ?? 0);
        $q->requested = (float) ($s['req'] ?? 0);
        $q->filled = (float) ($s['fil'] ?? 0);
        $q->maker = (int) ($s['mk'] ?? 0);
        $q->taker = (int) ($s['tk'] ?? 0);
        $q->count = (int) ($s['n'] ?? 0);
        $q->shortfall = (float) ($s['sf'] ?? 0);

        return $q;
    }
}
