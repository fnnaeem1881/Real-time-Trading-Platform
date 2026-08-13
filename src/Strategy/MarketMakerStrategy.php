<?php

declare(strict_types=1);

namespace TradingPlatform\Strategy;

use TradingPlatform\Order\OrderType;
use TradingPlatform\Order\Side;
use TradingPlatform\Order\TimeInForce;
use TradingPlatform\Support\Decimal;

/**
 * Inventory-aware market maker. Quotes a two-sided market around the mid,
 * capturing the spread. As inventory builds up, it skews quotes to lean against
 * the position (raising the bid distance / tightening the ask when long) so it
 * naturally mean-reverts its inventory toward flat — the core risk control of
 * market making.
 */
final class MarketMakerStrategy implements Strategy
{
    public function __construct(
        private readonly float $halfSpreadBps = 8.0,   // quote half-spread in bps
        private readonly float $clipSize = 0.15,        // size per quote
        private readonly float $maxInventory = 1.5,     // skew hard as we approach this
        private readonly float $skewBps = 6.0,          // max additional skew in bps
    ) {}

    public function name(): string
    {
        return 'MARKET_MAKER';
    }

    public function onTick(StrategyContext $ctx): array
    {
        if ($ctx->mid === null) {
            return [];
        }
        $mid = $ctx->mid->toFloat();
        $inv = $ctx->position->toFloat();

        // Inventory skew in [-1, 1]; positive inventory pushes quotes down.
        $skewFrac = max(-1.0, min(1.0, $inv / $this->maxInventory));
        $half = $mid * $this->halfSpreadBps / 10_000.0;
        $skew = $mid * $this->skewBps / 10_000.0 * $skewFrac;

        $bidPrice = $mid - $half - $skew;
        $askPrice = $mid + $half - $skew;

        // Stop quoting the side that would grow inventory past the cap.
        $intents = [];
        if ($inv < $this->maxInventory) {
            $intents[] = new OrderIntent(Side::Buy, OrderType::Limit, TimeInForce::GTC, Decimal::of($bidPrice), Decimal::of($this->clipSize));
        }
        if ($inv > -$this->maxInventory) {
            $intents[] = new OrderIntent(Side::Sell, OrderType::Limit, TimeInForce::GTC, Decimal::of($askPrice), Decimal::of($this->clipSize));
        }

        return $intents;
    }
}
