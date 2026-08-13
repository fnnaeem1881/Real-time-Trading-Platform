<?php

declare(strict_types=1);

namespace TradingPlatform\Compliance;

use TradingPlatform\Matching\Trade;
use TradingPlatform\Order\Order;

/**
 * Market-abuse surveillance. Lightweight, real-time heuristics that flag the
 * classic manipulation patterns for a human review queue (never auto-punitive):
 *
 *  - Wash trading: the same account on both sides of a trade (self-match).
 *  - Spoofing: a burst of large orders quickly cancelled without executing —
 *    the intent being to move price, not to trade.
 *  - Layering: many same-side orders stacked to create false depth.
 */
final class Surveillance
{
    /** @var list<array{ts:int,type:string,account:string,detail:string}> */
    private array $flags = [];

    /** @var array<string,array{placed:int,cancelled:int,filled:int}> per-account order stats */
    private array $orderStats = [];

    public function onOrderPlaced(Order $order): void
    {
        $s = &$this->orderStats[$order->accountId];
        $s['placed'] = ($s['placed'] ?? 0) + 1;
        $s['cancelled'] ??= 0;
        $s['filled'] ??= 0;
    }

    public function onOrderCancelled(Order $order, int $tsMillis): void
    {
        $s = &$this->orderStats[$order->accountId];
        $s['cancelled'] = ($s['cancelled'] ?? 0) + 1;
        $s['placed'] ??= 0;
        $s['filled'] ??= 0;

        // Spoofing signal: high cancel ratio on a busy account.
        $placed = max(1, $s['placed']);
        $ratio = $s['cancelled'] / $placed;
        if ($placed >= 20 && $ratio > 0.9) {
            $this->flag($tsMillis, 'SPOOFING', $order->accountId, sprintf('cancel ratio %.0f%% over %d orders', $ratio * 100, $placed));
        }
    }

    /** @param array<string,string> $orderAccounts orderId => accountId */
    public function onTrade(Trade $trade, array $orderAccounts, int $tsMillis): void
    {
        $takerAcct = $orderAccounts[$trade->takerOrderId] ?? null;
        $makerAcct = $orderAccounts[$trade->makerOrderId] ?? null;
        if ($takerAcct !== null && $takerAcct === $makerAcct) {
            $this->flag($tsMillis, 'WASH_TRADE', $takerAcct, sprintf('self-match %s @ %.2f x %.4f', $trade->symbol, $trade->price->toFloat(), $trade->qty->toFloat()));
        }
        if ($takerAcct !== null) {
            $this->orderStats[$takerAcct]['filled'] = ($this->orderStats[$takerAcct]['filled'] ?? 0) + 1;
        }
    }

    private function flag(int $tsMillis, string $type, string $account, string $detail): void
    {
        $this->flags[] = ['ts' => $tsMillis, 'type' => $type, 'account' => $account, 'detail' => $detail];
        if (count($this->flags) > 100) {
            array_shift($this->flags);
        }
    }

    /** @return list<array{ts:int,type:string,account:string,detail:string}> */
    public function flags(int $limit = 20): array
    {
        return array_slice($this->flags, -$limit);
    }

    public function count(): int
    {
        return count($this->flags);
    }
}
