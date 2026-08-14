<?php

declare(strict_types=1);

/**
 * Performance benchmarking tool (M4c).
 *
 * Drives the matching engine with a large stream of randomized orders and
 * reports throughput, latency percentiles, and memory — the numbers you quote
 * for an ultra-low-latency claim.
 *
 * Usage:  php bench/matching_bench.php [orders]
 */

require __DIR__.'/../vendor/autoload.php';

use TradingPlatform\Matching\MatchingEngine;
use TradingPlatform\Matching\OrderBook;
use TradingPlatform\Order\Order;
use TradingPlatform\Order\OrderType;
use TradingPlatform\Order\Side;
use TradingPlatform\Order\TimeInForce;
use TradingPlatform\Perf\LatencyMonitor;
use TradingPlatform\Support\Decimal;
use TradingPlatform\Support\DeterministicRandom;
use TradingPlatform\Support\Ids;
use TradingPlatform\Support\VirtualClock;

$N = (int) ($argv[1] ?? 50_000);
$c = fn (string $s, string $col) => "\033[{$col}m{$s}\033[0m";

echo $c("Matching Engine Benchmark\n", '1;36');
echo "orders: ".number_format($N)."\n\n";

$ids = new Ids();
$clock = new VirtualClock();
$rng = new DeterministicRandom(42);
$book = new OrderBook('BTC/USDT');
$engine = new MatchingEngine($book, $ids, $clock);
$lat = new LatencyMonitor(8192);

$mem0 = memory_get_usage();
$seq = 0;
$trades = 0;
$fills = 0;

$t0 = hrtime(true);
for ($i = 0; $i < $N; $i++) {
    // Random two-sided flow around a drifting mid.
    $mid = 64_000 + sin($i / 500) * 200;
    $side = $rng->nextFloat() < 0.5 ? Side::Buy : Side::Sell;
    $type = $rng->nextFloat() < 0.35 ? OrderType::Market : OrderType::Limit;
    $offset = ($rng->nextFloat() - 0.5) * 40;
    $price = $type === OrderType::Limit ? Decimal::of(round($mid + $offset, 2)) : null;
    $qty = Decimal::of(round(0.05 + $rng->nextFloat() * 0.5, 4));
    $tif = $type === OrderType::Market ? TimeInForce::IOC : TimeInForce::GTC;

    $order = new Order($ids->nextOrderId(), 'ACCT'.($i % 8), 'BTC/USDT', $side, $type, $tif, $price, $qty, $clock->nowNanos(), ++$seq);

    $start = hrtime(true);
    $res = $engine->submit($order);
    $lat->record((hrtime(true) - $start) / 1000.0);

    $trades += count($res['trades']);
    $fills += count($res['fills']);
    $clock->advanceNanos(1000);
}
$elapsed = (hrtime(true) - $t0) / 1e9;
$memUsed = (memory_get_usage() - $mem0) / 1048576;

$snap = $lat->snapshot();
echo $c("Throughput\n", '1;33');
printf("  %s orders in %.3f s\n", number_format($N), $elapsed);
printf("  %s orders/sec\n", number_format((int) ($N / $elapsed)));
printf("  %s trades, %s fills\n\n", number_format($trades), number_format($fills));

echo $c("Per-order matching latency (µs)\n", '1;33');
printf("  p50 %.2f   p95 %.2f   p99 %.2f   max %.2f   mean %.2f\n\n",
    $snap['p50Us'], $snap['p95Us'], $snap['p99Us'], $snap['maxUs'], $snap['meanUs']);

echo $c("Memory\n", '1;33');
printf("  working set delta: %.1f MB   peak: %.1f MB\n\n", $memUsed, memory_get_peak_usage() / 1048576);

echo $c(sprintf("✓ %s orders/sec, p99 %.1fµs\n", number_format((int) ($N / $elapsed)), $snap['p99Us']), '1;32');
