<?php

declare(strict_types=1);

namespace TradingPlatform\Persistence;

use TradingPlatform\Compliance\AuditLog;
use TradingPlatform\Order\Fill;
use TradingPlatform\Order\Order;
use TradingPlatform\Risk\Portfolio;

/**
 * PDO/Postgres persistence for the durable, ACID-critical records: accounts,
 * orders, fills, positions and the immutable audit chain.
 *
 * The whole push runs inside a single transaction — either the entire run's
 * data lands atomically or none of it does, which is the "ACID transactions for
 * critical operations" requirement.
 */
final class PostgresStore
{
    public function __construct(private readonly \PDO $pdo)
    {
        $this->pdo->setAttribute(\PDO::ATTR_ERRMODE, \PDO::ERRMODE_EXCEPTION);
    }

    public static function connect(string $dsn, string $user, string $pass): self
    {
        return new self(new \PDO($dsn, $user, $pass, [
            \PDO::ATTR_ERRMODE => \PDO::ERRMODE_EXCEPTION,
            \PDO::ATTR_DEFAULT_FETCH_MODE => \PDO::FETCH_ASSOC,
        ]));
    }

    /** Apply the schema (idempotent). */
    public function migrate(string $schemaSql): void
    {
        $this->pdo->exec($schemaSql);
    }

    /**
     * Persist a full run atomically under one account.
     *
     * @param list<Order> $orders
     * @param list<Fill> $fills
     * @return array{orders:int,fills:int,positions:int,audit:int}
     */
    public function persistRun(
        string $accountId,
        string $accountName,
        array $orders,
        array $fills,
        Portfolio $portfolio,
        AuditLog $audit,
    ): array {
        $this->pdo->beginTransaction();
        try {
            $this->upsertAccount($accountId, $accountName);

            $ordStmt = $this->pdo->prepare(
                'INSERT INTO orders (id, account_id, symbol, side, type, tif, price, qty, filled_qty, status, strategy)
                 VALUES (:id,:acct,:sym,:side,:type,:tif,:price,:qty,:filled,:status,:strategy)
                 ON CONFLICT (id) DO UPDATE SET filled_qty = EXCLUDED.filled_qty, status = EXCLUDED.status, updated_at = now()'
            );
            foreach ($orders as $o) {
                $ordStmt->execute([
                    ':id' => $o->id, ':acct' => $accountId, ':sym' => $o->symbol,
                    ':side' => $o->side->value, ':type' => $o->type->value, ':tif' => $o->tif->value,
                    ':price' => $o->price?->__toString(), ':qty' => (string) $o->qty,
                    ':filled' => (string) $o->filledQty, ':status' => $o->status->value, ':strategy' => $o->strategy,
                ]);
            }

            $fillStmt = $this->pdo->prepare(
                'INSERT INTO fills (trade_id, order_id, account_id, symbol, side, price, qty, liquidity)
                 VALUES (:tid,:oid,:acct,:sym,:side,:price,:qty,:liq)'
            );
            foreach ($fills as $f) {
                $fillStmt->execute([
                    ':tid' => $f->tradeId, ':oid' => $f->orderId, ':acct' => $accountId, ':sym' => $f->symbol,
                    ':side' => $f->side->value, ':price' => (string) $f->price, ':qty' => (string) $f->qty,
                    ':liq' => $f->liquidity->value,
                ]);
            }

            $posStmt = $this->pdo->prepare(
                'INSERT INTO positions (account_id, symbol, qty, avg_price, realized_pnl)
                 VALUES (:acct,:sym,:qty,:avg,:pnl)
                 ON CONFLICT (account_id, symbol) DO UPDATE SET qty = EXCLUDED.qty, avg_price = EXCLUDED.avg_price, realized_pnl = EXCLUDED.realized_pnl'
            );
            $posCount = 0;
            foreach ($portfolio->positions() as $symbol => $pos) {
                $posStmt->execute([
                    ':acct' => $accountId, ':sym' => $symbol, ':qty' => (string) $pos->qty,
                    ':avg' => (string) $pos->avgPrice, ':pnl' => (string) $pos->realizedPnl,
                ]);
                $posCount++;
            }

            $auditStmt = $this->pdo->prepare(
                'INSERT INTO audit_log (seq, ts, event_type, payload, prev_hash, hash)
                 VALUES (:seq, to_timestamp(:ts/1000.0), :type, :payload, :prev, :hash)
                 ON CONFLICT (seq) DO NOTHING'
            );
            $auditCount = 0;
            foreach ($audit->all() as $e) {
                $auditStmt->execute([
                    ':seq' => $e['seq'], ':ts' => $e['ts'], ':type' => $e['type'],
                    ':payload' => json_encode($e['payload'], JSON_UNESCAPED_SLASHES),
                    ':prev' => $e['prevHash'], ':hash' => $e['hash'],
                ]);
                $auditCount++;
            }

            $this->pdo->commit();

            return ['orders' => count($orders), 'fills' => count($fills), 'positions' => $posCount, 'audit' => $auditCount];
        } catch (\Throwable $e) {
            $this->pdo->rollBack();
            throw $e;
        }
    }

    private function upsertAccount(string $id, string $name): void
    {
        $this->pdo->prepare(
            'INSERT INTO accounts (id, name) VALUES (:id, :name)
             ON CONFLICT (id) DO UPDATE SET name = EXCLUDED.name'
        )->execute([':id' => $id, ':name' => $name]);
    }

    /** @return array<string,int> table => row count, for verification. */
    public function counts(): array
    {
        $out = [];
        foreach (['accounts', 'orders', 'fills', 'positions', 'audit_log'] as $t) {
            $out[$t] = (int) $this->pdo->query("SELECT count(*) FROM {$t}")->fetchColumn();
        }

        return $out;
    }

    public function pdo(): \PDO
    {
        return $this->pdo;
    }
}
