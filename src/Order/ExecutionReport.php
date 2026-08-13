<?php

declare(strict_types=1);

namespace TradingPlatform\Order;

/**
 * FIX-style execution report / trade confirmation emitted on every order state
 * change (accept, fill, cancel, reject). This is the client-facing acknowledgement.
 */
final class ExecutionReport
{
    public function __construct(
        public readonly string $orderId,
        public readonly string $accountId,
        public readonly string $symbol,
        public readonly OrderStatus $status,
        public readonly string $execType,   // NEW | TRADE | CANCELED | REJECTED
        public readonly float $lastQty,
        public readonly float $lastPrice,
        public readonly float $cumQty,
        public readonly float $leavesQty,
        public readonly ?float $avgPrice,
        public readonly int $tsNanos,
        public readonly ?string $reason = null,
    ) {}

    /** @return array<string,mixed> */
    public function toArray(): array
    {
        return [
            'orderId' => $this->orderId,
            'account' => $this->accountId,
            'symbol' => $this->symbol,
            'status' => $this->status->value,
            'execType' => $this->execType,
            'lastQty' => $this->lastQty,
            'lastPrice' => $this->lastPrice,
            'cumQty' => $this->cumQty,
            'leavesQty' => $this->leavesQty,
            'avgPrice' => $this->avgPrice,
            'ts' => $this->tsNanos,
            'reason' => $this->reason,
        ];
    }
}
