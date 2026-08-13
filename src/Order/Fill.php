<?php

declare(strict_types=1);

namespace TradingPlatform\Order;

use TradingPlatform\Support\Decimal;

/** An immutable execution record: one leg of a trade against one order. */
final class Fill
{
    public function __construct(
        public readonly string $tradeId,
        public readonly string $orderId,
        public readonly string $accountId,
        public readonly string $symbol,
        public readonly Side $side,
        public readonly Decimal $price,
        public readonly Decimal $qty,
        public readonly Liquidity $liquidity,
        public readonly int $tsNanos,
        public readonly ?string $strategy = null,
    ) {}

    public function notional(): Decimal
    {
        return $this->price->mul($this->qty);
    }

    /** @return array<string,mixed> */
    public function toArray(): array
    {
        return [
            'tradeId' => $this->tradeId,
            'orderId' => $this->orderId,
            'account' => $this->accountId,
            'symbol' => $this->symbol,
            'side' => $this->side->value,
            'price' => $this->price->toFloat(),
            'qty' => $this->qty->toFloat(),
            'liquidity' => $this->liquidity->value,
            'ts' => $this->tsNanos,
        ];
    }
}
