<?php

declare(strict_types=1);

namespace TradingPlatform\Risk;

use TradingPlatform\Order\Fill;
use TradingPlatform\Order\Side;
use TradingPlatform\Support\Decimal;

/**
 * A running position in one symbol for one account.
 *
 * Uses average-cost accounting: buys extend the position at a blended average
 * price; sells realize P&L against that average. Reducing or flipping the
 * position realizes the appropriate portion. This is what feeds both real-time
 * P&L (M4) and exposure/VaR (M3).
 */
final class Position
{
    /** Signed quantity: positive = long, negative = short. */
    public Decimal $qty;
    public Decimal $avgPrice;
    public Decimal $realizedPnl;

    public function __construct(public readonly string $symbol)
    {
        $this->qty = Decimal::zero();
        $this->avgPrice = Decimal::zero();
        $this->realizedPnl = Decimal::zero();
    }

    public function applyFill(Fill $fill): void
    {
        $signed = $fill->side === Side::Buy ? $fill->qty : $fill->qty->negate();
        $newQty = $this->qty->add($signed);

        $sameDirection = $this->qty->isZero()
            || ($this->qty->isPositive() && $signed->isPositive())
            || ($this->qty->isNegative() && $signed->isNegative());

        if ($sameDirection) {
            // Extending: blend the average price by notional.
            $oldNotional = $this->avgPrice->mul($this->qty->abs());
            $addNotional = $fill->price->mul($fill->qty);
            $totalQty = $this->qty->abs()->add($fill->qty);
            $this->avgPrice = $totalQty->isZero() ? Decimal::zero() : $oldNotional->add($addNotional)->div($totalQty);
        } else {
            // Reducing/closing/flipping: realize P&L on the closed portion.
            $closingQty = $fill->qty->min($this->qty->abs());
            $direction = $this->qty->isPositive() ? Decimal::of(1) : Decimal::of(-1);
            $pnlPerUnit = $fill->price->sub($this->avgPrice)->mul($direction);
            $this->realizedPnl = $this->realizedPnl->add($pnlPerUnit->mul($closingQty));

            if ($newQty->isZero()) {
                $this->avgPrice = Decimal::zero();
            } elseif ($this->qty->isPositive() !== $newQty->isPositive()) {
                // Flipped through zero: remainder opens at the fill price.
                $this->avgPrice = $fill->price;
            }
            // Pure reduction keeps the existing average price.
        }

        $this->qty = $newQty;
    }

    /** Unrealized P&L at a mark price. */
    public function unrealizedPnl(Decimal $mark): Decimal
    {
        if ($this->qty->isZero()) {
            return Decimal::zero();
        }

        return $mark->sub($this->avgPrice)->mul($this->qty);
    }

    /** Absolute notional exposure at a mark price. */
    public function exposure(Decimal $mark): Decimal
    {
        return $this->qty->abs()->mul($mark);
    }

    public function isFlat(): bool
    {
        return $this->qty->isZero();
    }

    /** @return array<string,mixed> */
    public function toArray(Decimal $mark): array
    {
        return [
            'symbol' => $this->symbol,
            'qty' => $this->qty->toFloat(),
            'avgPrice' => $this->avgPrice->toFloat(),
            'mark' => $mark->toFloat(),
            'realizedPnl' => $this->realizedPnl->toFloat(),
            'unrealizedPnl' => $this->unrealizedPnl($mark)->toFloat(),
            'exposure' => $this->exposure($mark)->toFloat(),
        ];
    }
}
