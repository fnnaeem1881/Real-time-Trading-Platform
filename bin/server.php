<?php

declare(strict_types=1);

/**
 * Production-style HTTP server on Swoole (run inside WSL where ext-swoole is
 * installed). Serves the same dashboard and JSON API as the `php -S` front
 * controller, but on a coroutine HTTP server with a worker pool — the shape
 * required by the assignment (PHP 8.2+ with Swoole for high performance).
 *
 * Usage (in WSL):  php bin/server.php
 * Falls back with a clear message if ext-swoole is not loaded.
 */

require __DIR__.'/../vendor/autoload.php';

use TradingPlatform\Web\Api;

$config = require __DIR__.'/../config/config.php';

if (!extension_loaded('swoole')) {
    fwrite(STDERR, "ext-swoole is not loaded.\n");
    fwrite(STDERR, "Install it (in WSL):  bash scripts/install-swoole-wsl.sh\n");
    fwrite(STDERR, "Or use the built-in server instead:  composer serve\n");
    exit(1);
}

$host = (string) $config['http']['host'];
$port = (int) $config['http']['port'];

$server = new Swoole\HTTP\Server($host, $port);
$server->set([
    'worker_num' => swoole_cpu_num(),   // one worker per CPU core
    'max_request' => 0,
    'http_compression' => true,
    'enable_coroutine' => true,
    'open_cpu_affinity' => true,        // pin workers to CPU cores (M4: CPU affinity)
    'tcp_fastopen' => true,
    'open_tcp_nodelay' => true,         // low-latency network path
]);

$dashboard = file_get_contents(__DIR__.'/../public/dashboard.html');
$api = new Api($config);

// One PDO connection pool per worker (M4: connection pooling).
$pgPool = new \TradingPlatform\Perf\ConnectionPool(
    static fn (): \PDO => new \PDO(
        (string) $config['postgres']['dsn'],
        (string) (getenv('PG_USER') ?: 'trading'),
        (string) (getenv('PG_PASS') ?: 'trading'),
        [\PDO::ATTR_ERRMODE => \PDO::ERRMODE_EXCEPTION, \PDO::ATTR_TIMEOUT => 2]
    ),
    8
);

$server->on('start', function () use ($host, $port): void {
    echo "Swoole trading server on http://{$host}:{$port}  (workers=".swoole_cpu_num().")\n";
});

$server->on('request', function (Swoole\Http\Request $req, Swoole\Http\Response $res) use ($api, $dashboard, $pgPool): void {
    $path = $req->server['request_uri'] ?? '/';
    $method = strtoupper($req->server['request_method'] ?? 'GET');

    // Health endpoint: exercises the DB connection pool + reports runtime.
    if ($path === '/api/health') {
        $res->header('Content-Type', 'application/json');
        $dbOk = false;
        try {
            $dbOk = $pgPool->use(static fn (\PDO $pdo): bool => (bool) $pdo->query('SELECT 1')->fetchColumn());
        } catch (\Throwable $e) {
            $dbOk = false;
        }
        $res->end(json_encode([
            'status' => 'ok',
            'server' => 'swoole '.swoole_version(),
            'workers' => swoole_cpu_num(),
            'cpuAffinity' => true,
            'postgres' => $dbOk ? 'up' : 'down',
            'connectionPool' => $pgPool->stats(),
            'extensions' => [
                'swoole' => extension_loaded('swoole'),
                'rdkafka' => extension_loaded('rdkafka'),
                'redis' => extension_loaded('redis'),
                'pdo_pgsql' => extension_loaded('pdo_pgsql'),
            ],
        ], JSON_UNESCAPED_SLASHES));

        return;
    }

    if (str_starts_with($path, '/api/')) {
        $res->header('Content-Type', 'application/json');
        $res->header('Cache-Control', 'no-store');
        $body = [];
        if ($method === 'POST') {
            $decoded = json_decode($req->getContent() ?: '', true);
            $body = is_array($decoded) ? $decoded : [];
        }
        try {
            $out = $api->handle($method, $path, $req->get ?? [], $body);
            $res->status($out['status']);
            $res->end(json_encode($out['body'], JSON_UNESCAPED_SLASHES));
        } catch (\Throwable $e) {
            $res->status(500);
            $res->end(json_encode(['error' => $e->getMessage()]));
        }

        return;
    }

    if ($path === '/' || $path === '/index.html') {
        $res->header('Content-Type', 'text/html; charset=utf-8');
        $res->end($dashboard);

        return;
    }

    $res->status(404);
    $res->end('Not found');
});

$server->start();
