# Project Guide — Real-time Trading Platform

A complete, file-by-file walkthrough of the codebase: what every component does,
how data flows through the system, and how to run it. Pair this with
[DESIGN.md](../DESIGN.md) (architecture rationale) and [README.md](../README.md)
(quick start).

---

## 1. The big picture

The platform simulates a high-frequency crypto exchange desk end to end. One
discrete **step** of the simulation flows like this:

```
 market data (synthetic feed)                     ── M1
      │  evolve fair value, post liquidity
      ▼
 technical indicators (RSI / MACD / VWAP)          ── M1
      │
      ▼
 strategies (market-maker, momentum)               ── M2
      │  emit OrderIntents
      ▼
 pre-trade RISK GATE  ──rejected──► audit + alert  ── M3
      │ approved
      ▼
 MATCHING ENGINE (price-time, FIFO/Pro-rata)       ── M2
      │  produces Trades + Fills
      ▼
 post-trade: positions · P&L · portfolio risk      ── M3 / M4
      │  VaR/CVaR, drawdown kill-switch
      ▼
 compliance: audit hash-chain · MiFID · surveil.   ── M3
      │
      ▼
 analytics + latency monitoring                    ── M4
      │
      ▼
 dashboard snapshot (JSON)  →  browser
```

**Key property — determinism.** The whole run is a pure function of
`(seed, steps, manualOrders)`. Two runs from the same seed are byte-identical.
That's why the web server can persist just those three values and rebuild the
exact same state on every request — no shared memory, works the same on `php -S`
and Swoole.

---

## 2. Directory map

```
src/
  Support/       value objects: Decimal money, Clock, IDs, RNG
  Order/         Order, Fill, enums, execution reports
  Matching/      order book + matching engine (the heart)
  MarketData/    ticks, normalization, aggregation, anomaly filter
  Analysis/      streaming indicators + statistics
  Strategy/      strategy interface + market-maker + momentum
  Routing/       smart router + order slicer
  Risk/          positions, portfolio, VaR, limits, stop-loss, risk engine
  Compliance/    audit hash-chain, MiFID reporter, surveillance
  Analytics/     P&L, metrics (Sharpe), attribution, backtest
  Perf/          object pool, ring buffer, latency monitor
  Engine/        TradingPlatform — wires everything + simulation
  Web/           HTTP API over the platform
  Persistence/   Postgres store + Redis publisher
bin/             demo.php, server.php (Swoole), persist.php
public/          index.php (router) + dashboard.html
config/          config.php
db/migrations/   Postgres schema
tests/           run.php (36 checks)
```

---

## 3. Support — the foundation (`src/Support/`)

Everything else builds on these.

- **`Decimal.php`** — fixed-precision decimal backed by `bcmath`. Used for all
  money and prices so there is **never** float drift on the order/fill path
  (`0.1 + 0.2` is exactly `0.3`). Supports add/sub/mul/div, comparisons, min/max.
- **`Clock.php`** — `Clock` interface plus `VirtualClock` (deterministic, advanced
  by the simulation) and `SystemClock` (wall clock). The virtual clock is what
  makes timestamps reproducible.
- **`Ids.php`** — three things: `Ids` (monotonic order/trade id generator; the
  increasing sequence also breaks price-time ties), and `DeterministicRandom`
  (a 63-bit **xorshift** PRNG — chosen over an LCG because PHP integer overflow
  silently corrupts to float, which xorshift avoids). Includes a Gaussian sampler
  for the price random walk.

---

## 4. Order domain (`src/Order/`)

- **`Enums.php`** — `Side` (BUY/SELL), `OrderType` (LIMIT/MARKET),
  `TimeInForce` (GTC/IOC/FOK), `OrderStatus` (NEW→PARTIALLY_FILLED→FILLED/…),
  `Liquidity` (MAKER/TAKER).
- **`Order.php`** — a live order and its lifecycle. Tracks filled vs remaining
  quantity exactly, rolls up VWAP of fills, and transitions status on each fill.
- **`Fill.php`** — an immutable execution record (one leg of a trade against one
  order): price, qty, maker/taker, timestamp.
- **`ExecutionReport.php`** — a FIX-style confirmation emitted on order state
  changes (the client-facing acknowledgement).

---

## 5. Matching engine (`src/Matching/`) — the heart (M2)

- **`PriceLevel.php`** — all resting orders at one price, held in strict **time
  priority (FIFO)**. Tracks total quantity for O(1) depth reads.
- **`OrderBook.php`** — one symbol's book: bids (sorted high→low) and asks
  (low→high). Best bid/ask is O(1); price keys are re-sorted lazily only when
  levels change. Exposes `depth()` for the dashboard ladder.
- **`MatchingAlgorithm.php`** — enum: `Fifo` (price-time) or `ProRata` (size-weighted).
- **`Trade.php`** — a matched execution between an aggressor (taker) and a resting
  order (maker).
