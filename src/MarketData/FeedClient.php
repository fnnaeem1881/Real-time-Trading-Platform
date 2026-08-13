<?php

declare(strict_types=1);

namespace TradingPlatform\MarketData;

/**
 * A market-data source. The production implementation is a Swoole WebSocket
 * client (Binance/Coinbase) with reconnect/backoff; tests and this Windows demo
 * use a deterministic simulated feed. Everything downstream depends only on this
 * interface, so live vs simulated is a one-line swap.
 */
interface FeedClient
{
    /** Human-readable venue id, e.g. BINANCE. */
    public function venue(): string;

    /**
     * Pull the next batch of normalized ticks. In the Swoole client this is fed
     * by the socket's onMessage; in the simulator it is generated on demand.
     *
     * @return list<MarketTick>
     */
    public function poll(): array;
}
