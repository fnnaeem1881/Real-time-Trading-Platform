<?php

declare(strict_types=1);

namespace TradingPlatform\Matching;

/**
 * Allocation policy used *within* a single price level once price priority has
 * selected it.
 *
 *  - FIFO (price-time): oldest resting order fills first. The default and the
 *    fairest for most markets.
 *  - PRO_RATA: the aggressor's quantity is split across resting orders in
 *    proportion to their size — used by some derivatives venues to reward size.
 */
enum MatchingAlgorithm: string
{
    case Fifo = 'FIFO';
    case ProRata = 'PRO_RATA';
}
