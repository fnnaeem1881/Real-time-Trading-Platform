<?php

declare(strict_types=1);

namespace TradingPlatform\Analytics;

use TradingPlatform\Order\Fill;

/**
 * Performance attribution: which strategy / symbol actually made the money.
 * Aggregates realized P&L, traded notional and fill counts per bucket so the
 * dashboard can show a contribution breakdown.
 */
final class Attribution
{
    /** @var array<string,array{realized:float,notional:float,fills:int,volume:float}> */
    private array $byStrategy = [];
    /** @var array<string,array{realized:float,notional:float,fills:int,volume:float}> */
    private array $bySymbol = [];

    /**
     * Record a fill's contribution. Realized P&L is attributed when a position
     * is reduced, so the caller passes the realized delta for this fill.
     */
    public function record(Fill $fill, float $realizedDelta): void
    {
        $strategy = $fill->strategy ?? 'MANUAL';
        $this->bump($this->byStrategy, $strategy, $fill, $realizedDelta);
        $this->bump($this->bySymbol, $fill->symbol, $fill, $realizedDelta);
    }

    /**
     * @param array<string,array{realized:float,notional:float,fills:int,volume:float}> $bucket
     */
    private function bump(array &$bucket, string $key, Fill $fill, float $realizedDelta): void
    {
        $b = $bucket[$key] ?? ['realized' => 0.0, 'notional' => 0.0, 'fills' => 0, 'volume' => 0.0];
        $b['realized'] += $realizedDelta;
        $b['notional'] += $fill->notional()->toFloat();
        $b['volume'] += $fill->qty->toFloat();
        $b['fills']++;
        $bucket[$key] = $b;
    }

    /** @return array<string,array{realized:float,notional:float,fills:int,volume:float}> */
    public function byStrategy(): array
    {
        return $this->byStrategy;
    }

    /** @return array<string,array{realized:float,notional:float,fills:int,volume:float}> */
    public function bySymbol(): array
    {
        return $this->bySymbol;
    }
}
