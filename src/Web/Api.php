<?php

declare(strict_types=1);

namespace TradingPlatform\Web;

use TradingPlatform\Engine\TradingPlatform;

/**
 * Stateless HTTP API over the trading platform.
 *
 * The platform run is a pure function of (seed, steps, manualOrders), so we
 * persist only those three values and rebuild identical state on every request.
 * This is what lets the classic single-process `php -S` server serve a "live"
 * dashboard without any shared in-memory state — and it works identically under
 * the Swoole server.
 */
final class Api
{
    /** @param array<string,mixed> $config */
    public function __construct(private readonly array $config) {}

    /**
     * @param array<string,mixed> $query
     * @param array<string,mixed> $body
     * @return array{status:int,body:array<string,mixed>}
     */
    public function handle(string $method, string $path, array $query, array $body): array
    {
        return match (true) {
            $path === '/api/state' => $this->state($query),
            $path === '/api/order' && $method === 'POST' => $this->order($body),
            $path === '/api/reset' && $method === 'POST' => $this->reset($body),
            $path === '/api/config' => ['status' => 200, 'body' => $this->publicConfig()],
            default => ['status' => 404, 'body' => ['error' => 'not found', 'path' => $path]],
        };
    }

    /** @param array<string,mixed> $query @return array{status:int,body:array<string,mixed>} */
    private function state(array $query): array
    {
        $session = $this->load();
        $target = isset($query['steps']) ? (int) $query['steps'] : $session['steps'];
        $target = max(0, min($target, (int) $this->config['maxSteps']));
        $session['steps'] = $target;
        $this->save($session);

        $platform = $this->build($session);
        $platform->runTo($target);

        return ['status' => 200, 'body' => $platform->snapshot()];
    }

    /** @param array<string,mixed> $body @return array{status:int,body:array<string,mixed>} */
    private function order(array $body): array
    {
        $side = strtoupper((string) ($body['side'] ?? ''));
        $type = strtoupper((string) ($body['type'] ?? 'MARKET'));
        $tif = strtoupper((string) ($body['tif'] ?? ($type === 'MARKET' ? 'IOC' : 'GTC')));
        $qty = (float) ($body['qty'] ?? 0);
        $price = isset($body['price']) && $body['price'] !== '' && $body['price'] !== null ? (float) $body['price'] : null;

        if (!in_array($side, ['BUY', 'SELL'], true) || $qty <= 0) {
            return ['status' => 422, 'body' => ['error' => 'side must be BUY/SELL and qty > 0']];
        }
        if ($type === 'LIMIT' && $price === null) {
            return ['status' => 422, 'body' => ['error' => 'limit orders require a price']];
        }

        $session = $this->load();
        $session['manualOrders'][] = [
            'step' => $session['steps'] + 1,
            'side' => $side,
            'type' => $type,
            'tif' => $tif,
            'price' => $price,
            'qty' => $qty,
        ];
        $this->save($session);

        return ['status' => 200, 'body' => ['ok' => true, 'scheduledStep' => $session['steps'] + 1, 'order' => end($session['manualOrders'])]];
    }

    /** @param array<string,mixed> $body @return array{status:int,body:array<string,mixed>} */
    private function reset(array $body): array
    {
        $seed = isset($body['seed']) ? (int) $body['seed'] : (int) $this->config['seed'];
        $algorithm = strtoupper((string) ($body['algorithm'] ?? $this->config['algorithm']));
        $session = ['seed' => $seed, 'steps' => 0, 'algorithm' => $algorithm, 'manualOrders' => []];
        $this->save($session);

        return ['status' => 200, 'body' => ['ok' => true, 'seed' => $seed, 'algorithm' => $algorithm]];
    }

    /** @param array<string,mixed> $session */
    private function build(array $session): TradingPlatform
    {
        return new TradingPlatform((int) $session['seed'], [
            'startingCash' => (float) $this->config['startingCash'],
            'sigma' => (float) $this->config['sigma'],
            'algorithm' => (string) ($session['algorithm'] ?? $this->config['algorithm']),
            'manualOrders' => $session['manualOrders'] ?? [],
        ]);
    }

    /** @return array{seed:int,steps:int,algorithm:string,manualOrders:list<array<string,mixed>>} */
    private function load(): array
    {
        $path = (string) $this->config['storagePath'];
        if (is_file($path)) {
            $data = json_decode((string) file_get_contents($path), true);
            if (is_array($data)) {
                return [
                    'seed' => (int) ($data['seed'] ?? $this->config['seed']),
                    'steps' => (int) ($data['steps'] ?? 0),
                    'algorithm' => (string) ($data['algorithm'] ?? $this->config['algorithm']),
                    'manualOrders' => $data['manualOrders'] ?? [],
                ];
            }
        }

        return ['seed' => (int) $this->config['seed'], 'steps' => 0, 'algorithm' => (string) $this->config['algorithm'], 'manualOrders' => []];
    }

    /** @param array<string,mixed> $session */
    private function save(array $session): void
    {
        $path = (string) $this->config['storagePath'];
        $dir = dirname($path);
        if (!is_dir($dir)) {
            @mkdir($dir, 0777, true);
        }
        file_put_contents($path, json_encode($session, JSON_PRETTY_PRINT), LOCK_EX);
    }

    /** @return array<string,mixed> */
    private function publicConfig(): array
    {
        return [
            'symbol' => TradingPlatform::SYMBOL,
            'startingCash' => (float) $this->config['startingCash'],
            'maxSteps' => (int) $this->config['maxSteps'],
            'algorithm' => (string) $this->config['algorithm'],
        ];
    }
}
