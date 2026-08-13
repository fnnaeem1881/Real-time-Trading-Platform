<?php

declare(strict_types=1);

namespace TradingPlatform\Risk;

use TradingPlatform\Order\Fill;
use TradingPlatform\Support\Decimal;

/**
 * Account-level book of positions plus cash. Central source of truth for
 * exposure, P&L and equity used by the risk engine and analytics.
 */
final class Portfolio
{
    /** @var array<string,Position> symbol => position */
    private array $positions = [];

    public function __construct(
        public readonly string $accountId,
        public Decimal $cash,
        public readonly Decimal $startingEquity,
    ) {}

    public static function open(string $accountId, Decimal $startingCash): self
    {
        return new self($accountId, $startingCash, $startingCash);
    }

    public function position(string $symbol): Position
    {
        return $this->positions[$symbol] ??= new Position($symbol);
    }

    /** Apply a fill: adjust cash by signed notional, then update the position. */
    public function applyFill(Fill $fill): void
    {
        $notional = $fill->notional();
        // Buys spend cash, sells receive cash.
        $this->cash = $fill->side->value === 'BUY'
            ? $this->cash->sub($notional)
            : $this->cash->add($notional);

        $this->position($fill->symbol)->applyFill($fill);
    }

    /** @param array<string,Decimal> $marks symbol => mark price */
    public function equity(array $marks): Decimal
    {
        $equity = $this->cash;
        foreach ($this->positions as $symbol => $pos) {
            $mark = $marks[$symbol] ?? $pos->avgPrice;
            // Position market value = qty * mark (signed); shorts subtract.
            $equity = $equity->add($pos->qty->mul($mark));
        }

        return $equity;
    }

    /** @param array<string,Decimal> $marks */
    public function grossExposure(array $marks): Decimal
    {
        $gross = Decimal::zero();
        foreach ($this->positions as $symbol => $pos) {
            $mark = $marks[$symbol] ?? $pos->avgPrice;
            $gross = $gross->add($pos->exposure($mark));
        }

        return $gross;
    }

    /** @param array<string,Decimal> $marks */
    public function realizedPnl(): Decimal
    {
        $r = Decimal::zero();
        foreach ($this->positions as $pos) {
            $r = $r->add($pos->realizedPnl);
        }

        return $r;
    }

    /** @param array<string,Decimal> $marks */
    public function unrealizedPnl(array $marks): Decimal
    {
        $u = Decimal::zero();
        foreach ($this->positions as $symbol => $pos) {
            $mark = $marks[$symbol] ?? $pos->avgPrice;
            $u = $u->add($pos->unrealizedPnl($mark));
        }

        return $u;
    }

    /** @return array<string,Position> */
    public function positions(): array
    {
        return $this->positions;
    }
}
