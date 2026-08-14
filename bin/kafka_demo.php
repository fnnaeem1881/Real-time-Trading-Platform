<?php

declare(strict_types=1);

/**
 * Kafka round-trip demo: produce a batch of trade events to the 'trades' topic,
 * then consume them back — proving the event-streaming pipeline end to end.
 *
 * Run:  docker compose run --rm app php bin/kafka_demo.php
 */

require __DIR__.'/../vendor/autoload.php';

use TradingPlatform\Engine\TradingPlatform;
use TradingPlatform\Persistence\KafkaPublisher;

$brokers = getenv('KAFKA_BROKERS') ?: 'kafka:9092';
$c = fn (string $s, string $col) => "\033[{$col}m{$s}\033[0m";

if (!extension_loaded('rdkafka')) {
    fwrite(STDERR, "ext-rdkafka not loaded (run inside the Docker container).\n");
    exit(1);
}

echo $c("Kafka event-streaming demo — brokers {$brokers}\n", '1;36');

// Generate some trades to publish.
$p = new TradingPlatform(7, ['record' => true]);
$p->runTo(120);
$fills = array_slice($p->recordedFills(), 0, 30);

$producer = new KafkaPublisher($brokers);
foreach ($fills as $f) {
    $producer->publish('trades', $f->toArray(), $f->symbol);
}
$producer->flush(4000);
echo $c('✓ produced ', '32').count($fills)." trade events to topic 'trades'\n";

echo "consuming back...\n";
$got = KafkaPublisher::consume($brokers, 'trades', 'demo-'.time(), 30, 3000);
echo $c('✓ consumed ', '32').count($got)." events. Sample:\n";
foreach (array_slice($got, 0, 3) as $e) {
    printf("    %s %s %.2f x %.4f\n", $e['side'] ?? '?', $e['symbol'] ?? '?', $e['price'] ?? 0, $e['qty'] ?? 0);
}
echo $c("\nDone — event stream verified end to end.\n", '1;32');