- **`MatchingEngine.php`** — the core algorithm:
  - **Price-time priority**: best price matches first, ties by arrival order.
  - **Execution at the maker price** (taker gets price improvement — standard).
  - **Time-in-force**: GTC rests the remainder, IOC cancels it, **FOK** checks
    full fillability *before* mutating anything (true atomicity — no partial leak).
  - **Self-trade prevention**: an account never matches against itself.
  - Both FIFO and pro-rata allocation, `submit()` and `cancel()`.

---

## 6. Market data (`src/MarketData/`) — M1

- **`MarketTick.php`** — the canonical, venue-neutral tick (bid/ask/last/volume +
  exchange and local timestamps → latency estimate).
- **`Normalizer.php`** — converts raw **Binance** and **Coinbase** JSON payloads
  into that canonical tick (the one place that knows each venue's quirks).
- **`FeedClient.php`** — interface for a market-data source. Production = Swoole
  WebSocket client; demo = deterministic simulated feed. Swap is one line.
- **`Aggregator.php`** — consolidated best bid/offer (CBBO) across venues, plus
  cross-venue **arbitrage** detection (bid on A above ask on B).
- **`AnomalyFilter.php`** — rejects bad ticks: structural checks (crossed quotes,
  absurd spread) + a **z-score** outlier test on returns (fat-finger / bad feed).

---

## 7. Analysis (`src/Analysis/`) — M1

- **`Indicators.php`** — **streaming** RSI (Wilder), MACD (+ signal + histogram),
  fast/slow EMA, and VWAP. Each update is O(1) — no window recompute — so it scales
  to high tick rates.
- **`Statistics.php`** — realized volatility (stddev of returns), Pearson
  **correlation**, and a correlation matrix. Reused by both M1 analytics and M3
  portfolio risk.

---

## 8. Strategies (`src/Strategy/`) — M2

- **`Strategy.php`** — the `Strategy` interface, `OrderIntent` (a desired order
  before risk/routing), and `StrategyContext` (read-only market view). The Strategy
  pattern keeps algo logic decoupled from execution.
- **`MarketMakerStrategy.php`** — quotes a two-sided market around the mid and
  captures the spread. **Inventory-aware**: skews quotes against its position so it
  mean-reverts inventory toward flat (the core market-making risk control).
- **`MomentumStrategy.php`** — combines MACD histogram (trend) with RSI (filter) to
  take small directional positions via marketable IOC orders; flattens when signals
  fade.

---

## 9. Routing (`src/Routing/`) — M2

- **`SmartRouter.php`** — given quotes from several venues, routes a buy to the
  lowest ask / a sell to the highest bid.
- **`Slicer.php`** — splits a large parent order to reduce market impact: **TWAP**
  (equal slices) and **iceberg** (fixed visible clip).

---

## 10. Risk (`src/Risk/`) — M3

- **`Position.php`** — a running position with **average-cost accounting**: buys
  blend the average price, sells realize P&L against it; handles reduce/close/flip.
- **`Portfolio.php`** — account book of positions + cash; computes equity, gross
  exposure, realized/unrealized P&L against marks.
- **`RiskMetrics.php`** — **VaR** (historical + parametric) and **CVaR / Expected
  Shortfall**, with an inverse-normal (`zScore`) helper.
- **`RiskLimits.php`** — per-account limits (max order/symbol/gross exposure,
  concentration, drawdown, margin rates).
- **`RiskDecision.php`** — approve/reject result carrying the list of breached limits.
- **`AlertManager.php`** — tiered alerts (INFO/WARNING/CRITICAL) with escalation
  counters.
- **`StopLoss.php`** — stop-loss / take-profit with **trailing** stops that ratchet
  in the favourable direction.
- **`RiskEngine.php`** — the risk brain:
  - **`preTradeCheck()`** — synchronous gate on the hot path; rejects orders that
    would breach any limit *before* they reach the book.
  - **`assess()`** — continuous portfolio risk (exposure, concentration, VaR/CVaR,
    margin) + the **drawdown kill-switch** that halts trading on breach.

---

## 11. Compliance (`src/Compliance/`) — M3

- **`AuditLog.php`** — append-only, **hash-chained** audit trail. Each entry's hash
  includes the previous hash, so altering any past entry breaks the chain;
  `verify()` detects tampering and locates it. This is the "immutable log".
- **`MiFIDReporter.php`** — builds MiFID II-style transaction reports (RTS 22
  fields) and writes each into the audit chain.
- **`Surveillance.php`** — real-time market-abuse heuristics: **wash trades**
  (self-match), **spoofing** (high cancel ratio), flagged for review.

---

## 12. Analytics (`src/Analytics/`) — M4

- **`PnL.php`** — records the equity curve and per-step P&L; derives returns and a
  summary (equity, total P&L, return %, Sharpe, Sortino, max drawdown).
- **`Metrics.php`** — **Sharpe**, **Sortino** (downside deviation), and **max
  drawdown** of an equity curve.
- **`Attribution.php`** — performance attribution: realized P&L / volume / fills
  bucketed **by strategy** and by symbol.
- **`Backtest.php`** — minimal event-driven backtester: replay a price series
  through a strategy callback, mark-to-market, report risk-adjusted metrics. Same
  strategy signature runs live.

---

## 13. Performance (`src/Perf/`) — M4

- **`ObjectPool.php`** — fixed-capacity pool that reuses hot-path objects instead
  of allocating/GC-ing millions of them (GC pauses show up as tail latency).
- **`MarketEvent.php`** — a mutable, poolable event envelope pushed through the bus.
- **`RingBuffer.php`** — fixed-size **single-producer/single-consumer** ring buffer:
  a bounded, allocation-free, lock-free queue (head/tail never race with one writer
  each). In production the backing store becomes a `Swoole\Table` in shared memory.
- **`LatencyMonitor.php`** — latency histogram with p50/p95/p99 percentiles and a
  p99 budget alarm; `time()` wraps and measures a callable.

---

## 14. The orchestrator (`src/Engine/TradingPlatform.php`)

This is where every milestone is wired together and the simulation is driven.
Per `step()` it: evolves the synthetic fair value (GBM) and a correlated ETH
series → rebuilds a fresh book → posts external liquidity → runs the market-maker
through the OMS → generates external order flow → runs momentum → applies manual
orders → updates indicators, marks, P&L, portfolio risk, stop-losses → snapshots
depth/BBO. `submitHouse()` is the OMS entrypoint (risk gate → matched → post-trade);
`processResult()` handles fills, attribution, surveillance, MiFID and the event bus.
`snapshot()` assembles the full dashboard JSON. A `record` mode retains every order
and fill for persistence.

---

## 15. Web layer (`src/Web/` + `public/`)

- **`Web/Api.php`** — stateless JSON API. `GET /api/state?steps=N` rebuilds the
  deterministic run to step N and returns the snapshot; `POST /api/order` schedules
  a manual order; `POST /api/reset` reseeds; `GET /api/config`. Persists only
  `(seed, steps, manualOrders)` to `storage/session.json`.
- **`public/index.php`** — front controller for `php -S`: serves the dashboard at
  `/` and dispatches `/api/*` to the API.
- **`public/dashboard.html`** — the live trading terminal: price/VWAP + equity
  canvas charts, depth ladder, trade tape, P&L, full risk panel, indicators,
  correlation/volatility, strategy attribution, compliance badges, latency, a
  manual order ticket, and a FIFO↔Pro-rata toggle. Vanilla JS polling, no external
  libraries.

---

## 16. Persistence (`src/Persistence/`)

- **`PostgresStore.php`** — PDO/Postgres store. `persistRun()` writes accounts,
  orders, fills, positions and the audit chain in **one transaction** (ACID). Also
  `migrate()` and `counts()`.
- **`RedisPublisher.php`** — publishes trades to a **Redis Stream** (`XADD`) and
  caches the BBO — the real-time caching / pub-sub side.

---

## 17. Entry points (`bin/`)

- **`demo.php`** — end-to-end CLI run printing a report across all four milestones
  (proves the core with zero infrastructure).
- **`server.php`** — production-style **Swoole** HTTP server (coroutines,
  worker-per-core) serving the same dashboard + API. Runs in the Docker container.
- **`persist.php`** — the **data push**: runs the platform in record mode and writes
  the whole run to Postgres under a named account (default *Mehedi Hasan*), then
  publishes trades to Redis.

---

## 18. Infrastructure & tests

- **`config/config.php`** — env-driven configuration (seed, cash, algorithm, DB/Redis
  endpoints, HTTP port).
- **`db/migrations/001_schema.sql`** — Postgres schema (accounts, orders, fills,
  positions, hash-chained audit_log, Timescale ticks hypertable).
- **`Dockerfile`** — PHP 8.3 image with **Swoole + pdo_pgsql + redis + bcmath**.
- **`docker-compose.yml`** — full stack: app (Swoole) + TimescaleDB + Redis.
- **`tests/run.php`** — 36 zero-dependency assertions covering Decimal math,
  matching/TIF semantics, position accounting, VaR, indicators, the audit tamper
  check, and determinism.

---

## 19. How to run

```bash
# Local, zero infra
composer install && composer test      # 36 checks
composer demo                          # CLI report
composer serve                         # dashboard → http://127.0.0.1:8080

# Full stack in Docker (Swoole + TimescaleDB + Redis)
docker compose up -d --build
docker compose run --rm app php bin/persist.php 600     # push data to Postgres
docker compose exec postgres psql -U trading -d trading -c "SELECT * FROM positions;"
```

---

## 20. Milestone → code index

| Milestone | Primary files |
|---|---|
| **M1** Market data & analysis | `MarketData/*`, `Analysis/*` |
| **M2** Trading engine & OMS | `Matching/*`, `Order/*`, `Strategy/*`, `Routing/*` |
| **M3** Risk & compliance | `Risk/*`, `Compliance/*` |
| **M4** Analytics & performance | `Analytics/*`, `Perf/*`, dashboard, `bin/*` |

A per-requirement status matrix (with points) is in
[docs/requirements.html](requirements.html).
