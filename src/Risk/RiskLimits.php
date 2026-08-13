<?php

declare(strict_types=1);

namespace TradingPlatform\Risk;

/**
 * Per-account risk limits enforced by the pre-trade gate and monitored
 * continuously. All monetary values are in the account base currency.
 */
final class RiskLimits
{
    public function __construct(
        /** Max absolute notional per single order. */
        public float $maxOrderNotional = 250_000.0,
        /** Max absolute notional exposure in any one symbol. */
        public float $maxSymbolExposure = 500_000.0,
        /** Max gross exposure across the whole portfolio. */
        public float $maxGrossExposure = 2_000_000.0,
        /** Max share of gross exposure a single symbol may represent (0..1). */
        public float $maxConcentration = 0.60,
        /** Hard stop: max peak-to-trough equity drawdown before kill-switch (0..1). */
        public float $maxDrawdown = 0.20,
        /** Initial margin requirement as a fraction of notional. */
        public float $initialMarginRate = 0.10,
        /** Maintenance margin requirement as a fraction of notional. */
        public float $maintenanceMarginRate = 0.06,
    ) {}

    /** @return array<string,float> */
    public function toArray(): array
    {
        return [
            'maxOrderNotional' => $this->maxOrderNotional,
            'maxSymbolExposure' => $this->maxSymbolExposure,
            'maxGrossExposure' => $this->maxGrossExposure,
            'maxConcentration' => $this->maxConcentration,
            'maxDrawdown' => $this->maxDrawdown,
            'initialMarginRate' => $this->initialMarginRate,
            'maintenanceMarginRate' => $this->maintenanceMarginRate,
        ];
    }
}
