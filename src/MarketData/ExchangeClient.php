<?php

declare(strict_types=1);

namespace TradingPlatform\MarketData;

/**
 * Fetches REAL market data from public exchange REST endpoints — no API key, no
 * account, read-only. This is the live implementation behind the FeedClient
 * concept: Binance and Coinbase best bid/offer, Binance depth (order book) and
 * recent trades.
 *
 * All calls are short-timeout and fail soft (return null) so a transient network
 * blip degrades gracefully instead of crashing the loop.
 */
final class ExchangeClient
{
    // Binance's public market-data mirror — permissive, no auth, global.
    private const BINANCE = 'https://data-api.binance.vision';
    private const COINBASE = 'https://api.exchange.coinbase.com';

    public function __construct(private readonly float $timeout = 4.0) {}

    /**
     * Fetch the whole live market picture in ONE parallel batch via curl_multi —
     * Binance BBO + depth + trades, Coinbase BBO, and ETH BBO fire concurrently
     * instead of serially, cutting a ~2s round-trip chain to a single hop. This
     * is the network-optimization path (falls back to sequential if ext-curl is
     * unavailable).
     *
     * @return array{binance:?array,depth:?array,trades:list<array<string,mixed>>,coinbase:?array,eth:?array}
     */
    public function fetchMarket(string $symbol = 'BTCUSDT', string $product = 'BTC-USD', string $ethSymbol = 'ETHUSDT'): array
    {
        $urls = [
            'binance' => self::BINANCE.'/api/v3/ticker/bookTicker?symbol='.$symbol,
            'depth' => self::BINANCE.'/api/v3/depth?symbol='.$symbol.'&limit=12',
            'trades' => self::BINANCE.'/api/v3/trades?symbol='.$symbol.'&limit=25',
            'coinbase' => self::COINBASE.'/products/'.$product.'/ticker',
            'eth' => self::BINANCE.'/api/v3/ticker/bookTicker?symbol='.$ethSymbol,
        ];

        if (!function_exists('curl_multi_init')) {
            return $this->fetchSequential($symbol, $product, $ethSymbol);
        }

        $mh = curl_multi_init();
        $handles = [];
        foreach ($urls as $key => $url) {
            $ch = curl_init($url);
            curl_setopt_array($ch, [
                CURLOPT_RETURNTRANSFER => true,
                CURLOPT_TIMEOUT => (int) ceil($this->timeout),
                CURLOPT_USERAGENT => 'RealTimeTradingPlatform/1.0',
                CURLOPT_HTTPHEADER => ['Accept: application/json'],
            ]);
            curl_multi_add_handle($mh, $ch);
            $handles[$key] = $ch;
        }

        do {
            $status = curl_multi_exec($mh, $running);
            if ($running) {
                curl_multi_select($mh, 1.0);
            }
        } while ($running && $status === CURLM_OK);

        $raw = [];
        foreach ($handles as $key => $ch) {
            $body = curl_multi_getcontent($ch);
            $raw[$key] = is_string($body) && $body !== '' ? json_decode($body, true) : null;
            curl_multi_remove_handle($mh, $ch);
            curl_close($ch);
        }
        curl_multi_close($mh);

        $shaped = $this->shape($raw);
        // If the batch failed (e.g. a host without a curl CA bundle), fall back
        // to the file_get_contents path which uses PHP's own TLS.
        if ($shaped['binance'] === null) {
            return $this->fetchSequential($symbol, $product, $ethSymbol);
        }

        return $shaped;
    }

    /** @return array{binance:?array,depth:?array,trades:list<array<string,mixed>>,coinbase:?array,eth:?array} */
    private function fetchSequential(string $symbol, string $product, string $ethSymbol): array
    {
        return [
            'binance' => $this->binanceBbo($symbol),
            'depth' => $this->binanceDepth($symbol, 12),
            'trades' => $this->binanceTrades($symbol, 25),
            'coinbase' => $this->coinbaseBbo($product),
            'eth' => $this->binanceBbo($ethSymbol),
        ];
    }

