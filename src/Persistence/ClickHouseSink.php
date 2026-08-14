<?php

declare(strict_types=1);

namespace TradingPlatform\Persistence;

/**
 * ClickHouse analytics sink over the HTTP interface (port 8123) — no PHP
 * extension required. ClickHouse is columnar and built for fast aggregate
 * queries over huge trade/P&L histories, which is exactly the "analytics
 * queries" workload the assignment calls for.
 */
final class ClickHouseSink
{
    public function __construct(
        private readonly string $url = 'http://clickhouse:8123',
        private readonly string $user = 'trading',
        private readonly string $pass = 'trading',
        private readonly string $db = 'trading',
    ) {}

    /** Create the analytics tables (idempotent). */
    public function migrate(): void
    {
        $this->exec("CREATE DATABASE IF NOT EXISTS {$this->db}");
        $this->exec(
            "CREATE TABLE IF NOT EXISTS {$this->db}.trades_analytics (
                ts          DateTime64(3),
                account     String,
                symbol      String,
                side        String,
                strategy    String,
                price       Float64,
                qty         Float64,
                notional    Float64
            ) ENGINE = MergeTree() ORDER BY (symbol, ts)"
        );
    }

    /**
     * Bulk-insert trade rows via JSONEachRow.
     *
     * @param list<array{ts:string,account:string,symbol:string,side:string,strategy:string,price:float,qty:float,notional:float}> $rows
     */
    public function insertTrades(array $rows): int
    {
        if ($rows === []) {
            return 0;
        }
        $body = '';
        foreach ($rows as $r) {
            $body .= json_encode($r, JSON_UNESCAPED_SLASHES)."\n";
        }
        $this->exec("INSERT INTO {$this->db}.trades_analytics FORMAT JSONEachRow", $body);

        return count($rows);
    }

    /**
     * Run an analytics query and return the raw text result.
     */
    public function query(string $sql): string
    {
        return $this->exec($sql) ?? '';
    }

    /** Execute SQL (optionally with a request body for inserts). */
    private function exec(string $sql, string $body = ''): ?string
    {
        $url = $this->url.'/?'.http_build_query([
            'query' => $sql,
            'database' => $this->db,
            'user' => $this->user,
            'password' => $this->pass,
        ]);

        $ctx = stream_context_create(['http' => [
            'method' => 'POST',
            // Explicit Content-Length so empty-body queries (SELECT) are accepted.
            'header' => "Content-Type: text/plain\r\nContent-Length: ".strlen($body)."\r\n",
            'content' => $body,
            'timeout' => 8,
            'ignore_errors' => true,
        ]]);
        $res = @file_get_contents($url, false, $ctx);

        return $res === false ? null : $res;
    }

    public function ping(): bool
    {
        $res = @file_get_contents(rtrim($this->url, '/').'/ping', false,
            stream_context_create(['http' => ['timeout' => 3, 'ignore_errors' => true]]));

        return is_string($res) && str_contains($res, 'Ok');
    }
}
