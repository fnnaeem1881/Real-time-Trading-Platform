<?php

declare(strict_types=1);

/**
 * REAL WebSocket market-data ingestion (M1) — a Swoole coroutine WSS client that
 * connects to Binance's live stream and pushes normalized ticks into Redis (a
 * Stream for history + a cached BBO for the trading engine to consume). This is
 * the true streaming ingestion path (persistent wss:// connection, not polling),
 * with automatic reconnect/backoff.
 *
 * Run (inside the app container, where Swoole has OpenSSL):
 *   docker compose run --rm app php bin/ws_ingest.php
 *
 * Requires Swoole built with --enable-openssl (see Dockerfile).
 */

require __DIR__.'/../vendor/autoload.php';

$config = require __DIR__.'/../config/config.php';
$redisHost = getenv('REDIS_HOST') ?: '127.0.0.1';
$symbol = strtolower($argv[1] ?? 'btcusdt');

if (!extension_loaded('swoole')) {
    fwrite(STDERR, "ext-swoole required (run inside the Docker container).\n");
    exit(1);
}

use Swoole\Coroutine;
use Swoole\Coroutine\Http\Client;

Coroutine\run(function () use ($redisHost, $symbol) {
    $redis = new \Redis();
    $redis->connect($redisHost, 6379, 2.0);

    $backoff = 1;
    while (true) {
        // TLS WebSocket client to Binance (port 9443, SSL on).
        $cli = new Client('stream.binance.com', 9443, true);
        $cli->set(['timeout' => 15, 'websocket_mask' => true]);

        if (!$cli->upgrade('/ws/'.$symbol.'@bookTicker')) {
            fwrite(STDERR, "upgrade failed, retrying in {$backoff}s\n");
            Coroutine::sleep($backoff);
            $backoff = min(30, $backoff * 2);
            continue;
        }
        echo "connected: wss://stream.binance.com:9443/ws/{$symbol}@bookTicker\n";
        $backoff = 1;
        $count = 0;

        while (true) {
            $frame = $cli->recv(30);
            if ($frame === false || $frame === '') {
                echo "disconnected — reconnecting\n";
                break;
            }
            $d = json_decode((string) $frame->data, true);
            if (!isset($d['b'], $d['a'])) {
                continue;
            }
            $sym = strtoupper((string) ($d['s'] ?? $symbol));
            $now = (int) (microtime(true) * 1000);

            // Cache the live BBO + append to a Redis Stream (bounded).
            $redis->hMSet('ws:bbo:'.$sym, ['bid' => $d['b'], 'ask' => $d['a'], 'ts' => $now]);
            $redis->xAdd('ws.'.$sym.'.bbo', '*', ['bid' => (string) $d['b'], 'ask' => (string) $d['a'], 'ts' => (string) $now], 5000, true);

            if (++$count % 25 === 0) {
                echo "{$sym}  bid={$d['b']}  ask={$d['a']}  (streamed {$count})\n";
            }
        }
        $cli->close();
        Coroutine::sleep($backoff);
    }
});
