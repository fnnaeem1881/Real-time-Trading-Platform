<?php

declare(strict_types=1);

namespace TradingPlatform\Persistence;

/**
 * Apache Kafka producer for the platform's event stream (ext-rdkafka).
 *
 * Trade, order and risk events are published to Kafka topics so downstream
 * consumers (analytics, ClickHouse loaders, surveillance, other services) can
 * process them asynchronously — the event-driven / message-queue backbone the
 * assignment asks for.
 */
final class KafkaPublisher
{
    private \RdKafka\Producer $producer;

    public function __construct(string $brokers = 'kafka:9092')
    {
        $conf = new \RdKafka\Conf();
        $conf->set('metadata.broker.list', $brokers);
        $conf->set('socket.timeout.ms', '2000');
        $conf->set('message.timeout.ms', '5000');
        $this->producer = new \RdKafka\Producer($conf);
    }

    /**
     * Publish one event to a topic (keyed for partition affinity by symbol).
     *
     * @param array<string,mixed> $payload
     */
    public function publish(string $topicName, array $payload, ?string $key = null): void
    {
        $topic = $this->producer->newTopic($topicName);
        $topic->produce(RD_KAFKA_PARTITION_UA, 0, json_encode($payload, JSON_UNESCAPED_SLASHES), $key);
        $this->producer->poll(0);
    }

    /** Block until all queued messages are delivered (or timeout). */
    public function flush(int $timeoutMs = 3000): int
    {
        return $this->producer->flush($timeoutMs);
    }

    /**
     * Consume up to $max messages from a topic (used by the demo/consumer).
     *
     * @return list<array<string,mixed>>
     */
    public static function consume(string $brokers, string $topicName, string $group = 'tp-consumer', int $max = 50, int $timeoutMs = 3000): array
    {
        $conf = new \RdKafka\Conf();
        $conf->set('metadata.broker.list', $brokers);
        $conf->set('group.id', $group);
        $conf->set('auto.offset.reset', 'earliest');
        $conf->set('enable.partition.eof', 'true');
        $consumer = new \RdKafka\KafkaConsumer($conf);
        $consumer->subscribe([$topicName]);

        $out = [];
        // Budget enough wall-time to cover the initial group join + fetch.
        $deadline = microtime(true) + max(6.0, $timeoutMs / 1000 * 3);
        while (count($out) < $max && microtime(true) < $deadline) {
            $msg = $consumer->consume(1000);
            if ($msg->err === RD_KAFKA_RESP_ERR_NO_ERROR) {
                $decoded = json_decode((string) $msg->payload, true);
                $out[] = is_array($decoded) ? $decoded : ['raw' => $msg->payload];
            } elseif ($msg->err === RD_KAFKA_RESP_ERR__PARTITION_EOF) {
                if ($out !== []) {
                    break; // read everything available
                }
                // else keep waiting — assignment may not be ready yet
            } elseif ($msg->err === RD_KAFKA_RESP_ERR__TIMED_OUT) {
                continue; // tolerate timeouts during group join
            } else {
                break;
            }
        }
        $consumer->close();

        return $out;
    }
}
