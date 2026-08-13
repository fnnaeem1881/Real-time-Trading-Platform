<?php

declare(strict_types=1);

/**
 * Front controller for the PHP built-in web server:
 *   php -S 127.0.0.1:8080 -t public public/index.php
 *
 * Serves the dashboard at "/" and JSON at "/api/*". Single-process, no external
 * infrastructure required — state is rebuilt deterministically per request.
 */

use TradingPlatform\Web\Api;

require __DIR__.'/../vendor/autoload.php';

$config = require __DIR__.'/../config/config.php';

$path = parse_url($_SERVER['REQUEST_URI'] ?? '/', PHP_URL_PATH) ?: '/';
$method = $_SERVER['REQUEST_METHOD'] ?? 'GET';

// API routes.
if (str_starts_with($path, '/api/')) {
    header('Content-Type: application/json');
    header('Cache-Control: no-store');

    $body = [];
    if ($method === 'POST') {
        $raw = file_get_contents('php://input') ?: '';
        $decoded = json_decode($raw, true);
        $body = is_array($decoded) ? $decoded : $_POST;
    }

    try {
        $result = (new Api($config))->handle($method, $path, $_GET, $body);
        http_response_code($result['status']);
        echo json_encode($result['body'], JSON_UNESCAPED_SLASHES);
    } catch (\Throwable $e) {
        http_response_code(500);
        echo json_encode(['error' => $e->getMessage(), 'at' => basename($e->getFile()).':'.$e->getLine()]);
    }

    return;
}

// Dashboard.
if ($path === '/' || $path === '/index.html') {
    header('Content-Type: text/html; charset=utf-8');
    readfile(__DIR__.'/dashboard.html');

    return;
}

http_response_code(404);
echo 'Not found';
