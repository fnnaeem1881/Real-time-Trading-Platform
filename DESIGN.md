# Real-time Trading Platform — Architecture & Design

> High-frequency cryptocurrency trading platform with real-time risk management,
> regulatory compliance, and sub-millisecond execution.
> Stack: **PHP 8.2+ / Swoole**, PostgreSQL/TimescaleDB, Redis, Kafka, ClickHouse.

This document is the design to review **before** implementation. It maps every
graded milestone to a concrete component, defines the concurrency and data
models, and proposes a realistic build order.

---

## 1. Goals & constraints

| Requirement | Target | How the design meets it |
|---|---|---|
| Throughput | thousands of trades/sec | Sharded matching engine, one process per symbol, lock-free intra-process |
| Latency | sub-millisecond match | In-process order books, pre-allocated object pools, no DB on hot path |
| Durability | ACID for critical ops | Postgres for orders/fills/positions; append-only audit log |
| Real-time risk | pre-trade + continuous | Synchronous pre-trade gate + async portfolio risk workers |
| Compliance | MiFID II, immutable audit | Hash-chained audit log, ClickHouse for surveillance queries |
| Time-series analytics | tick history, P&L | TimescaleDB hypertables + ClickHouse for heavy analytics |

**Non-goals for the exam build:** real exchange keys, real money, production
HA. Integrations that need external infra (Kafka, ClickHouse) are behind
interfaces so the core runs locally without them.

---

## 2. Architecture overview

Event-driven, actor-style processes communicating over Redis Streams / Kafka.
Each box below is an independent Swoole process (or process pool).

```
                          ┌─────────────────────────────────────────┐
   Exchanges              │            MARKET DATA ENGINE (M1)        │
  Binance ───ws──►┌───────┴───────┐   normalize │ orderbook │ agg    │
  Coinbase ──ws──►│ Feed Ingestors│──► Redis Streams: md.{symbol}    │
                  └───────┬───────┘   indicators │ anomaly filter    │
                          │           └──────────────┬────────────────┘
                          │                          │ pub/sub
                          ▼                          ▼
              ┌───────────────────────┐   ┌─────────────────────────┐
              │  STRATEGY WORKERS (M2) │   │  ANALYTICS (M4)          │
              │  market-making,        │   │  P&L, Sharpe, backtest   │
              │  arbitrage, signals    │   │  → ClickHouse            │
              └───────────┬───────────┘   └─────────────────────────┘
                          │ NewOrder
                          ▼
   ┌───────────────────────────────────────────────────────────────┐
   │                   ORDER MANAGEMENT / ROUTING (M2)               │
   │  validate → PRE-TRADE RISK GATE (M3) → route (FOK/IOC/slice)    │
   └───────────┬───────────────────────────────────────┬────────────┘
               │ accepted                               │ rejected
               ▼                                        ▼
   ┌───────────────────────────┐              (risk alert / audit)
   │  MATCHING ENGINE (M2)      │   one process per symbol shard
   │  price-time priority book  │──► Fills ──► Redis Stream: fills
   │  FIFO / Pro-rata           │
   └───────────┬───────────────┘
               │ fills
               ▼
   ┌───────────────────────────────────────────────────────────────┐
   │  POST-TRADE: Positions · P&L · Portfolio Risk (VaR/CVaR) (M3)   │
   │  Postgres (ACID) · TimescaleDB (ticks) · Audit chain            │
   └───────────────────────────────────────────────────────────────┘
```

**Data-flow principle:** the hot path (order → risk gate → match → fill) never
touches a synchronous disk write. Persistence and analytics happen off the hot
path by consuming fill/event streams.

---

## 3. Concurrency & process model

Swoole gives us three primitives; we use each deliberately:

- **Process pool** — feed ingestors and matching-engine shards. One matching
  process **owns** a symbol's order book, so no locking is needed within it
  (the actor model: single-threaded actor per symbol, message in / event out).
- **Coroutines** — I/O fan-out inside a process (many WebSocket reads, Redis
  calls) without blocking.
- **Channels / Tables** — `Swoole\Table` (lock-free shared memory) for hot
  cross-process reads like current best bid/ask and position snapshots.

Why one-process-per-symbol matters: it turns concurrent order matching into a
**serial** problem per book, which is both correct (no race on price levels)
and fast (no mutex). Cross-symbol scaling is horizontal — add shards.

**Message contract** between stages is a small set of immutable events:
`MarketTick`, `NewOrder`, `RiskDecision`, `Fill`, `OrderStatus`, `AuditEvent`.

---

## 4. Milestone → component mapping

