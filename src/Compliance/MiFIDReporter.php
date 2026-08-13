<?php

declare(strict_types=1);

namespace TradingPlatform\Compliance;

use TradingPlatform\Matching\Trade;
use TradingPlatform\Order\Order;

/**
 * Generates MiFID II-style transaction reports. In production these are batched
 * and submitted to an Approved Reporting Mechanism (ARM); here we build the
 * report record with the mandated fields and hand it to the audit trail.
 *
 * Field set is a representative subset of RTS 22 (transaction reporting).
 */
final class MiFIDReporter
{
    /** @var list<array<string,mixed>> */
    private array $reports = [];

    public function __construct(private readonly AuditLog $audit) {}

    public function reportTrade(Trade $trade, Order $order, int $tsMillis): array
    {
        $report = [
            'reportId' => 'MIFIR-'.$trade->id,
            'tradingVenue' => 'XOFF',                 // systematic internaliser / off-venue
            'instrument' => $trade->symbol,
            'instrumentClass' => 'CRYPTO',
            'buyerOrSeller' => $order->side->value,
            'quantity' => $trade->qty->toFloat(),
            'price' => $trade->price->toFloat(),
            'notional' => $trade->notional()->toFloat(),
            'currency' => 'USDT',
            'executingEntity' => $order->accountId,
            'investmentDecisionMaker' => $order->strategy ?? 'MANUAL',
            'tradingCapacity' => 'DEAL',              // dealing on own account
            'transactionTime' => gmdate('Y-m-d\TH:i:s\Z', intdiv($tsMillis, 1000)),
        ];
        $this->reports[] = $report;

        // Every transaction report is also written to the immutable audit chain.
        $this->audit->append('MIFID_TXN_REPORT', $report, $tsMillis);

        if (count($this->reports) > 200) {
            array_shift($this->reports);
        }

        return $report;
    }

    /** @return list<array<string,mixed>> */
    public function reports(int $limit = 20): array
    {
        return array_slice($this->reports, -$limit);
    }

    public function count(): int
    {
        return count($this->reports);
    }
}
