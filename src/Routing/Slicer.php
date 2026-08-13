<?php

declare(strict_types=1);

namespace TradingPlatform\Routing;

use TradingPlatform\Support\Decimal;

/**
 * Splits a large parent order into smaller child slices to reduce market impact.
 *
 *  - TWAP: equal slices over N intervals.
 *  - Iceberg: a fixed visible clip repeated until the parent is exhausted.
 *
 * The engine executes each child as an ordinary order; the slicer only decides
 * sizes, keeping impact policy separate from matching.
 */
final class Slicer
{
    /**
     * @return list<Decimal> child slice quantities that sum to $total
     */
    public function twap(Decimal $total, int $slices): array
    {
        if ($slices < 1) {
            throw new \InvalidArgumentException('slices must be >= 1');
        }
        $each = $total->div(Decimal::of($slices));
        $out = [];
        $acc = Decimal::zero();
        for ($i = 0; $i < $slices - 1; $i++) {
            $out[] = $each;
            $acc = $acc->add($each);
        }
        // Last slice absorbs any rounding remainder so the sum is exact.
        $out[] = $total->sub($acc);

        return $out;
    }

    /**
     * @return list<Decimal> iceberg clips of size $clip (last one may be smaller)
     */
    public function iceberg(Decimal $total, Decimal $clip): array
    {
        if (!$clip->isPositive()) {
            throw new \InvalidArgumentException('clip must be positive');
        }
        $out = [];
        $remaining = $total;
        while ($remaining->isPositive()) {
            $slice = $remaining->min($clip);
            $out[] = $slice;
            $remaining = $remaining->sub($slice);
        }

        return $out;
    }
}
