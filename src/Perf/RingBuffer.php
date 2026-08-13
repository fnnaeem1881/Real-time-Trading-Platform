<?php

declare(strict_types=1);

namespace TradingPlatform\Perf;

/**
 * Fixed-size single-producer/single-consumer ring buffer.
 *
 * On the hot path we want a bounded queue with no per-item allocation and no
 * locking. With one producer and one consumer, a ring buffer with separate head
 * and tail indices is inherently race-free: the producer only writes tail, the
 * consumer only writes head. In a Swoole deployment the backing store becomes a
 * Swoole\Table in shared memory; the semantics are identical.
 *
 * @template T
 */
final class RingBuffer
{
    /** @var array<int,mixed> */
    private array $slots;
    private int $head = 0; // next read
    private int $tail = 0; // next write
    private int $dropped = 0;

    public function __construct(private readonly int $capacity = 1024)
    {
        if (($capacity & ($capacity - 1)) !== 0) {
            throw new \InvalidArgumentException('capacity must be a power of two');
        }
        $this->slots = array_fill(0, $capacity, null);
    }

    /** @param T $item @return bool false if full (item dropped) */
    public function push(mixed $item): bool
    {
        if ($this->tail - $this->head >= $this->capacity) {
            $this->dropped++;

            return false;
        }
        $this->slots[$this->tail & ($this->capacity - 1)] = $item;
        $this->tail++;

        return true;
    }

    /** @return T|null */
    public function pop(): mixed
    {
        if ($this->head === $this->tail) {
            return null;
        }
        $slot = $this->head & ($this->capacity - 1);
        $item = $this->slots[$slot];
        $this->slots[$slot] = null;
        $this->head++;

        return $item;
    }

    public function size(): int
    {
        return $this->tail - $this->head;
    }

    public function isEmpty(): bool
    {
        return $this->head === $this->tail;
    }

    public function dropped(): int
    {
        return $this->dropped;
    }
}