    /**
     * @param array<string,mixed> $raw
     * @return array{binance:?array,depth:?array,trades:list<array<string,mixed>>,coinbase:?array,eth:?array}
     */
    private function shape(array $raw): array
    {
        $bbo = static function ($d): ?array {
            return isset($d['bidPrice'], $d['askPrice'])
                ? ['bid' => (float) $d['bidPrice'], 'ask' => (float) $d['askPrice'], 'bidQty' => (float) ($d['bidQty'] ?? 0), 'askQty' => (float) ($d['askQty'] ?? 0)]
                : null;
        };
        $depth = null;
        if (isset($raw['depth']['bids'], $raw['depth']['asks'])) {
            $map = static fn (array $rows): array => array_map(static fn (array $r): array => ['price' => (float) $r[0], 'qty' => (float) $r[1]], $rows);
            $depth = ['bids' => $map($raw['depth']['bids']), 'asks' => $map($raw['depth']['asks'])];
        }
        $trades = [];
        if (is_array($raw['trades'] ?? null)) {
            foreach ($raw['trades'] as $t) {
                if (isset($t['price'])) {
                    $trades[] = ['price' => (float) $t['price'], 'qty' => (float) $t['qty'], 'side' => ($t['isBuyerMaker'] ?? false) ? 'SELL' : 'BUY', 'ts' => (int) ($t['time'] ?? 0)];
                }
            }
        }
        $cb = isset($raw['coinbase']['bid'], $raw['coinbase']['ask']) ? ['bid' => (float) $raw['coinbase']['bid'], 'ask' => (float) $raw['coinbase']['ask']] : null;

        return ['binance' => $bbo($raw['binance'] ?? null), 'depth' => $depth, 'trades' => $trades, 'coinbase' => $cb, 'eth' => $bbo($raw['eth'] ?? null)];
    }

    /**
     * Binance best bid/offer.
     *
     * @return array{bid:float,ask:float,bidQty:float,askQty:float}|null
     */
    public function binanceBbo(string $symbol = 'BTCUSDT'): ?array
    {
        $d = $this->getJson(self::BINANCE.'/api/v3/ticker/bookTicker?symbol='.$symbol);
        if (!isset($d['bidPrice'], $d['askPrice'])) {
            return null;
        }

        return [
            'bid' => (float) $d['bidPrice'], 'ask' => (float) $d['askPrice'],
            'bidQty' => (float) $d['bidQty'], 'askQty' => (float) $d['askQty'],
        ];
    }

    /**
     * Binance order-book depth.
     *
     * @return array{bids:list<array{price:float,qty:float}>,asks:list<array{price:float,qty:float}>}|null
     */
    public function binanceDepth(string $symbol = 'BTCUSDT', int $limit = 12): ?array
    {
        $d = $this->getJson(self::BINANCE.'/api/v3/depth?symbol='.$symbol.'&limit='.$limit);
        if (!isset($d['bids'], $d['asks'])) {
            return null;
        }
        $map = static fn (array $rows): array => array_map(
            static fn (array $r): array => ['price' => (float) $r[0], 'qty' => (float) $r[1]],
            $rows
        );

        return ['bids' => $map($d['bids']), 'asks' => $map($d['asks'])];
    }

    /**
     * Binance recent trades.
     *
     * @return list<array{price:float,qty:float,side:string,ts:int}>
     */
    public function binanceTrades(string $symbol = 'BTCUSDT', int $limit = 25): array
    {
        $d = $this->getJson(self::BINANCE.'/api/v3/trades?symbol='.$symbol.'&limit='.$limit);
        if (!is_array($d)) {
            return [];
        }
        $out = [];
        foreach ($d as $t) {
            if (!isset($t['price'])) {
                continue;
            }
            // isBuyerMaker=true → the aggressor was a SELLER.
            $out[] = [
                'price' => (float) $t['price'],
                'qty' => (float) $t['qty'],
                'side' => ($t['isBuyerMaker'] ?? false) ? 'SELL' : 'BUY',
                'ts' => (int) ($t['time'] ?? 0),
            ];
        }

        return $out;
    }

    /**
     * Coinbase best bid/offer (for the second venue in the aggregator).
     *
     * @return array{bid:float,ask:float}|null
     */
    public function coinbaseBbo(string $product = 'BTC-USD'): ?array
    {
        $d = $this->getJson(self::COINBASE.'/products/'.$product.'/ticker');
        if (!isset($d['bid'], $d['ask'])) {
            return null;
        }

        return ['bid' => (float) $d['bid'], 'ask' => (float) $d['ask']];
    }

    /**
     * @return array<mixed>|null decoded JSON, or null on any failure
     */
    private function getJson(string $url): ?array
    {
        $ctx = stream_context_create(['http' => [
            'timeout' => $this->timeout,
            'header' => "User-Agent: RealTimeTradingPlatform/1.0\r\nAccept: application/json\r\n",
            'ignore_errors' => true,
        ], 'ssl' => ['verify_peer' => true, 'verify_peer_name' => true]]);

        $raw = @file_get_contents($url, false, $ctx);
        if ($raw === false || $raw === '') {
            return null;
        }
        $data = json_decode($raw, true);

        return is_array($data) ? $data : null;
    }
}
