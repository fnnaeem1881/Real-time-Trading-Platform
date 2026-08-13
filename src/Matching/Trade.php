<?php

declare(strict_types=1);

namespace TradingPlatform\Matching;

use TradingPlatform\Order\Side;
use TradingPlatform\Support\Decimal;

/** A matched execution between an aggressing (taker) and a resting (maker) order. */
final class Trade
{
    public function __construct(
        public readonly string $id,
        public readonly string $symbol,
        public readonly Decimal $price,
        public readonly Decimal $qty,
        public readonly string $takerOrderId,
        public readonly string $makerOrderId,
        /** Side of the aggressor (drives trade-tape colour and signed volume). */
        public readonly Side $takerSide,
        public readonly int $tsNanos,
    ) {}

    public function notional(): Decimal
    {
        return $this->price->mul($this->qty);
    }

    /** @return array<string,mixed> */
    public function toArray(): array
    {
        return [
            'id' => $this->id,
            'symbol' => $this->symbol,
            'price' => $this->price->toFloat(),
            'qty' => $this->qty->toFloat(),
            'taker' => $this->takerOrderId,
            'maker' => $this->makerOrderId,
            'side' => $this->takerSide->value,
            'ts' => $this->tsNanos,
        ];
    }
}