### Milestone 1 — Market Data Processing Engine
| Feature | Component | Notes |
|---|---|---|
| WS ingestion (Binance/Coinbase) | `MarketData\Ingest\*Client` | Swoole WS client + reconnect/backoff |
| Normalization & validation | `MarketData\Normalizer` | unify to canonical `MarketTick` |
| Order-book reconstruction | `MarketData\OrderBook` | snapshot + incremental diffs, seq gaps → resync |
| Price-feed aggregation | `MarketData\Aggregator` | consolidated best bid/ask across venues |
| Latency compensation / clock sync | `MarketData\ClockSync` | per-venue offset estimate |
| Redis Streams cache | `MarketData\StreamCache` | `XADD md.{symbol}` |
| Anomaly detection & filtering | `MarketData\AnomalyFilter` | z-score / spread sanity checks |
| Indicators (RSI, MACD), VWAP | `Analysis\Indicators` | incremental (streaming) computation |
| Volatility & correlation | `Analysis\Statistics` | rolling stddev, Pearson matrix |

### Milestone 2 — Trading Engine & Order Management
| Feature | Component | Notes |
|---|---|---|
| Order book + priority queues | `Matching\OrderBook`, `PriceLevel` | price-time priority, O(1) best, O(log n) insert |
| Matching algorithms | `Matching\Algo\Fifo`, `ProRata` | pluggable per market |
| Order lifecycle | `Order\Order`, `OrderState` | NEW→PARTIAL→FILLED/CANCELED/REJECTED |
| Atomic trade execution | `Matching\Engine::match()` | fill generation is all-or-nothing per event |
| Strategies (market-making, arbitrage) | `Strategy\*` | Strategy pattern, driven by market data |
| Position sizing | `Strategy\Sizing` | fixed-fractional / Kelly-capped |
| Order routing (smart, FOK/IOC) | `Routing\Router` | TIF handling, venue selection |
| Order slicing | `Routing\Slicer` | TWAP/iceberg for large orders |
| Execution reports | `Order\ExecutionReport` | FIX-like confirmations |

### Milestone 3 — Risk Management System
| Feature | Component | Notes |
|---|---|---|
| Position/portfolio risk | `Risk\PositionRisk`, `PortfolioRisk` | exposure, concentration |
| VaR / CVaR | `Risk\VaR` | historical + parametric |
| Correlation risk | `Risk\CorrelationRisk` | reuse M1 correlation matrix |
| Margin requirements | `Risk\Margin` | initial/maintenance |
| Pre-trade checks | `Risk\PreTradeGate` | **synchronous** gate before matching |
| Dynamic limits, stop-loss | `Risk\Limits`, `StopLoss` | per-account/per-symbol |
| Drawdown protection | `Risk\Drawdown` | kill-switch on threshold |
| Risk alerts + escalation | `Risk\AlertManager` | tiered severity |
| Regulatory reporting (MiFID II) | `Compliance\MiFIDReporter` | transaction reports |
| Surveillance & suspicious activity | `Compliance\Surveillance` | wash-trade/spoofing heuristics |
| Immutable audit trail | `Compliance\AuditLog` | hash-chained, append-only |

### Milestone 4 — Performance Optimization & Analytics
| Feature | Component | Notes |
|---|---|---|
| Memory pool allocation | `Perf\ObjectPool` | reuse Order/Fill objects, no GC churn |
| Lock-free structures | `Perf\RingBuffer`, `Swoole\Table` | SPSC ring buffer on hot path |
| CPU affinity / thread tuning | `Perf\Affinity` | pin shard procs to cores |
| Connection pooling | `Perf\ConnectionPool` | Redis/PG pools per worker |
| Real-time P&L | `Analytics\PnL` | realized + unrealized, mark-to-market |
| Performance attribution | `Analytics\Attribution` | by strategy/symbol |
| Sharpe & risk metrics | `Analytics\Metrics` | Sharpe, Sortino, max drawdown |
| Backtesting framework | `Analytics\Backtest` | replay ticks through strategies |
| Latency monitoring/alerting | `Observability\LatencyMonitor` | histogram, p50/p99 |
| Health dashboards | `Observability\Health` | HTTP endpoint + metrics |
| Benchmarking tools | `bench/` | throughput/latency harness |

---

## 5. Data model (core tables)

**Postgres (ACID — orders, fills, positions, audit):**
- `accounts(id, name, base_ccy, created_at)`
- `orders(id, account_id, symbol, side, type, tif, price, qty, filled_qty, status, created_at, updated_at)`
- `fills(id, order_id, symbol, price, qty, liquidity, ts)` — append-only
- `positions(account_id, symbol, qty, avg_price, realized_pnl)` — updated in txn with fills
- `audit_log(seq, ts, actor, event_type, payload_json, prev_hash, hash)` — hash chain

**TimescaleDB (hypertables — time-series):**
- `ticks(ts, symbol, venue, bid, ask, last, volume)`
- `book_snapshots(ts, symbol, bids_json, asks_json)`

