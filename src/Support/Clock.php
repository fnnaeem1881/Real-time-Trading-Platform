<?php

declare(strict_types=1);

namespace TradingPlatform\Support;

/**
 * Monotonic-ish clock abstraction. In simulation/backtest we drive a virtual
 * clock so results are deterministic; in production this wraps the wall clock.
 */
interface Clock
{
    /** Nanoseconds since epoch (virtual or real). */
    public function nowNanos(): int;

    /** Milliseconds since epoch. */
    public function nowMillis(): int;
}

/**
 * Deterministic virtual clock: advances only when the simulation steps it.
 * Two runs from the same seed produce identical timestamps.
 */
final class VirtualClock implements Clock
{
    public function __construct(private int $nanos = 1_700_000_000_000_000_000) {}

    public function advanceNanos(int $delta): void
    {
        $this->nanos += $delta;
    }

    public function advanceMillis(int $delta): void
    {
        $this->nanos += $delta * 1_000_000;
    }

    public function nowNanos(): int
    {
        return $this->nanos;
    }

    public function nowMillis(): int
    {
        return intdiv($this->nanos, 1_000_000);
    }

    public function toState(): int
    {
        return $this->nanos;
    }

    public static function fromState(int $nanos): self
    {
        return new self($nanos);
    }
}

/** Real wall-clock, hrtime-backed where available. */
final class SystemClock implements Clock
{
    public function nowNanos(): int
    {
        return (int) (microtime(true) * 1_000_000_000);
    }

    public function nowMillis(): int
    {
        return (int) (microtime(true) * 1_000);
    }
}
