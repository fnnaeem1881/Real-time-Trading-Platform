<?php

declare(strict_types=1);

namespace TradingPlatform\Strategy;

use TradingPlatform\Order\OrderType;
use TradingPlatform\Order\Side;
use TradingPlatform\Order\TimeInForce;
use TradingPlatform\Support\Decimal;

/**
 * Signal-driven momentum strategy. Combines MACD histogram (trend) with RSI
 * (over-bought/over-sold filter) to take small directional positions with
 * marketable IOC orders. Flattens toward neutral when signals disagree.
 */
final class MomentumStrategy implements Strategy
{
    public function __construct(
        private readonly float $clipSize = 0.2,
        private readonly float $maxPosition = 1.0,
        private readonly float $rsiOverbought = 70.0,
        private readonly float $rsiOversold = 30.0,
    ) {}

    public function name(): string
    {
        return 'MOMENTUM';
    }

    public function onTick(StrategyContext $ctx): array
    {
        $rsi = $ctx->indicators['rsi'] ?? null;
        $hist = $ctx->indicators['macdHist'] ?? null;
        if ($rsi === null || $hist === null || $ctx->bestBid === null || $ctx->bestAsk === null) {
            return [];
        }

        $pos = $ctx->position->toFloat();
        $wantLong = $hist > 0 && $rsi < $this->rsiOverbought;
        $wantShort = $hist < 0 && $rsi > $this->rsiOversold;

        // Marketable IOC to cross the spread when we have conviction.
        if ($wantLong && $pos < $this->maxPosition) {
            return [new OrderIntent(Side::Buy, OrderType::Market, TimeInForce::IOC, null, Decimal::of($this->clipSize))];
        }
        if ($wantShort && $pos > -$this->maxPosition) {
            return [new OrderIntent(Side::Sell, OrderType::Market, TimeInForce::IOC, null, Decimal::of($this->clipSize))];
        }

        // Mean-revert toward flat when momentum fades.
        if (!$wantLong && !$wantShort && abs($pos) > 1e-9) {
            $side = $pos > 0 ? Side::Sell : Side::Buy;

            return [new OrderIntent($side, OrderType::Market, TimeInForce::IOC, null, Decimal::of(min(abs($pos), $this->clipSize)))];
        }

        return [];
    }
}
