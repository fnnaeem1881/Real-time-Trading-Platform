<?php

declare(strict_types=1);

namespace TradingPlatform\Strategy;

use TradingPlatform\Order\OrderType;
use TradingPlatform\Order\Side;
use TradingPlatform\Order\TimeInForce;
use TradingPlatform\Support\Decimal;

/** A desired order emitted by a strategy, before risk checks and routing. */
final class OrderIntent
{
    public function __construct(
        public readonly Side $side,
        public readonly OrderType $type,
        public readonly TimeInForce $tif,
        public readonly ?Decimal $price,
        public readonly Decimal $qty,
    ) {}
}

/** Read-only view of the market a strategy reacts to. */
final class StrategyContext
{
    public function __construct(
        public readonly string $symbol,
        public readonly ?Decimal $bestBid,
        public readonly ?Decimal $bestAsk,
        public readonly ?Decimal $mid,
        /** @var array<string,?float> */
        public readonly array $indicators,
        /** Current signed position quantity for this strategy's account. */
        public readonly Decimal $position,
        public readonly int $step,
    ) {}
}

/**
 * A trading strategy: pure decision logic that maps market state to order
 * intents. The Strategy pattern keeps algo logic decoupled from execution, so
 * the same strategy runs identically in backtest and live.
 */
interface Strategy
{
    public function name(): string;

    /**
     * @return list<OrderIntent>
     */
    public function onTick(StrategyContext $ctx): array;
}
