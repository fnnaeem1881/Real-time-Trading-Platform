<?php

declare(strict_types=1);

namespace TradingPlatform\MarketData;

use TradingPlatform\Support\Clock;
use TradingPlatform\Support\Decimal;

/**
 * Normalizes raw, venue-specific ticker payloads into the canonical MarketTick.
 * Each venue speaks a different JSON dialect; this is the one place that knows
 * their quirks so nothing downstream does.
 */
final class Normalizer
{
    public function __construct(private readonly Clock $clock) {}

    /**
     * Binance bookTicker stream:
     *   {"s":"BTCUSDT","b":"64000.10","a":"64000.50","B":"1.2","A":"0.9","E":169..}
     *
     * @param array<string,mixed> $payload
     */
    public function fromBinance(array $payload): MarketTick
    {
        $symbol = $this->canonicalSymbol((string) ($payload['s'] ?? ''));

        return new MarketTick(
            symbol: $symbol,
            venue: 'BINANCE',
            bid: Decimal::of((string) ($payload['b'] ?? '0')),
            ask: Decimal::of((string) ($payload['a'] ?? '0')),
            last: Decimal::of((string) ($payload['c'] ?? $payload['a'] ?? '0')),
            volume: Decimal::of((string) ($payload['V'] ?? $payload['A'] ?? '0')),
            exchangeTsMillis: (int) ($payload['E'] ?? $this->clock->nowMillis()),
            localTsMillis: $this->clock->nowMillis(),
        );
    }

    /**
     * Coinbase ticker channel:
     *   {"product_id":"BTC-USD","best_bid":"64000.1","best_ask":"64000.5","price":"64000.3","time":"..."}
     *
     * @param array<string,mixed> $payload
     */
    public function fromCoinbase(array $payload): MarketTick
    {
        $symbol = $this->canonicalSymbol((string) ($payload['product_id'] ?? ''));
        $ts = isset($payload['time']) ? (int) (strtotime((string) $payload['time']) * 1000) : $this->clock->nowMillis();

        return new MarketTick(
            symbol: $symbol,
            venue: 'COINBASE',
            bid: Decimal::of((string) ($payload['best_bid'] ?? '0')),
            ask: Decimal::of((string) ($payload['best_ask'] ?? '0')),
            last: Decimal::of((string) ($payload['price'] ?? '0')),
            volume: Decimal::of((string) ($payload['volume_24h'] ?? '0')),
            exchangeTsMillis: $ts,
            localTsMillis: $this->clock->nowMillis(),
        );
    }

    /** BTCUSDT / BTC-USD / BTC-USDT -> BTC/USDT canonical form. */
    private function canonicalSymbol(string $raw): string
    {
        $raw = strtoupper(str_replace('-', '', $raw));
        foreach (['USDT', 'USDC', 'USD', 'EUR', 'BTC', 'ETH'] as $quote) {
            if (str_ends_with($raw, $quote) && strlen($raw) > strlen($quote)) {
                $base = substr($raw, 0, -strlen($quote));

                return $base.'/'.($quote === 'USD' ? 'USDT' : $quote);
            }
        }

        return $raw;
    }
}
