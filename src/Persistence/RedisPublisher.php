<?php

declare(strict_types=1);

namespace TradingPlatform\Persistence;

/**
 * Thin wrapper over ext-redis for the real-time caching / pub-sub side of the
 * platform: publish trades to a Redis Stream (XADD) and cache the consolidated
 * BBO. Best-effort — callers guard on availability so the core never depends
 * on Redis being present.
 */
final class RedisPublisher
{
    private \Redis $redis;

    public function __construct(string $host = '127.0.0.1', int $port = 6379)
    {
        $this->redis = new \Redis();
        $this->redis->connect($host, $port, 2.0);
    }

    /** @param array<string,mixed> $trade */
    public function publishTrade(string $symbol, array $trade): void
    {
        $this->redis->xAdd('md.'.$symbol.'.trades', '*', array_map('strval', $trade));
    }

    public function cacheBbo(string $symbol, float $bid, float $ask): void
    {
        $this->redis->hMSet('bbo:'.$symbol, ['bid' => $bid, 'ask' => $ask, 'ts' => (string) (int) (microtime(true) * 1000)]);
    }

    public function streamLength(string $symbol): int
    {
        return (int) $this->redis->xLen('md.'.$symbol.'.trades');
    }

    public function ping(): bool
    {
        return (bool) $this->redis->ping();
    }
}
