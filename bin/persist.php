<?php

declare(strict_types=1);

/**
 * Data push: run the platform for N steps in recording mode and persist the
 * whole run (accounts, orders, fills, positions, immutable audit chain) into
 * Postgres/TimescaleDB — under a named trading account — plus publish trades to
 * a Redis Stream. Designed to run inside the Docker app container where the
 * postgres/redis services are reachable by name.
 *
 * Usage:  php bin/persist.php [steps] [seed]
 * Env:    PG_DSN, PG_USER, PG_PASS, REDIS_HOST, TRADER_ACCOUNT, TRADER_NAME
 */

require __DIR__.'/../vendor/autoload.php';

use TradingPlatform\Engine\TradingPlatform;
use TradingPlatform\Persistence\PostgresStore;
use TradingPlatform\Persistence\RedisPublisher;

$steps = (int) ($argv[1] ?? 600);
$seed = (int) ($argv[2] ?? 42);

$accountId = getenv('TRADER_ACCOUNT') ?: 'MEHEDI-HASAN';
$accountName = getenv('TRADER_NAME') ?: 'Mehedi Hasan';
$dsn = getenv('PG_DSN') ?: 'pgsql:host=postgres;port=5432;dbname=trading';
$pgUser = getenv('PG_USER') ?: 'trading';
$pgPass = getenv('PG_PASS') ?: 'trading';
$redisHost = getenv('REDIS_HOST') ?: 'redis';

$c = fn (string $s, string $col) => "\033[{$col}m{$s}\033[0m";
echo $c("▶ Data push — persisting run to Postgres under '{$accountName}'\n", '1;36');
echo "  account={$accountId}  steps={$steps}  seed={$seed}\n";

// 1. Run the simulation with recording enabled.
$p = new TradingPlatform($seed, [
    'record' => true,
    'houseAccount' => $accountId,
    'houseName' => $accountName,
]);
$p->runTo($steps);
$snap = $p->snapshot();
echo "  simulated: {$snap['totals']['trades']} trades, ".count($p->recordedOrders())." of our orders, ".count($p->recordedFills())." of our fills\n";
echo "  audit chain: {$snap['compliance']['auditCount']} entries, valid=".($snap['compliance']['auditValid'] ? 'yes' : 'NO')."\n";

// 2. Connect to Postgres (retry — the DB may still be starting).
$store = null;
for ($attempt = 1; $attempt <= 20; $attempt++) {
    try {
        $store = PostgresStore::connect($dsn, $pgUser, $pgPass);
        break;
    } catch (\Throwable $e) {
        echo "  waiting for postgres ({$attempt}/20): ".$e->getMessage()."\n";
        sleep(2);
    }
}
if ($store === null) {
    fwrite(STDERR, "Could not connect to Postgres at {$dsn}\n");
    exit(1);
}

// 3. Migrate schema + persist the run atomically.
$store->migrate((string) file_get_contents(__DIR__.'/../db/migrations/001_schema.sql'));
$written = $store->persistRun($accountId, $accountName, $p->recordedOrders(), $p->recordedFills(), $p->housePortfolio(), $p->auditLog());

echo $c("\n✓ Persisted to Postgres (single ACID transaction):\n", '1;32');
printf("    orders=%d  fills=%d  positions=%d  audit=%d\n", $written['orders'], $written['fills'], $written['positions'], $written['audit']);

$counts = $store->counts();
echo "  table row counts: ".json_encode($counts)."\n";

// 4. Show the persisted account + position (proof it's under the trader's name).
$row = $store->pdo()->query("SELECT a.name, p.symbol, p.qty, p.realized_pnl
    FROM positions p JOIN accounts a ON a.id = p.account_id
    WHERE a.id = ".$store->pdo()->quote($accountId))->fetch();
if ($row) {
    echo "  position of record → {$row['name']}: {$row['symbol']} qty={$row['qty']} realizedPnl={$row['realized_pnl']}\n";
}

// 5. Best-effort: publish trades to a Redis Stream.
if (extension_loaded('redis')) {
    try {
        $redis = new RedisPublisher($redisHost, 6379);
        foreach (array_slice($snap['tape'], 0, 15) as $t) {
            $redis->publishTrade($snap['symbol'], $t);
        }
        if ($snap['bbo']['bid'] && $snap['bbo']['ask']) {
            $redis->cacheBbo($snap['symbol'], (float) $snap['bbo']['bid'], (float) $snap['bbo']['ask']);
        }
        echo $c("✓ Published to Redis Stream ", '1;32')."md.{$snap['symbol']}.trades (len=".$redis->streamLength($snap['symbol']).")\n";
    } catch (\Throwable $e) {
        echo "  (redis publish skipped: ".$e->getMessage().")\n";
    }
} else {
    echo "  (ext-redis not loaded; skipping Redis publish)\n";
}

// 6. ClickHouse: load trades into the columnar analytics store + sample query.
$chUrl = getenv('CLICKHOUSE_URL') ?: 'http://clickhouse:8123';
try {
    $ch = new \TradingPlatform\Persistence\ClickHouseSink($chUrl);
    if ($ch->ping()) {
        $ch->migrate();
        $rows = [];
        foreach ($p->recordedFills() as $f) {
            $rows[] = [
                'ts' => gmdate('Y-m-d H:i:s.', intdiv($f->tsNanos, 1_000_000_000)).sprintf('%03d', intdiv($f->tsNanos, 1_000_000) % 1000),
                'account' => $accountId, 'symbol' => $f->symbol, 'side' => $f->side->value,
                'strategy' => $f->strategy ?? 'MANUAL', 'price' => $f->price->toFloat(),
                'qty' => $f->qty->toFloat(), 'notional' => $f->notional()->toFloat(),
            ];
        }
        $n = $ch->insertTrades($rows);
        $vwap = trim($ch->query("SELECT symbol, round(sum(notional)/sum(qty),2) AS vwap, count() AS fills FROM trading.trades_analytics GROUP BY symbol FORMAT TSV"));
        echo $c("✓ Loaded {$n} trades into ClickHouse", '1;32')." — analytics VWAP:\n    ".str_replace("\n", "\n    ", $vwap)."\n";
    } else {
        echo "  (ClickHouse not reachable at {$chUrl}; skipping)\n";
    }
} catch (\Throwable $e) {
    echo "  (ClickHouse load skipped: ".$e->getMessage().")\n";
}

// 7. Kafka: publish trade events to the event stream.
if (extension_loaded('rdkafka')) {
    try {
        $kafka = new \TradingPlatform\Persistence\KafkaPublisher(getenv('KAFKA_BROKERS') ?: 'kafka:9092');
        $published = 0;
        foreach (array_slice($p->recordedFills(), 0, 200) as $f) {
            $kafka->publish('trades', $f->toArray(), $f->symbol);
            $published++;
        }
        $kafka->flush(3000);
        echo $c("✓ Published {$published} trade events to Kafka topic 'trades'\n", '1;32');
    } catch (\Throwable $e) {
        echo "  (Kafka publish skipped: ".$e->getMessage().")\n";
    }
} else {
    echo "  (ext-rdkafka not loaded; skipping Kafka publish)\n";
}

echo "\n".$c('Done.', '1;32')." Query it:  docker compose exec postgres psql -U trading -d trading -c 'SELECT * FROM positions;'\n";
