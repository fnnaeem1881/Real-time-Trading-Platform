<?php

declare(strict_types=1);

namespace TradingPlatform\Matching;

use TradingPlatform\Order\Fill;
use TradingPlatform\Order\Liquidity;
use TradingPlatform\Order\Order;
use TradingPlatform\Order\OrderStatus;
use TradingPlatform\Order\OrderType;
use TradingPlatform\Order\Side;
use TradingPlatform\Order\TimeInForce;
use TradingPlatform\Support\Clock;
use TradingPlatform\Support\Decimal;
use TradingPlatform\Support\Ids;

/**
 * Continuous limit-order matching engine for a single symbol.
 *
 * Price-time priority: the best price matches first, ties broken by arrival
 * order (seq). Execution happens at the resting (maker) price — the taker gets
 * price improvement, which is standard exchange behaviour.
 *
 * Time-in-force:
 *   GTC — rest any unfilled remainder on the book.
 *   IOC — fill what crosses now, cancel the rest (never rests).
 *   FOK — fill the *entire* quantity immediately or do nothing at all.
 *
 * Atomicity: for FOK we check fillability before mutating any state, so a
 * partial fill can never leak. For every trade, both maker and taker fills are
 * emitted together — there is no intermediate state where one side is recorded
 * without the other.
 *
 * @phpstan-type ExecResult array{order:Order,trades:list<Trade>,fills:list<Fill>,rested:bool}
 */
final class MatchingEngine
{
    /** @var array<string,Order> resting orders by id, for cancel/lookup */
    private array $restingById = [];

    public function __construct(
        public readonly OrderBook $book,
        private readonly Ids $ids,
        private readonly Clock $clock,
        private MatchingAlgorithm $algorithm = MatchingAlgorithm::Fifo,
        /** Self-trade prevention: never match an account against itself. */
        private readonly bool $selfTradePrevention = true,
    ) {}

    public function setAlgorithm(MatchingAlgorithm $algo): void
    {
        $this->algorithm = $algo;
    }

    /**
     * Submit an aggressing order. Returns the resulting trades and fills.
     *
     * @return ExecResult
     */
    public function submit(Order $order): array
    {
        // Fill-or-kill: verify the whole quantity can be satisfied *before*
        // mutating anything. If not, the order dies untouched.
        if ($order->tif === TimeInForce::FOK && !$this->canFullyFill($order)) {
            $order->cancel();

            return ['order' => $order, 'trades' => [], 'fills' => [], 'rested' => false];
        }

        $trades = [];
        $fills = [];

        while ($order->isActive() && $this->crossesBook($order)) {
            $level = $this->bestOppositeLevel($order);
            if ($level === null) {
                break;
            }

            $levelTrades = $this->algorithm === MatchingAlgorithm::ProRata
                ? $this->matchProRata($order, $level, $fills)
                : $this->matchFifo($order, $level, $fills);

            foreach ($levelTrades as $t) {
                $trades[] = $t;
            }

            // Safety valve: if the aggressor is still active and crosses but the
            // chosen level produced no progress, stop to avoid a spin loop.
            if ($order->isActive() && $levelTrades === [] && !$level->isEmpty()) {
                break;
            }
        }

        // Decide the fate of any remainder.
        $rested = false;
        if ($order->isActive()) {
            if ($order->type === OrderType::Limit && $order->tif === TimeInForce::GTC) {
                $this->book->addResting($order);
                $this->restingById[$order->id] = $order;
                $rested = true;
                if ($order->status === OrderStatus::New) {
                    // stays NEW until first fill; nothing else to do
                }
            } else {
                // IOC / market / FOK-that-somehow-remains: cancel the rest.
                $order->cancel();
            }
        }

        return ['order' => $order, 'trades' => $trades, 'fills' => $fills, 'rested' => $rested];
    }

    public function cancel(string $orderId): ?Order
    {
        $order = $this->restingById[$orderId] ?? null;
        if ($order === null) {
            return null;
        }
        $this->book->removeResting($order);
        $order->cancel();
        unset($this->restingById[$orderId]);

        return $order;
    }

    /**
     * FIFO allocation: walk resting orders in time priority, filling each fully
     * before moving to the next.
     *
     * @param list<Fill> $fills accumulator (by reference)
     * @return list<Trade>
     */
    private function matchFifo(Order $taker, PriceLevel $level, array &$fills): array
    {
        $trades = [];
        foreach ($level->orders() as $maker) {
            if (!$taker->isActive()) {
                break;
            }
            // Self-trade prevention: skip the aggressor's own resting orders.
            if ($this->selfTradePrevention && $maker->accountId === $taker->accountId) {
                continue;
            }
            $qty = $taker->remainingQty()->min($maker->remainingQty());
            if (!$qty->isPositive()) {
                continue;
            }
            $trades[] = $this->execute($taker, $maker, $level, $qty, $fills);

            if (!$maker->isActive()) {
                $this->book->removeResting($maker);
                unset($this->restingById[$maker->id]);
            }
        }

        return $trades;
    }

