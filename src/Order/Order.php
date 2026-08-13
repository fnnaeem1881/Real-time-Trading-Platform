<?php

declare(strict_types=1);

namespace TradingPlatform\Order;

use TradingPlatform\Support\Decimal;

/**
 * A live order and its lifecycle state.
 *
 * The lifecycle is: NEW -> (PARTIALLY_FILLED)* -> FILLED | CANCELLED | REJECTED.
 * Quantity accounting is exact (Decimal): filledQty + remainingQty == qty at all
 * times, which the matching engine and risk checks both depend on.
 */
final class Order
{
    public OrderStatus $status = OrderStatus::New;
    public Decimal $filledQty;

    /** Volume-weighted average fill price, or null until first fill. */
    public ?Decimal $avgFillPrice = null;

    /** @var Fill[] */
    public array $fills = [];

    public function __construct(
        public readonly string $id,
        public readonly string $accountId,
        public readonly string $symbol,
        public readonly Side $side,
        public readonly OrderType $type,
        public readonly TimeInForce $tif,
        /** Limit price; null for market orders. */
        public readonly ?Decimal $price,
        public readonly Decimal $qty,
        public readonly int $createdAtNanos,
        /** Monotonic sequence for price-time priority tie-break. */
        public readonly int $seq,
        /** Optional originating strategy, for attribution. */
        public readonly ?string $strategy = null,
    ) {
        $this->filledQty = Decimal::zero();
    }

    public function remainingQty(): Decimal
    {
        return $this->qty->sub($this->filledQty);
    }

    public function isActive(): bool
    {
        return !$this->status->isTerminal() && $this->remainingQty()->isPositive();
    }

    /** Record a fill against this order and roll up status + avg price. */
    public function applyFill(Fill $fill): void
    {
        $this->fills[] = $fill;

        // Update VWAP of fills: (oldNotional + fillNotional) / newFilledQty.
        $oldNotional = $this->avgFillPrice?->mul($this->filledQty) ?? Decimal::zero();
        $fillNotional = $fill->price->mul($fill->qty);
        $this->filledQty = $this->filledQty->add($fill->qty);
        $this->avgFillPrice = $this->filledQty->isZero()
            ? null
            : $oldNotional->add($fillNotional)->div($this->filledQty);

        if ($this->remainingQty()->isZero()) {
            $this->status = OrderStatus::Filled;
        } else {
            $this->status = OrderStatus::PartiallyFilled;
        }
    }

    public function cancel(): void
    {
        if (!$this->status->isTerminal()) {
            $this->status = OrderStatus::Cancelled;
        }
    }

    public function reject(): void
    {
        $this->status = OrderStatus::Rejected;
    }

    /** @return array<string,mixed> */
    public function toArray(): array
    {
        return [
            'id' => $this->id,
            'account' => $this->accountId,
            'symbol' => $this->symbol,
            'side' => $this->side->value,
            'type' => $this->type->value,
            'tif' => $this->tif->value,
            'price' => $this->price?->toFloat(),
            'qty' => $this->qty->toFloat(),
            'filled' => $this->filledQty->toFloat(),
            'remaining' => $this->remainingQty()->toFloat(),
            'avgPrice' => $this->avgFillPrice?->toFloat(),
            'status' => $this->status->value,
            'strategy' => $this->strategy,
        ];
    }
}