**ClickHouse (analytics — heavy read):**
- `trades_analytics`, `pnl_timeseries`, `surveillance_events`

**Redis:**
- Streams: `md.{symbol}`, `fills`, `orders.new`
- Tables/keys: consolidated BBO, position snapshots, rate limiters

**Audit hash chain:** `hash = sha256(prev_hash || seq || ts || event_type || payload)`.
Any tampering breaks the chain — this is the "immutable log" requirement.

---

## 6. Project layout

```
Real-time-Trading-Platform/
├── composer.json
├── DESIGN.md                  # this file
├── docker-compose.yml         # postgres, redis, (kafka, clickhouse optional)
├── config/
│   └── config.php
├── src/
│   ├── Support/               # Money, Decimal, Clock, Ids
│   ├── MarketData/            # M1
│   ├── Analysis/              # M1 indicators/stats
│   ├── Matching/              # M2 engine
│   ├── Order/                 # M2 lifecycle
│   ├── Strategy/              # M2 algos
│   ├── Routing/               # M2 routing/slicing
│   ├── Risk/                  # M3
│   ├── Compliance/            # M3
│   ├── Perf/                  # M4 pools, ring buffer
│   ├── Analytics/             # M4 P&L, metrics, backtest
│   └── Observability/         # M4 latency/health
├── bin/
│   ├── ingest.php             # run market-data ingestors
│   ├── engine.php             # run matching shard
│   └── demo.php               # end-to-end local demo, no external infra
├── bench/                     # latency/throughput harness
├── tests/                     # PHPUnit
└── db/migrations/
```

**Decimal correctness:** money/prices use integer minor-units or a `Decimal`
value object — never floats — on the order/fill path. Floats are fine for
statistical indicators (RSI, correlation) where precision isn't monetary.

---

## 7. Realistic build order

The full system is a multi-week effort. I propose building a **runnable
vertical slice** first, then widening. Each phase is independently demoable.

- **Phase 0 — Foundation:** `composer.json`, autoload, `Support/` value objects
  (Decimal/Money, Clock, ID generator), config, a `bin/demo.php` entrypoint.
- **Phase 1 — Matching core (M2, highest value):** `OrderBook`, `PriceLevel`,
  FIFO matching, order lifecycle, fills. Unit-tested. Runs with **zero** external
  infra. This is the beating heart and the biggest point block (13+13+8).
- **Phase 2 — Pre-trade risk gate (M3):** limits, position checks, stop-loss,
  audit hash-chain wired into the order flow.
- **Phase 3 — Market data (M1):** canonical `MarketTick`, order-book
  reconstruction, streaming indicators (RSI/MACD/VWAP), aggregator. Ingestors
  read from a **replay file** by default; live WS clients behind the same
  interface.
- **Phase 4 — Analytics & perf (M4):** P&L, Sharpe, object pool, ring buffer,
  latency histogram, backtest replay, benchmark harness.
- **Phase 5 — Infra integrations:** Postgres persistence, Redis Streams,
  TimescaleDB/ClickHouse — behind interfaces, enabled via docker-compose.

**Rationale:** Phases 0–2 give a correct, tested, *running* trading core with
no infra dependencies — the most defensible deliverable — and every later phase
plugs into it without rework.

---

## 8. Key design decisions (call these out in review)

1. **One process per symbol** for matching → correctness without locks, the
   cleanest way to hit both throughput and sub-ms latency in PHP.
2. **Interfaces over infra.** Kafka/ClickHouse/TimescaleDB sit behind
   ports (`EventPublisher`, `AnalyticsSink`, `TickStore`) with an in-memory /
   Redis default, so the core runs and tests without them installed.
3. **Hot path is allocation-light and DB-free.** Object pools + ring buffer;
   persistence is a downstream consumer of the fill stream.
4. **Audit as a hash chain**, not just a table — satisfies "immutable logs"
   in a verifiable way.
5. **Decimal money, never floats**, on the order/fill path.

---

## 9. Environment (verified on this machine)

- **PHP 8.3.31** ✅ (exceeds 8.2+), **Composer 2.10.1** ✅, **Docker 29.2.1** ✅
- **bcmath / PDO / pdo_pgsql** ✅ — bcmath backs the `Decimal` money type
- **Swoole** ❌ not installed (Unix-only; needs WSL/Docker on Windows)
- **redis extension** ❌ not installed

**Consequence — confirmed direction:** the trading core (matching, risk,
indicators, analytics, audit) is built as **plain PHP 8.3 CLI** so it runs and
tests on this machine today. Swoole is a *deployment* concern, not a
*correctness* one: process-wiring, Redis Streams, and DB persistence sit behind
interfaces (`EventPublisher`, `TickStore`, `AnalyticsSink`) and are enabled in
Phase 5 via Docker/WSL. Nothing in Phases 0–4 blocks on Swoole being present.
```
