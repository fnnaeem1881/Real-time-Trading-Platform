<?php

declare(strict_types=1);

namespace TradingPlatform\Perf;

/**
 * A fixed-capacity object pool for hot-path objects (orders, fills, events).
 *
 * On the critical path, allocating and garbage-collecting millions of small
 * objects is a latency killer — GC pauses show up directly in tail latency.
 * Pooling reuses instances instead, trading a little memory for far fewer
 * allocations and predictable timing.
 *
 * @template T of object
 */
final class ObjectPool
{
    /** @var array<int,object> */
    private array $free = [];
    private int $created = 0;
    private int $reused = 0;

    /**
     * @param callable():T $factory   builds a fresh object when the pool is empty
     * @param callable(T):void $reset returns an object to a clean state on release
     */
    public function __construct(
        private $factory,
        private $reset,
        private readonly int $capacity = 4096,
    ) {}

    /** @return T */
    public function acquire(): object
    {
        $obj = array_pop($this->free);
        if ($obj === null) {
            $this->created++;

            return ($this->factory)();
        }
        $this->reused++;

        return $obj;
    }

    /** @param T $obj */
    public function release(object $obj): void
    {
        if (count($this->free) >= $this->capacity) {
            return; // let it be GC'd rather than grow unbounded
        }
        ($this->reset)($obj);
        $this->free[] = $obj;
    }

    /** @return array{created:int,reused:int,free:int,reuseRate:float} */
    public function stats(): array
    {
        $total = $this->created + $this->reused;

        return [
            'created' => $this->created,
            'reused' => $this->reused,
            'free' => count($this->free),
            'reuseRate' => $total > 0 ? $this->reused / $total : 0.0,
        ];
    }
}
