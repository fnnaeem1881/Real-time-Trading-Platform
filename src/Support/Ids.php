<?php

declare(strict_types=1);

namespace TradingPlatform\Support;

/**
 * Monotonic ID generator. IDs are strictly increasing within a process, which
 * we rely on for price-time priority tie-breaking (lower id == arrived earlier).
 */
final class Ids
{
    public function __construct(private int $counter = 0) {}

    public function next(): int
    {
        return ++$this->counter;
    }

    public function nextOrderId(): string
    {
        return 'ORD-'.str_pad((string) $this->next(), 9, '0', STR_PAD_LEFT);
    }

    public function nextTradeId(): string
    {
        return 'TRD-'.str_pad((string) $this->next(), 9, '0', STR_PAD_LEFT);
    }

    public function current(): int
    {
        return $this->counter;
    }

    public function toState(): int
    {
        return $this->counter;
    }

    public static function fromState(int $counter): self
    {
        return new self($counter);
    }
}

/**
 * Deterministic PRNG so simulations and backtests are fully reproducible from a
 * seed.
 *
 * Implemented as a 63-bit xorshift. We deliberately avoid multiply/add-based
 * generators (LCG, splitmix): in PHP an integer multiply or add that exceeds
 * PHP_INT_MAX silently promotes to float and *loses the low bits*, which
 * corrupts the stream. xorshift uses only XOR and shifts, and we mask to 63 bits
 * after each left shift so every value stays a non-negative int — no overflow,
 * no float promotion, fully portable.
 */
final class DeterministicRandom
{
    private const MASK63 = 0x7FFFFFFFFFFFFFFF; // 2^63 - 1

    private int $state;

    public function __construct(int $seed)
    {
        // Scramble the seed so small/zero seeds still fill the state well.
        $s = ($seed ^ 0x2545F4914F6CDD1D) & self::MASK63;
        $this->state = $s !== 0 ? $s : 0x1234_5678_9ABC_DEF0;
    }

    /** Next 63-bit non-negative integer via xorshift. */
    public function nextInt(): int
    {
        $x = $this->state;
        $x ^= ($x << 13) & self::MASK63;
        $x ^= $x >> 7;                       // x >= 0, so this is a logical shift
        $x ^= ($x << 17) & self::MASK63;
        $this->state = $x & self::MASK63;

        return $this->state;
    }

    /** Uniform float in [0, 1). */
    public function nextFloat(): float
    {
        // Use the top 53 bits (double mantissa width) for a clean uniform.
        return ($this->nextInt() >> 10) / (float) (1 << 53);
    }

    /** Uniform float in [min, max). */
    public function uniform(float $min, float $max): float
    {
        return $min + ($max - $min) * $this->nextFloat();
    }

    /** Standard-normal sample via Box-Muller. */
    public function gaussian(): float
    {
        $u1 = max(1e-12, $this->nextFloat());
        $u2 = $this->nextFloat();

        return sqrt(-2.0 * log($u1)) * cos(2 * M_PI * $u2);
    }

    public function toState(): int
    {
        return $this->state;
    }

    public static function fromState(int $state): self
    {
        $r = new self(0);
        $r->state = $state;

        return $r;
    }
}