    /**
     * Pro-rata allocation within a level: distribute the taker's incoming
     * quantity across resting orders in proportion to their resting size.
     * Any rounding remainder is swept up in time priority so nothing is lost.
     *
     * @param list<Fill> $fills
     * @return list<Trade>
     */
    private function matchProRata(Order $taker, PriceLevel $level, array &$fills): array
    {
        $trades = [];
        $incoming = $taker->remainingQty();
        $levelTotal = $level->totalQty();

        // If the taker sweeps the whole level, pro-rata degenerates to "fill all".
        $toAllocate = $incoming->min($levelTotal);
        if (!$toAllocate->isPositive()) {
            return $trades;
        }

        /** @var array<int,array{order:Order,qty:Decimal}> $allocations */
        $allocations = [];
        $allocated = Decimal::zero();
        foreach ($level->orders() as $maker) {
            if ($this->selfTradePrevention && $maker->accountId === $taker->accountId) {
                continue;
            }
            $share = $maker->remainingQty()->div($levelTotal)->mul($toAllocate);
            // Floor to book scale by truncation via bcmath (Decimal keeps SCALE dp).
            $share = $share->min($maker->remainingQty());
            $allocations[$maker->seq] = ['order' => $maker, 'qty' => $share];
            $allocated = $allocated->add($share);
        }

        // Distribute rounding leftover by time priority.
        $leftover = $toAllocate->sub($allocated);
        if ($leftover->isPositive()) {
            foreach ($level->orders() as $maker) {
                if (!$leftover->isPositive()) {
                    break;
                }
                $room = $maker->remainingQty()->sub($allocations[$maker->seq]['qty']);
                $bump = $room->min($leftover);
                if ($bump->isPositive()) {
                    $allocations[$maker->seq]['qty'] = $allocations[$maker->seq]['qty']->add($bump);
                    $leftover = $leftover->sub($bump);
                }
            }
        }

        foreach ($allocations as $alloc) {
            $maker = $alloc['order'];
            $qty = $alloc['qty'];
            if (!$qty->isPositive() || !$taker->isActive()) {
                continue;
            }
            $trades[] = $this->execute($taker, $maker, $level, $qty, $fills);
            if (!$maker->isActive()) {
                $this->book->removeResting($maker);
                unset($this->restingById[$maker->id]);
            }
        }

        return $trades;
    }

    /**
     * Emit a trade + both fills atomically and advance both orders.
     *
     * @param list<Fill> $fills
     */
    private function execute(Order $taker, Order $maker, PriceLevel $level, Decimal $qty, array &$fills): Trade
    {
        $execPrice = $maker->price; // execution at the resting price
        $ts = $this->clock->nowNanos();
        $tradeId = $this->ids->nextTradeId();

        $takerFill = new Fill($tradeId, $taker->id, $taker->accountId, $taker->symbol, $taker->side, $execPrice, $qty, Liquidity::Taker, $ts, $taker->strategy);
        $makerFill = new Fill($tradeId, $maker->id, $maker->accountId, $maker->symbol, $maker->side, $execPrice, $qty, Liquidity::Maker, $ts, $maker->strategy);

        $qtyBefore = $maker->remainingQty();
        $taker->applyFill($takerFill);
        $maker->applyFill($makerFill);
        // Keep the level's cached total in sync with the maker we just reduced.
        $level->reduceBy($qtyBefore->sub($maker->remainingQty()));

        $fills[] = $takerFill;
        $fills[] = $makerFill;

        return new Trade($tradeId, $taker->symbol, $execPrice, $qty, $taker->id, $maker->id, $taker->side, $ts);
    }

    private function crossesBook(Order $order): bool
    {
        if ($order->side === Side::Buy) {
            $ask = $this->book->bestAsk();
            if ($ask === null) {
                return false;
            }

            return $order->type === OrderType::Market || ($order->price !== null && $order->price->gte($ask));
        }

        $bid = $this->book->bestBid();
        if ($bid === null) {
            return false;
        }

        return $order->type === OrderType::Market || ($order->price !== null && $order->price->lte($bid));
    }

    private function bestOppositeLevel(Order $order): ?PriceLevel
    {
        if ($order->side === Side::Buy) {
            $prices = $this->book->askPrices();

            return isset($prices[0]) ? $this->book->askLevel($prices[0]) : null;
        }
        $prices = $this->book->bidPrices();

        return isset($prices[0]) ? $this->book->bidLevel($prices[0]) : null;
    }

    /** Can this order be fully filled against current book liquidity right now? */
    private function canFullyFill(Order $order): bool
    {
        $need = $order->qty;
        $prices = $order->side === Side::Buy ? $this->book->askPrices() : $this->book->bidPrices();

        foreach ($prices as $p) {
            $level = $order->side === Side::Buy ? $this->book->askLevel($p) : $this->book->bidLevel($p);
            if ($level === null) {
                continue;
            }
            // Stop once prices no longer cross the limit.
            if ($order->type === OrderType::Limit && $order->price !== null) {
                if ($order->side === Side::Buy && $level->price->gt($order->price)) {
                    break;
                }
                if ($order->side === Side::Sell && $level->price->lt($order->price)) {
                    break;
                }
            }
            $need = $need->sub($level->totalQty());
            if ($need->lte(Decimal::zero())) {
                return true;
            }
        }

        return false;
    }

    /** @return array<string,Order> */
    public function restingOrders(): array
    {
        return $this->restingById;
    }
}
