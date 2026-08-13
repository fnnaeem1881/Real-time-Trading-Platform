<?php

declare(strict_types=1);

namespace TradingPlatform\MarketData;

use TradingPlatform\Support\Decimal;

/**
 * Consolidated best bid/offer (CBBO) across venues.
 *
 * Holds the latest tick per venue and derives the best executable market:
 * highest bid and lowest ask across all venues. A bid on venue A above the ask
 * on venue B is a cross-venue arbitrage, which the aggregator surfaces.
 */
final class Aggregator
{
    /** @var array<string,MarketTick> latest tick per venue */
    private array $byVenue = [];

    public function update(MarketTick $tick): void
    {
        $this->byVenue[$tick->venue] = $tick;
    }

    public function bestBid(): ?array
    {
        $best = null;
        foreach ($this->byVenue as $venue => $t) {
            if ($best === null || $t->bid->gt($best['price'])) {
                $best = ['venue' => $venue, 'price' => $t->bid];
            }
        }

        return $best;
    }

    public function bestAsk(): ?array
    {
        $best = null;
        foreach ($this->byVenue as $venue => $t) {
            if ($best === null || $t->ask->lt($best['price'])) {
                $best = ['venue' => $venue, 'price' => $t->ask];
            }
        }

        return $best;
    }

    public function consolidatedMid(): ?Decimal
    {
        $b = $this->bestBid();
        $a = $this->bestAsk();

        return ($b && $a) ? $b['price']->add($a['price'])->div(Decimal::of(2)) : null;
    }

    /**
     * Cross-venue arbitrage opportunity, if any: buy the cheapest ask, sell the
     * richest bid, when bid > ask across venues.
     *
     * @return array{buyVenue:string,sellVenue:string,edge:float}|null
     */
    public function arbitrage(): ?array
    {
        $bestBid = $this->bestBid();
        $bestAsk = $this->bestAsk();
        if ($bestBid === null || $bestAsk === null) {
            return null;
        }
        if ($bestBid['price']->gt($bestAsk['price']) && $bestBid['venue'] !== $bestAsk['venue']) {
            return [
                'buyVenue' => $bestAsk['venue'],
                'sellVenue' => $bestBid['venue'],
                'edge' => $bestBid['price']->sub($bestAsk['price'])->toFloat(),
            ];
        }

        return null;
    }

    /** @return array<string,MarketTick> */
    public function venues(): array
    {
        return $this->byVenue;
    }
}
