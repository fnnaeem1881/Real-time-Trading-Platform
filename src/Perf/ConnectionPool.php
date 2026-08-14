<?php

declare(strict_types=1);

namespace TradingPlatform\Perf;

/**
 * A bounded connection pool (M4 — network optimization / connection pooling).
 *
 * Opening a fresh TCP+auth connection per request is slow; a pool keeps a set of
 * warm connections and lends them out, which is essential under Swoole's
 * long-lived workers. Backed by a Swoole\Channel when running in a coroutine
 * worker (true concurrent borrowing), and by a simple idle stack otherwise so it
 * also works under the plain CLI/`php -S` path.
 *
 * @template T
 */
final class ConnectionPool
{
    /** @var \Swoole\Coroutine\Channel|null */
    private $channel = null;
    /** @var array<int,mixed> idle connections (non-Swoole fallback) */
    private array $idle = [];
    private int $created = 0;
    private int $borrowed = 0;

    /**
     * @param callable():T $factory builds a new connection
     */
    public function __construct(private $factory, private readonly int $size = 8)
    {
        if (class_exists('\Swoole\Coroutine\Channel') && \Swoole\Coroutine::getCid() > 0) {
            $this->channel = new \Swoole\Coroutine\Channel($size);
        }
    }

    /** @return T */
    public function get(): mixed
    {
        $this->borrowed++;
        if ($this->channel !== null) {
            if (!$this->channel->isEmpty() || $this->created >= $this->size) {
                return $this->channel->pop(2.0);
            }
            $this->created++;

            return ($this->factory)();
        }
        // Fallback: reuse an idle connection or create one.
        $conn = array_pop($this->idle);
        if ($conn !== null) {
            return $conn;
        }
        $this->created++;

        return ($this->factory)();
    }

    /** @param T $conn */
    public function put(mixed $conn): void
    {
        if ($this->channel !== null) {
            $this->channel->push($conn);

            return;
        }
        if (count($this->idle) < $this->size) {
            $this->idle[] = $conn;
        }
    }

    /**
     * Borrow a connection, run the callback, and always return it to the pool.
     *
     * @template R
     * @param callable(T):R $fn
     * @return R
     */
    public function use(callable $fn): mixed
    {
        $conn = $this->get();
        try {
            return $fn($conn);
        } finally {
            $this->put($conn);
        }
    }

    /** @return array{size:int,created:int,borrowed:int,idle:int,driver:string} */
    public function stats(): array
    {
        return [
            'size' => $this->size,
            'created' => $this->created,
            'borrowed' => $this->borrowed,
            'idle' => $this->channel !== null ? $this->channel->length() : count($this->idle),
            'driver' => $this->channel !== null ? 'swoole-channel' : 'stack',
        ];
    }
}
