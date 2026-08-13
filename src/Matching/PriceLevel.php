<?php

declare(strict_types=1);

namespace TradingPlatform\Matching;

use TradingPlatform\Order\Order;
use TradingPlatform\Support\Decimal;

/**
 * All resting orders at a single price, held in strict time priority (FIFO).
 * Total resting quantity is tracked incrementally so best-of-book and depth
 * reads are O(1).
 */
final class PriceLevel
{
    /** @var array<int,Order> insertion-ordered by seq (== arrival order) */
    private array $orders = [];

    private Decimal $totalQty;

    public function __construct(public readonly Decimal $price)
    {
        $this->totalQty = Decimal::zero();
    }

    public function add(Order $order): void
    {
        $this->orders[$order->seq] = $order;
        $this->totalQty = $this->totalQty->add($order->remainingQty());
    }

    public function remove(Order $order): void
    {
        if (isset($this->orders[$order->seq])) {
            $this->totalQty = $this->totalQty->sub($order->remainingQty());
            unset($this->orders[$order->seq]);
        }
    }

    /** Reduce tracked total after a resting order was partially consumed. */
    public function reduceBy(Decimal $qty): void
    {
        $this->totalQty = $this->totalQty->sub($qty);
    }

    public function totalQty(): Decimal
    {
        return $this->totalQty;
    }

    public function isEmpty(): bool
    {
        return $this->orders === [];
    }

    public function count(): int
    {
        return count($this->orders);
    }

    /** Oldest resting order (front of the FIFO queue), or null. */
    public function front(): ?Order
    {
        foreach ($this->orders as $order) {
            return $order;
        }

        return null;
    }

    /** @return array<int,Order> in time priority */
    public function orders(): array
    {
        return $this->orders;
    }
}
