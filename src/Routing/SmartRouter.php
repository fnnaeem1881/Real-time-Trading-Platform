<?php

declare(strict_types=1);

namespace TradingPlatform\Routing;

use TradingPlatform\Order\Side;
use TradingPlatform\Support\Decimal;

/**
 * Smart order router: given the best bid/ask across multiple venues, pick the
 * venue offering the best executable price for the aggressing side.
 *
 *  - A buy routes to the venue with the lowest ask.
 *  - A sell routes to the venue with the highest bid.
 *
 * @phpstan-type Quote array{venue:string,bid:Decimal,ask:Decimal}
 */
final class SmartRouter
{
    /**
     * @param list<Quote> $quotes
     * @return array{venue:string,price:Decimal}|null
     */
    public function route(Side $side, array $quotes): ?array
    {
        $best = null;
        foreach ($quotes as $q) {
            $price = $side === Side::Buy ? $q['ask'] : $q['bid'];
            if ($best === null) {
                $best = ['venue' => $q['venue'], 'price' => $price];
                continue;
            }
            $better = $side === Side::Buy
                ? $price->lt($best['price'])   // cheaper ask is better to buy
                : $price->gt($best['price']);  // higher bid is better to sell
            if ($better) {
                $best = ['venue' => $q['venue'], 'price' => $price];
            }
        }

        return $best;
    }
}
