<?php

declare(strict_types=1);

namespace TradingPlatform\Perf;

/**
 * A mutable, reusable event envelope pushed through the {@see RingBuffer} event
 * bus. Being mutable is the whole point: instances are drawn from an
 * {@see ObjectPool}, populated, consumed, and returned — so a busy step does not
 * allocate a fresh object per trade. This is the allocation-avoidance pattern
 * that keeps GC out of the hot path.
 */
final class MarketEvent
{
    public string $type = '';
    public string $symbol = '';
    public float $price = 0.0;
    public float $qty = 0.0;
    public string $side = '';

    public function reset(): void
    {
        $this->type = '';
        $this->symbol = '';
        $this->price = 0.0;
        $this->qty = 0.0;
        $this->side = '';
    }
}
