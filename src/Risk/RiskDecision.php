<?php

declare(strict_types=1);

namespace TradingPlatform\Risk;

/** Outcome of the pre-trade risk gate. */
final class RiskDecision
{
    /**
     * @param list<string> $breaches
     */
    private function __construct(
        public readonly bool $approved,
        public readonly array $breaches,
    ) {}

    public static function approve(): self
    {
        return new self(true, []);
    }

    /** @param list<string> $breaches */
    public static function reject(array $breaches): self
    {
        return new self(false, $breaches);
    }

    public function reason(): string
    {
        return $this->approved ? 'approved' : implode('; ', $this->breaches);
    }
}
