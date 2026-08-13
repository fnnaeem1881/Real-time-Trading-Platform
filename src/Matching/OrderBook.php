<?php

declare(strict_types=1);

namespace TradingPlatform\Matching;

use TradingPlatform\Order\Order;
use TradingPlatform\Order\Side;
use TradingPlatform\Support\Decimal;

/**
 * A single-symbol limit order book with price-time priority.
 *
 * Bids and asks are kept as price -> PriceLevel maps. We keep the sorted list of
 * price keys maintained lazily (re-sorted only when levels are added/removed), so
 * best bid/ask is O(1) to read and crossing walks levels in price priority.
 *
 * One book is owned by exactly one matching process (the actor model), so no
 * locking is required for correctness.
 */
final class OrderBook
{
    /** @var array<string,PriceLevel> price(string) => level, bids */
    private array $bids = [];
    /** @var array<string,PriceLevel> price(string) => level, asks */
    private array $asks = [];

    /** @var list<string> bid prices sorted DESC (best first) */
    private array $bidPrices = [];
    /** @var list<string> ask prices sorted ASC (best first) */
    private array $askPrices = [];

    private bool $bidsDirty = false;
    private bool $asksDirty = false;

    public function __construct(public readonly string $symbol) {}

    public function addResting(Order $order): void
    {
        $price = (string) $order->price;
        if ($order->side === Side::Buy) {
            if (!isset($this->bids[$price])) {
                $this->bids[$price] = new PriceLevel($order->price);
                $this->bidsDirty = true;
            }
            $this->bids[$price]->add($order);
        } else {
            if (!isset($this->asks[$price])) {
                $this->asks[$price] = new PriceLevel($order->price);
                $this->asksDirty = true;
            }
            $this->asks[$price]->add($order);
        }
    }

    public function removeResting(Order $order): void
    {
        $price = (string) $order->price;
        if ($order->side === Side::Buy && isset($this->bids[$price])) {
            $this->bids[$price]->remove($order);
            if ($this->bids[$price]->isEmpty()) {
                unset($this->bids[$price]);
                $this->bidsDirty = true;
            }
        } elseif ($order->side === Side::Sell && isset($this->asks[$price])) {
            $this->asks[$price]->remove($order);
            if ($this->asks[$price]->isEmpty()) {
                unset($this->asks[$price]);
                $this->asksDirty = true;
            }
        }
    }

    public function bestBid(): ?Decimal
    {
        $this->ensureSorted();

        return isset($this->bidPrices[0]) ? $this->bids[$this->bidPrices[0]]->price : null;
    }

    public function bestAsk(): ?Decimal
    {
        $this->ensureSorted();

        return isset($this->askPrices[0]) ? $this->asks[$this->askPrices[0]]->price : null;
    }

    public function spread(): ?Decimal
    {
        $b = $this->bestBid();
        $a = $this->bestAsk();

        return ($b && $a) ? $a->sub($b) : null;
    }

    public function midPrice(): ?Decimal
    {
        $b = $this->bestBid();
        $a = $this->bestAsk();

        return ($b && $a) ? $b->add($a)->div(Decimal::of(2)) : null;
    }

    /** @return list<string> bid prices best-first */
    public function bidPrices(): array
    {
        $this->ensureSorted();

        return $this->bidPrices;
    }

    /** @return list<string> ask prices best-first */
    public function askPrices(): array
    {
        $this->ensureSorted();

        return $this->askPrices;
    }

    public function bidLevel(string $price): ?PriceLevel
    {
        return $this->bids[$price] ?? null;
    }

    public function askLevel(string $price): ?PriceLevel
    {
        return $this->asks[$price] ?? null;
    }

    /**
     * Aggregated depth for display.
     *
     * @return array{bids:list<array{price:float,qty:float,orders:int}>,asks:list<array{price:float,qty:float,orders:int}>}
     */
    public function depth(int $levels = 10): array
    {
        $this->ensureSorted();
        $bids = [];
        foreach (array_slice($this->bidPrices, 0, $levels) as $p) {
            $lvl = $this->bids[$p];
            $bids[] = ['price' => $lvl->price->toFloat(), 'qty' => $lvl->totalQty()->toFloat(), 'orders' => $lvl->count()];
        }
        $asks = [];
        foreach (array_slice($this->askPrices, 0, $levels) as $p) {
            $lvl = $this->asks[$p];
            $asks[] = ['price' => $lvl->price->toFloat(), 'qty' => $lvl->totalQty()->toFloat(), 'orders' => $lvl->count()];
        }

        return ['bids' => $bids, 'asks' => $asks];
    }

    private function ensureSorted(): void
    {
        if ($this->bidsDirty) {
            $this->bidPrices = array_keys($this->bids);
            usort($this->bidPrices, static fn (string $a, string $b): int => bccomp($b, $a, Decimal::SCALE));
            $this->bidsDirty = false;
        }
        if ($this->asksDirty) {
            $this->askPrices = array_keys($this->asks);
            usort($this->askPrices, static fn (string $a, string $b): int => bccomp($a, $b, Decimal::SCALE));
            $this->asksDirty = false;
        }
    }

    public function markDirty(): void
    {
        $this->bidsDirty = true;
        $this->asksDirty = true;
    }
}
