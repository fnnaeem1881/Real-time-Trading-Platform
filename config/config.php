<?php

declare(strict_types=1);

/**
 * Central configuration. In production these come from environment variables;
 * for the demo we keep sane defaults so it runs with zero setup.
 */
return [
    'seed' => (int) (getenv('TP_SEED') ?: 42),
    'startingCash' => (float) (getenv('TP_CASH') ?: 250_000),
    'sigma' => (float) (getenv('TP_SIGMA') ?: 0.0009),
    'algorithm' => getenv('TP_ALGO') ?: 'FIFO',       // FIFO | PRO_RATA
    'maxSteps' => (int) (getenv('TP_MAX_STEPS') ?: 1200),
    'storagePath' => __DIR__.'/../storage/session.json',

    // Infrastructure endpoints (used by the Swoole/Redis/PG adapters in prod).
    'redis' => ['host' => getenv('REDIS_HOST') ?: '127.0.0.1', 'port' => 6379],
    'postgres' => ['dsn' => getenv('PG_DSN') ?: 'pgsql:host=127.0.0.1;dbname=trading'],
    'http' => ['host' => getenv('HTTP_HOST') ?: '0.0.0.0', 'port' => (int) (getenv('HTTP_PORT') ?: 9501)],
];
