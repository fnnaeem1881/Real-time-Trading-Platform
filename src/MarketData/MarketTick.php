<?php

declare(strict_types=1);

namespace TradingPlatform\MarketData;

use TradingPlatform\Support\Decimal;

/**
 * Canonical, venue-agnostic market tick. Raw Binance / Coinbase payloads are
 * normalized into this single shape so everything downstream is venue-neutral.
 */
final class MarketTick
{
    public function __construct(
        public readonly string $symbol,
        public readonly string $venue,
        public readonly Decimal $bid,
        public readonly Decimal $ask,
        public readonly Decimal $last,
        public readonly Decimal $volume,
        /** Exchange event time (ms). */
        public readonly int $exchangeTsMillis,
        /** Local receive time (ms) — the gap drives latency compensation. */
        public readonly int $localTsMillis,
    ) {}

    public function mid(): Decimal
    {
        return $this->bid->add($this->ask)->div(Decimal::of(2));
    }

    public function spread(): Decimal
    {
        return $this->ask->sub($this->bid);
    }

    /** One-way transport latency estimate in milliseconds. */
    public function latencyMillis(): int
    {
        return $this->localTsMillis - $this->exchangeTsMillis;
    }

    /** @return array<string,mixed> */
    public function toArray(): array
    {
        return [
            'symbol' => $this->symbol,
            'venue' => $this->venue,
            'bid' => $this->bid->toFloat(),
            'ask' => $this->ask->toFloat(),
            'last' => $this->last->toFloat(),
            'volume' => $this->volume->toFloat(),
            'exchangeTs' => $this->exchangeTsMillis,
            'localTs' => $this->localTsMillis,
            'latencyMs' => $this->latencyMillis(),
        ];
    }
}
